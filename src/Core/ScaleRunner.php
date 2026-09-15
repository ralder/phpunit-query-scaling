<?php

declare(strict_types=1);

namespace Ralder\QueryScaling\Core;

use Closure;

/**
 * Executes the same scenario once per scale factor, each time in a fresh
 * isolation scope, and records only the queries of the run callback.
 *
 * Lifecycle per scale:
 *   isolation.begin() -> populate(scale) -> [warmup(scale)] ->
 *   collector.measure(run) -> isolation.restore()   (always, via finally)
 */
final class ScaleRunner
{
    public function __construct(
        private readonly QueryCollectorContract $collector,
        private readonly IsolationStrategy $isolation,
    ) {}

    /**
     * @param  Closure(int): void  $populate  creates the dataset for the scale
     * @param  (Closure(int): void)|null  $warmup  optional per-scale warmup; must not mutate the dataset
     * @param  Closure(): mixed  $run  the scenario under measurement
     */
    public function run(
        ScaleFactors $scales,
        Closure $populate,
        ?Closure $warmup,
        Closure $run,
        bool $normalizeLiterals = false,
    ): MeasurementSeries {
        $measurements = [];

        foreach ($scales as $scale) {
            $this->isolation->begin();

            try {
                $populate($scale);

                if ($warmup !== null) {
                    $warmup($scale);
                }

                $queries = $this->collector->measure($run);
            } finally {
                $this->isolation->restore();
            }

            $measurements[] = new ScaleMeasurement($scale, $queries, $normalizeLiterals);
        }

        return new MeasurementSeries($measurements);
    }
}
