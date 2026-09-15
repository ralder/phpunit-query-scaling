<?php

declare(strict_types=1);

namespace Ralder\QueryScaling\Core;

/**
 * A scaling contract checked against measured scale factors.
 *
 * Expectations are empirical checks over the measured points. They must never
 * be described as mathematical proofs of asymptotic complexity.
 */
interface ScalingExpectation
{
    public function evaluate(MeasurementSeries $series): EvaluationResult;

    /**
     * Human-readable contract description used in failure reports,
     * e.g. "query count to remain constant".
     */
    public function summary(): string;
}
