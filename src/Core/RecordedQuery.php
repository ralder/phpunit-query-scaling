<?php

declare(strict_types=1);

namespace Ralder\QueryScaling\Core;

/**
 * A single SQL statement observed while a measurement session was active.
 */
final class RecordedQuery
{
    /**
     * @param  string  $connection  name of the DB connection the query ran on
     * @param  string  $sql  raw SQL as reported (placeholders intact)
     * @param  list<mixed>  $bindings  raw bindings; kept for opt-in diagnostics only
     */
    public function __construct(
        public readonly string $connection,
        public readonly string $sql,
        public readonly array $bindings = [],
    ) {}
}
