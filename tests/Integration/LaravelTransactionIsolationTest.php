<?php

declare(strict_types=1);

namespace Ralder\QueryScaling\Tests\Integration;

use Illuminate\Support\Facades\DB;
use LogicException;
use Ralder\QueryScaling\Core\IsolationViolationException;
use Ralder\QueryScaling\Laravel\LaravelTransactionIsolation;
use RuntimeException;

final class LaravelTransactionIsolationTest extends TestCase
{
    public function test_rolls_back_rows_written_inside_the_scope(): void
    {
        $this->createWidgetsTable();
        $isolation = $this->isolation();

        $isolation->begin();
        DB::insert('INSERT INTO widgets (name) VALUES (?)', ['scoped']);
        $isolation->restore();

        $this->assertSame([], $this->widgets());
        $this->assertSame(0, DB::transactionLevel());
    }

    public function test_works_inside_an_already_open_test_transaction(): void
    {
        $this->createWidgetsTable();
        DB::insert('INSERT INTO widgets (name) VALUES (?)', ['pre-existing']);

        DB::beginTransaction();
        $this->assertSame(1, DB::transactionLevel());

        $isolation = $this->isolation();
        $isolation->begin();
        $this->assertSame(2, DB::transactionLevel());
        DB::insert('INSERT INTO widgets (name) VALUES (?)', ['scoped']);
        $isolation->restore();

        $this->assertSame(1, DB::transactionLevel());
        $this->assertCount(1, $this->widgets(), 'row written before the scope survives; row written inside does not');

        // restore the test environment like DatabaseTransactions would
        DB::rollBack();
    }

    public function test_recovers_when_callback_leaves_nested_transactions_open(): void
    {
        $this->createWidgetsTable();
        $isolation = $this->isolation();

        $isolation->begin();
        DB::beginTransaction(); // callback opens a nested transaction...
        DB::insert('INSERT INTO widgets (name) VALUES (?)', ['nested']);
        // ...and forgets to close it
        $isolation->restore();

        $this->assertSame(0, DB::transactionLevel());
        $this->assertSame([], $this->widgets());
    }

    public function test_throws_when_callback_commits_beyond_its_own_scope(): void
    {
        $this->createWidgetsTable();

        DB::beginTransaction(); // surrounding test transaction (depth 1)
        $isolation = $this->isolation();
        $isolation->begin(); // depth 2

        DB::commit(); // callback commits the scope's transaction (depth back to 1)

        try {
            $isolation->restore();
            $this->fail('expected IsolationViolationException');
        } catch (IsolationViolationException $e) {
            $this->assertStringContainsString('testing', $e->getMessage());
            $this->assertStringContainsString('depth 1', $e->getMessage());
        } finally {
            // manual cleanup for this deliberately broken scenario
            if (DB::transactionLevel() > 0) {
                DB::rollBack();
            }
        }
    }

    public function test_throws_when_callback_rolls_back_everything(): void
    {
        $this->createWidgetsTable();
        $isolation = $this->isolation();

        $isolation->begin();
        DB::rollBack(); // rolls back the isolation scope itself

        $this->expectException(IsolationViolationException::class);
        $isolation->restore();
    }

    public function test_commit_then_reopen_at_the_top_level_is_reported_as_isolation_violation(): void
    {
        $this->createWidgetsTable();
        $isolation = $this->isolation();
        $isolation->begin(); // depth 1, no surrounding transaction

        DB::insert('INSERT INTO widgets (name) VALUES (?)', ['escaped']);
        DB::commit(); // closes the isolation scope for real (depth 0)
        DB::beginTransaction(); // ...and re-opens one at exactly the measured depth

        try {
            $isolation->restore();
            $this->fail('Expected an isolation violation for the balanced commit-and-re-open.');
        } catch (IsolationViolationException $e) {
            $this->assertStringContainsString('testing', $e->getMessage());
            $this->assertStringContainsString('committed or rolled back the isolation transaction', $e->getMessage());
        }

        $this->assertSame(0, DB::transactionLevel(), 'restore() still returns the connection to the observed depth');
        $this->assertCount(1, $this->widgets(), 'the row committed before the re-open escaped the scope');
    }

