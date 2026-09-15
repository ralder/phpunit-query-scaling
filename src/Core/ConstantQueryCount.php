<?php

declare(strict_types=1);

namespace Ralder\QueryScaling\Core;

use InvalidArgumentException;

/**
 * Every measured scale factor must yield the same query count,
 * within an absolute tolerance (in number of queries).
 */
final class ConstantQueryCount implements ScalingExpectation
{
    public function __construct(
        public readonly int $tolerance = 0,
    ) {
        if ($this->tolerance < 0) {
            throw new InvalidArgumentException('Tolerance must be >= 0 (absolute number of queries).');
        }
    }

    public function evaluate(MeasurementSeries $series): EvaluationResult
    {
        $scales = $series->scales();
        $counts = $series->counts();

        if (max($counts) - min($counts) <= $this->tolerance) {
            return EvaluationResult::passed();
        }

        $violations = [];
        for ($i = 1, $n = count($counts); $i < $n; $i++) {
            $delta = abs($counts[$i] - $counts[$i - 1]);
            if ($delta > $this->tolerance) {
                $violations[] = new ScalingViolation(
                    fromScale: $scales[$i - 1],
                    toScale: $scales[$i],
                    fromCount: $counts[$i - 1],
                    toCount: $counts[$i],
                    observedDelta: (float) $delta,
                    allowedDelta: (float) $this->tolerance,
                );
            }
        }

        if ($violations === []) {
            // The whole band exceeds the tolerance even though no single
            // step does (e.g. +1, +1 with tolerance 1): report the extremes.
            $minIndex = array_search(min($counts), $counts, true);
            $maxIndex = array_search(max($counts), $counts, true);
            $violations[] = new ScalingViolation(
                fromScale: $scales[(int) $minIndex],
                toScale: $scales[(int) $maxIndex],
                fromCount: $counts[(int) $minIndex],
                toCount: $counts[(int) $maxIndex],
                observedDelta: (float) (max($counts) - min($counts)),
                allowedDelta: (float) $this->tolerance,
            );
        }

        return EvaluationResult::failed($violations);
    }

    public function summary(): string
    {
        $summary = 'query count to remain constant';

        if ($this->tolerance > 0) {
            $summary .= " (tolerance: {$this->tolerance})";
        }

        return $summary;
    }
}
