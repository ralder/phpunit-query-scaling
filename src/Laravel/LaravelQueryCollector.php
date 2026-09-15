<?php

declare(strict_types=1);

namespace Ralder\QueryScaling\Laravel;

use Closure;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\Events\QueryExecuted;
use LogicException;
use Ralder\QueryScaling\Core\QueryCollectorContract;
use Ralder\QueryScaling\Core\RecordedQuery;
use WeakMap;

/**
 * Measures the queries issued by a callback on the configured connections.
 *
 * Exactly one QueryExecuted listener is registered per event-dispatcher
 * instance for the whole PHP process. Registrations are keyed by dispatcher
 * object identity in a WeakMap, so a garbage-collected dispatcher releases its
 * registration and a rebuilt application (whose fresh dispatcher may recycle
 * the collected one's object id) always gets its own listener. Repeated
 * assertions on the same application never add listeners. The listener is
 * inert unless a measurement session is active, and sessions are always closed
 * in a finally block.
 */
final class LaravelQueryCollector implements QueryCollectorContract
{
    /** @var WeakMap<Dispatcher, true>|null */
    private static ?WeakMap $registeredDispatchers = null;

    private static ?MeasurementSession $activeSession = null;

    /**
     * @param  non-empty-list<string>  $connections
     */
    public function __construct(
        private readonly Dispatcher $events,
        private readonly array $connections,
    ) {
        $this->ensureListenerRegistered();
    }

    /**
     * @return list<RecordedQuery>
     */
    public function measure(Closure $callback): array
    {
        if (self::$activeSession !== null) {
            throw new LogicException(
                'A query measurement session is already active. Measurement sessions cannot overlap; '
                .'do not call scaling assertions (or collector::measure()) from inside a measured callback.'
            );
        }

        $session = new MeasurementSession($this->connections);
        self::$activeSession = $session;

        try {
            $callback();
        } finally {
            self::$activeSession = null;
        }

        return $session->queries();
    }

    /**
     * How many live dispatchers currently have a listener registered.
     * Registrations of collected dispatchers disappear with them.
     * Introspection for tests; not part of the public assertion API.
     */
    public static function listenerRegistrationCount(): int
    {
        return self::$registeredDispatchers === null ? 0 : count(self::$registeredDispatchers);
    }

    private function ensureListenerRegistered(): void
    {
        $registered = self::$registeredDispatchers ??= new WeakMap;

        if (isset($registered[$this->events])) {
            return;
        }

        $this->events->listen(QueryExecuted::class, static function (QueryExecuted $event): void {
            self::$activeSession?->record($event->connectionName, $event->sql, array_values($event->bindings));
        });

        $registered[$this->events] = true;
    }
}
