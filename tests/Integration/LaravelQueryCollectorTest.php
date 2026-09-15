<?php

declare(strict_types=1);

namespace Ralder\QueryScaling\Tests\Integration;

use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Events\Dispatcher;
use Illuminate\Support\Facades\DB;
use LogicException;
use Ralder\QueryScaling\Laravel\LaravelQueryCollector;

final class LaravelQueryCollectorTest extends TestCase
{
    public function test_records_only_queries_issued_inside_measure(): void
    {
        $this->createWidgetsTable();
        DB::insert('INSERT INTO widgets (name) VALUES (?)', ['outside']);

        $collector = new LaravelQueryCollector($this->dispatcher(), ['testing']);

        $queries = $collector->measure(function (): void {
            DB::insert('INSERT INTO widgets (name) VALUES (?)', ['inside']);
            DB::select('SELECT * FROM widgets');
        });

        DB::select('SELECT * FROM widgets');

        $this->assertCount(2, $queries);
        $this->assertStringStartsWith('INSERT INTO WIDGETS', strtoupper($queries[0]->sql));
        $this->assertSame('testing', $queries[0]->connection);
    }

    public function test_filters_queries_to_configured_connections(): void
    {
        $this->createWidgetsTable();
        $this->createWidgetsTable('secondary');

        $collector = new LaravelQueryCollector($this->dispatcher(), ['secondary']);

        $queries = $collector->measure(function (): void {
            DB::connection('testing')->insert('INSERT INTO widgets (name) VALUES (?)', ['ignored']);
            DB::connection('secondary')->insert('INSERT INTO widgets (name) VALUES (?)', ['kept']);
        });

        $this->assertCount(1, $queries);
        $this->assertSame('secondary', $queries[0]->connection);
    }

    public function test_rejects_overlapping_sessions(): void
    {
        $collector = new LaravelQueryCollector($this->dispatcher(), ['testing']);

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('already active');

        $collector->measure(function () use ($collector): void {
            $collector->measure(static fn () => null);
        });
    }

    public function test_session_is_closed_after_overlap_error(): void
    {
        $collector = new LaravelQueryCollector($this->dispatcher(), ['testing']);

        try {
            $collector->measure(function () use ($collector): void {
                $collector->measure(static fn () => null);
            });
        } catch (LogicException) {
        }

        // outer session kept working and closed normally; next measure is unaffected
        $this->createWidgetsTable();
        $queries = $collector->measure(function (): void {
            DB::insert('INSERT INTO widgets (name) VALUES (?)', ['x']);
        });

        $this->assertCount(1, $queries);
    }

    public function test_listener_is_registered_once_per_dispatcher(): void
    {
        $before = LaravelQueryCollector::listenerRegistrationCount();

        new LaravelQueryCollector($this->dispatcher(), ['testing']);
        new LaravelQueryCollector($this->dispatcher(), ['testing']);
        new LaravelQueryCollector($this->dispatcher(), ['secondary']);

        $this->assertSame($before + 1, LaravelQueryCollector::listenerRegistrationCount());
    }

    public function test_registration_is_released_when_the_dispatcher_is_collected(): void
    {
        $dispatcher = new Dispatcher;
        new LaravelQueryCollector($dispatcher, ['testing']);

        $withDispatcher = LaravelQueryCollector::listenerRegistrationCount();

        unset($dispatcher);
        gc_collect_cycles();

        $this->assertLessThan(
            $withDispatcher,
            LaravelQueryCollector::listenerRegistrationCount(),
            'a collected dispatcher must release its registration so its object id can be reused safely'
        );
    }

    public function test_a_rebuilt_dispatcher_records_even_when_object_ids_are_recycled(): void
    {
        $first = new Dispatcher;
        $firstId = spl_object_id($first);
        new LaravelQueryCollector($first, ['testing']);

        unset($first);
        gc_collect_cycles();

        $second = new Dispatcher;
        $recycled = spl_object_id($second) === $firstId;

        $collector = new LaravelQueryCollector($second, ['testing']);
        $connection = $this->db()->connection();

        $queries = $collector->measure(static function () use ($second, $connection): void {
            $second->dispatch(new QueryExecuted('select 1', [], 1.0, $connection));
        });

        $this->assertCount(
            1,
            $queries,
            $recycled
                ? 'a recycled object id must not suppress the listener of the rebuilt dispatcher'
                : 'a fresh dispatcher always gets its own listener'
        );
        $this->assertSame('select 1', $queries[0]->sql);
    }

    public function test_inert_listener_does_not_record_without_active_session(): void
    {
        new LaravelQueryCollector($this->dispatcher(), ['testing']);

        $this->createWidgetsTable();
        DB::insert('INSERT INTO widgets (name) VALUES (?)', ['unrecorded']);

        $collector = new LaravelQueryCollector($this->dispatcher(), ['testing']);
        $queries = $collector->measure(static fn () => null);

        $this->assertSame([], $queries);
    }

    public function test_repeated_measures_do_not_mix_results(): void
    {
        $this->createWidgetsTable();
        $collector = new LaravelQueryCollector($this->dispatcher(), ['testing']);

        $first = $collector->measure(static function (): void {
            DB::insert('INSERT INTO widgets (name) VALUES (?)', ['a']);
        });
        $second = $collector->measure(static function (): void {
            DB::insert('INSERT INTO widgets (name) VALUES (?)', ['b']);
            DB::insert('INSERT INTO widgets (name) VALUES (?)', ['c']);
        });

        $this->assertCount(1, $first);
        $this->assertCount(2, $second);
    }

    public function test_session_is_deactivated_when_callback_throws(): void
    {
        $this->createWidgetsTable();
        $collector = new LaravelQueryCollector($this->dispatcher(), ['testing']);

        try {
            $collector->measure(static function (): void {
                throw new \RuntimeException('boom');
            });
        } catch (\RuntimeException) {
        }

        $queries = $collector->measure(static function (): void {
            DB::select('SELECT 1');
        });

        $this->assertCount(1, $queries);
    }
}
