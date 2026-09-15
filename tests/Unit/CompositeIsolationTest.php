<?php

declare(strict_types=1);

namespace Ralder\QueryScaling\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Ralder\QueryScaling\Core\CompositeIsolation;
use Ralder\QueryScaling\Core\IsolationStrategy;
use RuntimeException;

final class CompositeIsolationTest extends TestCase
{
    public function test_partial_begin_restores_already_begun_strategies_and_rethrows(): void
    {
        $events = [];
        $first = new RecordingIsolation('first', $events);
        $second = new RecordingIsolation('second', $events, beginFailure: $failure = new RuntimeException('begin failed'));
        $composite = new CompositeIsolation([$first, $second]);

        try {
            $composite->begin();
            $this->fail('Expected the second strategy to fail during begin.');
        } catch (RuntimeException $exception) {
            $this->assertSame($failure, $exception);
        }

        $this->assertSame(['begin:first', 'begin:second', 'restore:first'], $events);
    }

    public function test_restore_continues_after_failure_and_rethrows_first_failure(): void
    {
        $events = [];
        $first = new RecordingIsolation('first', $events, restoreFailure: $failure = new RuntimeException('restore failed'));
        $second = new RecordingIsolation('second', $events);
        $composite = new CompositeIsolation([$first, $second]);

        try {
            $composite->restore();
            $this->fail('Expected the first strategy restore failure.');
        } catch (RuntimeException $exception) {
            $this->assertSame($failure, $exception);
        }

        $this->assertSame(['restore:second', 'restore:first'], $events);
    }
}

final class RecordingIsolation implements IsolationStrategy
{
    /**
     * @param  list<string>  $events
     */
    public function __construct(
        private readonly string $name,
        private array &$events,
        private readonly ?RuntimeException $beginFailure = null,
        private readonly ?RuntimeException $restoreFailure = null,
    ) {}

    public function begin(): void
    {
        $events = &$this->events;
        $events[] = 'begin:'.$this->name;
        if ($this->beginFailure !== null) {
            throw $this->beginFailure;
        }
    }

    public function restore(): void
    {
        $events = &$this->events;
        $events[] = 'restore:'.$this->name;
        if ($this->restoreFailure !== null) {
            throw $this->restoreFailure;
        }
    }
}
