<?php

declare(strict_types=1);

namespace Ralder\QueryScaling\Laravel;

use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\Connection;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\Events\TransactionCommitted;
use Illuminate\Database\Events\TransactionRolledBack;
use LogicException;
use Ralder\QueryScaling\Core\CompositeIsolation;
use Ralder\QueryScaling\Core\IsolationStrategy;
use Ralder\QueryScaling\Core\IsolationViolationException;
use WeakMap;

/**
 * Isolates one scale run inside a nested transaction (savepoint) that is
 * rolled back after the measurement. Designed to nest inside an already-open
 * test transaction (DatabaseTransactions / RefreshDatabase) as well as to run
 * with no surrounding transaction at all.
 *
 * Guarantees (per connection):
 *  - restore() always leaves the connection at the exact transaction depth
 *    observed by begin();
 *  - a callback that left its own nested transactions open is cleaned up;
 *  - a callback that committed or rolled back beyond its own scope (or
 *    destroyed the physical transaction, e.g. via implicit-commit DDL on
 *    MySQL) triggers an IsolationViolationException: the assertion cannot
 *    guarantee a clean environment and fails loudly;
 *  - a callback that committed the isolation transaction and then opened a
 *    new one (ending at exactly the measured depth) is detected through the
 *    connection's transaction lifecycle events and reported as a violation:
 *    data committed before the re-open cannot be discarded;
 *  - side effects outside the database connection (Redis, filesystem,
 *    queues, HTTP, ...) are NOT isolated.
 *
 * Lifecycle-event detection requires an event dispatcher on the connection
 * (the normal Laravel setup). Without one, restore() falls back to the
 * transaction-depth checks above, which cannot see a balanced
 * commit-and-re-open.
 */
final class LaravelTransactionIsolation implements IsolationStrategy
{
    /**
     * The scope currently measuring a connection, so the (inert) transaction
     * lifecycle listeners registered once per connection can report breaches.
     *
     * @var WeakMap<Connection, self>|null
     */
    private static ?WeakMap $activeScopes = null;

    /**
     * Connections whose transaction lifecycle listeners are registered.
     *
     * @var WeakMap<Connection, true>|null
     */
    private static ?WeakMap $listeningConnections = null;

    private ?int $beforeLevel = null;

    private bool $scopeWasBreached = false;

    private function __construct(
        private readonly Connection $connection,
    ) {}

    /**
     * Build an isolation strategy for the given connection names.
     *
     * @param  non-empty-list<string>  $names
     */
    public static function forConnections(DatabaseManager $manager, array $names): IsolationStrategy
    {
        $strategies = array_map(
            static fn (string $name): self => new self($manager->connection($name)),
            $names,
        );

        /** @var non-empty-list<IsolationStrategy> $strategies */
        return count($strategies) === 1
            ? $strategies[0]
            : new CompositeIsolation($strategies);
    }

    public function connectionName(): string
    {
        return $this->connection->getName() ?? $this->connection->getDriverName();
    }

    public function begin(): void
    {
        if ($this->beforeLevel !== null) {
            throw new LogicException(
                "Isolation scope on connection [{$this->connectionName()}] was already begun and not restored. "
                .'Nested scaling assertions are not supported.'
            );
        }

        $this->beforeLevel = $this->connection->transactionLevel();
        $this->connection->beginTransaction();
        $this->scopeWasBreached = false;
        $this->activateScope();
    }

    public function restore(): void
    {
        if ($this->beforeLevel === null) {
            throw new LogicException(
                "Isolation scope on connection [{$this->connectionName()}] was not begun."
            );
        }

        $before = $this->beforeLevel;
        $this->beforeLevel = null;
        $this->deactivateScope();

        $measuredLevel = $before + 1;
        $currentLevel = $this->connection->transactionLevel();
        $rawPdo = $this->connection->getRawPdo();
        $physicallyInTransaction = $rawPdo instanceof \PDO && $rawPdo->inTransaction();

        if ($this->scopeWasBreached) {
            $this->scopeWasBreached = false;

            // The isolation transaction was closed by the callback (and possibly
            // replaced by a new one). Restore the depth begin() observed, then
            // report: whatever was committed before that cannot be undone.
            $this->connection->rollBack($before);

            throw new IsolationViolationException(
                "The scale run callback broke transaction isolation on connection [{$this->connectionName()}]: "
                ."isolation began at transaction depth {$before} and was measured at depth {$measuredLevel}, but the "
                .'callback committed or rolled back the isolation transaction itself. Data written before that point '
                .'is committed and cannot be discarded, so the surrounding environment is not clean.'
            );
        }

        if ($currentLevel > 0 && ! $physicallyInTransaction) {
            throw new IsolationViolationException(
                "The physical transaction on connection [{$this->connectionName()}] vanished while Laravel still "
                ."tracks depth {$currentLevel}. This typically means implicit-commit DDL (e.g. CREATE/ALTER TABLE on MySQL) "
                .'was executed inside the scale run. Isolation cannot be guaranteed; the surrounding transaction '
                .'may already have been committed.'
            );
        }

        if ($currentLevel === $measuredLevel) {
            $this->connection->rollBack();

            return;
        }

        if ($currentLevel > $measuredLevel) {
            // The callback opened nested transactions and never closed them:
            // roll everything back to the depth we started from.
            $this->connection->rollBack($before);

            return;
        }

        throw new IsolationViolationException(
            "The scale run callback broke transaction isolation on connection [{$this->connectionName()}]: "
            ."isolation began at transaction depth {$before}, the run was measured at depth {$measuredLevel}, "
            ."but the connection is now at depth {$currentLevel}. The callback committed or rolled back "
            .'transactions outside its own scope, and data written by the run cannot be discarded reliably.'
        );
    }

    private function activateScope(): void
    {
        $scopes = self::$activeScopes ??= new WeakMap;
        $scopes[$this->connection] = $this;

        $this->ensureLifecycleListenersRegistered();
    }

    private function deactivateScope(): void
    {
        if (self::$activeScopes !== null) {
            unset(self::$activeScopes[$this->connection]);
        }
    }

    private function ensureLifecycleListenersRegistered(): void
    {
        $listening = self::$listeningConnections ??= new WeakMap;

        if (isset($listening[$this->connection])) {
            return;
        }

        $dispatcher = $this->connection->getEventDispatcher();

        if (! $dispatcher instanceof Dispatcher) {
            // Without lifecycle events a balanced commit-and-re-open cannot be
            // detected; the transaction-depth checks in restore() still apply.
            return;
        }

        $dispatcher->listen(TransactionCommitted::class, static function (TransactionCommitted $event): void {
            self::noteLifecycleEvent($event->connection);
        });

        $dispatcher->listen(TransactionRolledBack::class, static function (TransactionRolledBack $event): void {
            self::noteLifecycleEvent($event->connection);
        });

        $listening[$this->connection] = true;
    }

    private static function noteLifecycleEvent(Connection $connection): void
    {
        $scope = self::$activeScopes?->offsetExists($connection) === true
            ? self::$activeScopes[$connection]
            : null;

        if ($scope === null || $scope->beforeLevel === null) {
            return;
        }

        // A commit or rollback that drops the connection below the measured
        // depth closed the isolation transaction itself, even if the callback
        // later opens a new one at the same depth.
        if ($connection->transactionLevel() < $scope->beforeLevel + 1) {
            $scope->scopeWasBreached = true;
        }
    }
}
