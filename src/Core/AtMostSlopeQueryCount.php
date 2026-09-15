<?php

declare(strict_types=1);

namespace Ralder\QueryScaling\Core;

use InvalidArgumentException;

/**
 * For EVERY consecutive pair of measured scale factors the observed query
 * increase must stay within the allowed budget:
 *
 *     q(i+1) - q(i) <= maxSlope * (s(i+1) - s(i)) + tolerance
 *
 * The tolerance is an absolute number of extra queries allowed on each
 * segment. A decrease between two scales always passes: this contract
 * constrains growth, it does not require growth. No global regression is
 * fitted for the verdict — every real segment is checked.
 */
final class AtMostSlopeQueryCount implements ScalingExpectation
{
    private const EPSILON = 1e-9;

    public function __construct(
        public readonly float $maxSlope,
        public readonly float $tolerance = 0.0,
    ) {
        if (! is_finite($this->maxSlope)) {
            throw new InvalidArgumentException('Max slope must be finite (queries per additional item).');
        }
        if ($this->maxSlope < 0) {
            throw new InvalidArgumentException('Max slope must be >= 0 (queries per additional item).');
        }
        if (! is_finite($this->tolerance)) {
            throw new InvalidArgumentException('Tolerance must be finite (absolute number of queries per segment).');
        }
        if ($this->tolerance < 0) {
            throw new InvalidArgumentException('Tolerance must be >= 0 (absolute number of queries per segment).');
        }
    }

    public function evaluate(MeasurementSeries $series): EvaluationResult
    {
        $scales = $series->scales();
        $counts = $series->counts();
        $violations = [];

        for ($i = 1, $n = count($scales); $i < $n; $i++) {
            $observed = $counts[$i] - $counts[$i - 1];
            $allowed = $this->maxSlope * ($scales[$i] - $scales[$i - 1]) + $this->tolerance;

            if ($observed > $allowed + self::EPSILON) {
                $violations[] = new ScalingViolation(
                    fromScale: $scales[$i - 1],
                    toScale: $scales[$i],
                    fromCount: $counts[$i - 1],
                    toCount: $counts[$i],
                    observedDelta: (float) $observed,
                    allowedDelta: $allowed,
                );
            }
        }

        return $violations === []
            ? EvaluationResult::passed()
            : EvaluationResult::failed($violations);
    }

    public function summary(): string
    {
        $slope = DecimalFormatter::format($this->maxSlope);
        $summary = "query count to grow at most {$slope} per additional item";

        if ($this->tolerance > 0) {
            $summary .= ' (tolerance: '.DecimalFormatter::format($this->tolerance).')';
        }

        return $summary;
    }
}