    public function test_commit_then_reopen_inside_a_test_transaction_is_reported_as_isolation_violation(): void
    {
        $this->createWidgetsTable();

        DB::beginTransaction(); // surrounding test transaction (depth 1)
        $isolation = $this->isolation();
        $isolation->begin(); // depth 2

        DB::commit(); // releases the scope's savepoint (depth 1)
        DB::beginTransaction(); // ...and re-opens at the measured depth again

        try {
            $isolation->restore();
            $this->fail('Expected an isolation violation for the balanced commit-and-re-open.');
        } catch (IsolationViolationException $e) {
            $this->assertStringContainsString('testing', $e->getMessage());
            $this->assertStringContainsString('depth 1', $e->getMessage());
        } finally {
            while (DB::transactionLevel() > 0) {
                DB::rollBack();
            }
        }

        $this->assertSame(0, DB::transactionLevel());
        $this->assertSame([], $this->widgets());
    }

    public function test_physical_transaction_loss_is_reported_as_isolation_violation(): void
    {
        $isolation = $this->isolation();
        $isolation->begin();

        // Bypass Laravel's transaction bookkeeping while keeping its depth at 1.
        $this->db()->connection()->getPdo()->commit();

        try {
            $isolation->restore();
            $this->fail('Expected a physical transaction violation.');
        } catch (IsolationViolationException $e) {
            $this->assertStringContainsString('vanished', $e->getMessage());
        }
    }

    public function test_disconnected_connection_is_reported_as_isolation_violation(): void
    {
        $isolation = $this->isolation();
        $isolation->begin();
        $this->db()->connection()->disconnect();

        try {
            $isolation->restore();
            $this->fail('Expected a disconnected connection violation.');
        } catch (IsolationViolationException $e) {
            $this->assertStringContainsString('broke transaction isolation', $e->getMessage());
            $this->assertStringContainsString('testing', $e->getMessage());
        }
    }

    public function test_exception_while_scoped_still_restores_cleanly(): void
    {
        $this->createWidgetsTable();
        $isolation = $this->isolation();

        try {
            $isolation->begin();
            DB::insert('INSERT INTO widgets (name) VALUES (?)', ['scoped']);
            throw new RuntimeException('callback exploded');
        } catch (RuntimeException) {
            $isolation->restore();
        }

        $this->assertSame([], $this->widgets());
        $this->assertSame(0, DB::transactionLevel());
    }

    public function test_begin_twice_without_restore_throws(): void
    {
        $this->createWidgetsTable();
        $isolation = $this->isolation();
        $isolation->begin();

        try {
            $this->expectException(LogicException::class);
            $isolation->begin();
        } finally {
            $isolation->restore();
        }
    }

    public function test_restore_without_begin_throws(): void
    {
        $this->expectException(LogicException::class);

        $this->isolation()->restore();
    }

    public function test_multiple_connections_are_isolated_and_restored_together(): void
    {
        $this->createWidgetsTable();
        $this->createWidgetsTable('secondary');

        $isolation = LaravelTransactionIsolation::forConnections($this->db(), ['testing', 'secondary']);

        $isolation->begin();
        DB::connection('testing')->insert('INSERT INTO widgets (name) VALUES (?)', ['a']);
        DB::connection('secondary')->insert('INSERT INTO widgets (name) VALUES (?)', ['b']);
        $isolation->restore();

        $this->assertSame([], $this->widgets());
        $this->assertSame([], $this->widgets('secondary'));
        $this->assertSame(0, DB::connection('secondary')->transactionLevel());
    }

    private function isolation(): LaravelTransactionIsolation
    {
        /** @var LaravelTransactionIsolation $isolation */
        $isolation = LaravelTransactionIsolation::forConnections($this->db(), ['testing']);

        return $isolation;
    }
}
