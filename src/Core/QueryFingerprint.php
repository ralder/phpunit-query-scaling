<?php

declare(strict_types=1);

namespace Ralder\QueryScaling\Core;

/**
 * Identity of a query for grouping: connection name + normalized SQL.
 * Bindings are never part of the fingerprint. Identity is expressed by
 * key(); two fingerprints with equal keys describe the same query shape.
 */
final class QueryFingerprint
{
    private function __construct(
        public readonly string $connection,
        public readonly string $sql,
    ) {}

    public static function from(string $connection, string $rawSql, bool $normalizeLiterals = false): self
    {
        return new self($connection, self::normalize($rawSql, $normalizeLiterals));
    }

    /**
     * Deliberately minimal normalization:
     *  - trim + whitespace collapse (always);
     *  - optional literal folding (numbers and single-quoted strings -> "?"),
     *    which is a heuristic: it can over-group hand-written SQL with
     *    semantically different literals. It is off by default.
     */
    public static function normalize(string $sql, bool $normalizeLiterals = false): string
    {
        $sql = trim($sql);
        $sql = (string) preg_replace('/\s+/', ' ', $sql);

        if ($normalizeLiterals) {
            // '...' string literals (with backslash escapes) first, so digits
            // inside strings are not folded by the numeric rule below.
            $sql = (string) preg_replace("/'(?:[^'\\\\]|\\\\.)*'/", '?', $sql);
            // stand-alone numeric literals; digits adjacent to word chars,
            // dots or backticks (identifiers, column2, utf8mb4) are kept.
            $sql = (string) preg_replace('/(?<![\w.`])\d+(?:\.\d+)?(?![\w`])/', '?', $sql);
            $sql = (string) preg_replace('/\?+/', '?', $sql);
            $sql = trim((string) preg_replace('/\s+/', ' ', $sql));
        }

        return $sql;
    }

    /**
     * Stable identity key, also used as deterministic sort input.
     */
    public function key(): string
    {
        return $this->connection."\0".$this->sql;
    }
}
