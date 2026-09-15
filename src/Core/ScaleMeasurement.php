<?php

declare(strict_types=1);

namespace Ralder\QueryScaling\Core;

/**
 * All queries recorded for one scale factor, grouped by fingerprint.
 */
final class ScaleMeasurement
{
    public const MAX_BINDINGS_EXAMPLES = 3;

    /** @var array<string, array{fingerprint: QueryFingerprint, count: int, bindings: list<list<mixed>>}>|null */
    private ?array $grouped = null;

    /**
     * @param  list<RecordedQuery>  $queries
     */
    public function __construct(
        public readonly int $scale,
        public readonly array $queries,
        private readonly bool $normalizeLiterals = false,
    ) {}

    public function count(): int
    {
        return count($this->queries);
    }

    /**
     * @return array<string, int> fingerprint key => occurrences at this scale
     */
    public function fingerprintCounts(): array
    {
        $this->compute();

        /** @var array<string, array{fingerprint: QueryFingerprint, count: int, bindings: list<list<mixed>>}> $grouped */
        $grouped = $this->grouped;

        return array_map(static fn (array $entry): int => $entry['count'], $grouped);
    }

    /**
     * @return array<string, QueryFingerprint> fingerprint key => fingerprint
     */
    public function fingerprints(): array
    {
        $this->compute();

        /** @var array<string, array{fingerprint: QueryFingerprint, count: int, bindings: list<list<mixed>>}> $grouped */
        $grouped = $this->grouped;

        return array_map(static fn (array $entry): QueryFingerprint => $entry['fingerprint'], $grouped);
    }

    /**
     * Up to MAX_BINDINGS_EXAMPLES raw bindings captured for a fingerprint.
     * Diagnostics only — render them exclusively in explicit opt-in modes.
     *
     * @return list<list<mixed>>
     */
    public function bindingsExamples(string $fingerprintKey): array
    {
        $this->compute();

        /** @var array<string, array{fingerprint: QueryFingerprint, count: int, bindings: list<list<mixed>>}> $grouped */
        $grouped = $this->grouped;

        return $grouped[$fingerprintKey]['bindings'] ?? [];
    }

    private function compute(): void
    {
        if ($this->grouped !== null) {
            return;
        }

        $this->grouped = [];
        foreach ($this->queries as $query) {
            $fingerprint = QueryFingerprint::from($query->connection, $query->sql, $this->normalizeLiterals);
            $key = $fingerprint->key();

            if (! isset($this->grouped[$key])) {
                $this->grouped[$key] = ['fingerprint' => $fingerprint, 'count' => 0, 'bindings' => []];
            }

            $this->grouped[$key]['count']++;

            if (count($this->grouped[$key]['bindings']) < self::MAX_BINDINGS_EXAMPLES && $query->bindings !== []) {
                $this->grouped[$key]['bindings'][] = $query->bindings;
            }
        }
    }
}
