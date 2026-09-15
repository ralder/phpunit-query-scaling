<?php

declare(strict_types=1);

namespace Ralder\QueryScaling\Tests\Unit;

use Closure;
use PHPUnit\Framework\TestCase;
use Ralder\QueryScaling\Core\IsolationStrategy;
use Ralder\QueryScaling\Core\QueryCollectorContract;
use Ralder\QueryScaling\Core\RecordedQuery;
use Ralder\QueryScaling\Core\ScaleFactors;
use Ralder\QueryScaling\Core\ScaleRunner;
use RuntimeException;

final class ScaleRunnerTest extends TestCase
{
    public function test_runs_each_scale_in_isolation_and_measures_only_the_run_callback(): void
    {
        $events = [];
        $isolation = new FakeIsolation($events);
        $collector = new FakeCollector($events);

        $runner = new ScaleRunner($collector, $isolation);
        $series = $runner->run(
            ScaleFactors::make([2, 5]),
            populate: function (int $scale) use (&$events): void {
                $events[] = "populate:$scale";
            },
            warmup: function (int $scale) use (&$events): void {
                $events[] = "warmup:$scale";
            },
            run: function () use (&$events): void {
                $events[] = 'run';
            },
        );

        $this->assertSame([
            'begin', 'populate:2', 'warmup:2', 'measure:start', 'run', 'measure:end', 'restore',
            'begin', 'populate:5', 'warmup:5', 'measure:start', 'run', 'measure:end', 'restore',
        ], $events);

        $this->assertSame([2, 5], $series->scales());
    }

    public function test_warmup_is_optional(): void
    {
        $events = [];
        $runner = new ScaleRunner(new FakeCollector($events), new FakeIsolation($events));

        $runner->run(
            ScaleFactors::make([2, 5]),
            populate: fn (int $scale) => null,
            warmup: null,
            run: function () use (&$events): void {
                $events[] = 'run';
            },
        );

        $this->assertSame(['begin', 'measure:start', 'run', 'measure:end', 'restore', 'begin', 'measure:start', 'run', 'measure:end', 'restore'], $events);
    }

    public function test_collected_queries_become_scale_measurements(): void
    {
        $events = [];
        $collector = new FakeCollector($events, queriesPerMeasure: [
            [new RecordedQuery('testing', 'select 1', [])],
            [new RecordedQuery('testing', 'select 1', []), new RecordedQuery('testing', 'select 2', [])],
        ]);

        $series = (new ScaleRunner($collector, new FakeIsolation($events)))->run(
            ScaleFactors::make([2, 5]),
            populate: fn (int $scale) => null,
            warmup: null,
            run: fn () => null,
        );

        $this->assertSame([1, 2], $series->counts());
    }

    public function test_normalizes_literals_when_requested(): void
    {
        $events = [];
        $collector = new FakeCollector($events, queriesPerMeasure: [
            [new RecordedQuery('testing', 'select * from users where id = 1', [])],
            [new RecordedQuery('testing', 'select * from users where id = 2', [])],
        ]);

        $series = (new ScaleRunner($collector, new FakeIsolation($events)))->run(
            ScaleFactors::make([2, 5]),
            populate: fn (int $scale) => null,
            warmup: null,
            run: fn () => null,
            normalizeLiterals: true,
        );

        $grouped = $series->fingerprintCountsByScale();
        $this->assertCount(1, $grouped);
        $counts = array_values($grouped)[0]['counts'];
        $this->assertSame([2 => 1, 5 => 1], $counts);
    }

    public function test_isolation_is_restored_when_populate_throws(): void
    {
        $events = [];
        $isolation = new FakeIsolation($events);
        $runner = new ScaleRunner(new FakeCollector($events), $isolation);

        $boom = new RuntimeException('populate exploded');

        try {
            $runner->run(
                ScaleFactors::make([2, 5]),
                populate: function (int $scale) use ($boom): void {
                    throw $boom;
                },
                warmup: null,
                run: fn () => null,
            );
            $this->fail('expected exception');
        } catch (RuntimeException $e) {
            $this->assertSame($boom, $e);
        }

        $this->assertSame(['begin', 'restore'], $events);
        $this->assertSame($isolation->beginCount, $isolation->restoreCount);
    }

    public function test_isolation_is_restored_and_collector_deactivated_when_run_throws(): void
    {
        $events = [];
        $isolation = new FakeIsolation($events);
        $collector = new FakeCollector($events, throwOnMeasure: $boom = new RuntimeException('run exploded'));

        $runner = new ScaleRunner($collector, $isolation);

        try {
            $runner->run(
                ScaleFactors::make([2, 5]),
                populate: fn (int $scale) => null,
                warmup: null,
                run: fn () => null,
            );
            $this->fail('expected exception');
        } catch (RuntimeException $e) {
            $this->assertSame($boom, $e);
        }

        $this->assertSame(['begin', 'measure:start', 'measure:end', 'restore'], $events);
        $this->assertSame($isolation->beginCount, $isolation->restoreCount);
        $this->assertFalse($collector->active, 'collector session must be deactivated after failure');
    }

    public function test_every_scale_gets_fresh_isolation_even_after_success(): void
    {
        $events = [];
        $isolation = new FakeIsolation($events);

        (new ScaleRunner(new FakeCollector($events), $isolation))->run(
            ScaleFactors::make([2, 5, 10]),
            populate: fn (int $scale) => null,
            warmup: null,
            run: fn () => null,
        );

        $this->assertSame(3, $isolation->beginCount);
        $this->assertSame(3, $isolation->restoreCount);
    }
}

final class FakeIsolation implements IsolationStrategy
{
    public int $beginCount = 0;

    public int $restoreCount = 0;

    /** @param  list<string>  $events */
    public function __construct(private array &$events) {}

    public function begin(): void
    {
        $this->beginCount++;
        $events = &$this->events;
        $events[] = 'begin';
    }

    public function restore(): void
    {
        $this->restoreCount++;
        $events = &$this->events;
        $events[] = 'restore';
    }
}

final class FakeCollector implements QueryCollectorContract
{
    public bool $active = false;

    private int $measureCalls = 0;

    /**
     * @param  list<string>  $events
     * @param  list<list<RecordedQuery>>  $queriesPerMeasure
     */
    public function __construct(
        private array &$events,
        private array $queriesPerMeasure = [],
        private ?RuntimeException $throwOnMeasure = null,
    ) {}

    public function measure(Closure $callback): array
    {
        $events = &$this->events;
        $events[] = 'measure:start';
        $this->active = true;

        try {
            if ($this->throwOnMeasure !== null) {
                throw $this->throwOnMeasure;
            }

            $callback();
        } finally {
            $this->active = false;
            $events = &$this->events;
            $events[] = 'measure:end';
        }

        $queries = $this->queriesPerMeasure[$this->measureCalls] ?? [];
        $this->measureCalls++;

        return $queries;
    }
}
