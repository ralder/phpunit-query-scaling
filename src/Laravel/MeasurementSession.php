<?php

declare(strict_types=1);

namespace Ralder\QueryScaling\Laravel;

use Ralder\QueryScaling\Core\RecordedQuery;

/**
 * Buffers the queries of exactly one measure() call.
 * Never registers listeners; lifecycle is owned by LaravelQueryCollector.
 */
final class MeasurementSession
{
    /** @var list<RecordedQuery> */
    private array $queries = [];

    /**
     * @param  non-empty-list<string>  $connections  connection names to record
     */
    public function __construct(
        private readonly array $connections,
    ) {}

    /**
     * @param  list<mixed>  $bindings
     */
    public function record(string $connectionName, string $sql, array $bindings): void
    {
        if (! in_array($connectionName, $this->connections, true)) {
            return;
        }

        $this->queries[] = new RecordedQuery($connectionName, $sql, $bindings);
    }

    /**
     * @return list<RecordedQuery>
     */
    public function queries(): array
    {
        return $this->queries;
    }
}
