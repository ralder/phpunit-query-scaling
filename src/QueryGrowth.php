<?php

declare(strict_types=1);

namespace Ralder\QueryScaling;

use Ralder\QueryScaling\Core\AtMostSlopeQueryCount;
use Ralder\QueryScaling\Core\ConstantQueryCount;

/**
 * Factories for scaling contracts.
 */
final class QueryGrowth
{
    private function __construct() {}

    /**
     * Every measured scale factor must yield the same query count
     * (up to $tolerance queries of absolute spread).
     */
    public static function constant(int $tolerance = 0): ConstantQueryCount
    {
        return new ConstantQueryCount($tolerance);
    }

    /**
     * On every pair of consecutive measured scales the query count may grow
     * by at most $maxSlope per additional item, plus an absolute per-segment
     * $tolerance of extra queries.
     */
    public static function atMostSlope(float|int $maxSlope, float|int $tolerance = 0): AtMostSlopeQueryCount
    {
        return new AtMostSlopeQueryCount((float) $maxSlope, (float) $tolerance);
    }
}
