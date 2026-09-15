<?php

declare(strict_types=1);

namespace Ralder\QueryScaling\Core;

use InvalidArgumentException;

/**
 * Ordered set of scale measurements, one per scale factor, ascending by scale.
 *
 * Besides the raw measurements and their scale/count projections, the series
 * exposes derived views (per-fingerprint counts and the growing fingerprints)
 * that custom ScalingExpectation implementations and report renderers build on.
 */
final class MeasurementSeries
{
    /** @var non-empty-list<ScaleMeasurement> */
    private readonly array $measurements;

    /**
     * @param  list<ScaleMeasurement>  $measurements
     */
    public function __construct(array $measurements)
    {
        if (count($measurements) < 2) {
            throw new InvalidArgumentException('A measurement series needs at least two scale measurements.');
        }

        for ($i = 1, $n = count($measurements); $i < $n; $i++) {
            if ($measurements[$i]->scale <= $measurements[$i - 1]->scale) {
                throw new InvalidArgumentException('Measurements must be in strictly ascending scale order.');
            }
        }

        $this->measurements = $measurements;
    }

    /**
     * @return non-empty-list<ScaleMeasurement>
     */
    public function measurements(): array
    {
        return $this->measurements;
    }

    /**
     * @return non-empty-list<int>
     */
    public function scales(): array
    {
        return array_map(static fn (ScaleMeasurement $m): int => $m->scale, $this->measurements);
    }

    /**
     * @return non-empty-list<int>
     */
    public function counts(): array
    {
        return array_map(static fn (ScaleMeasurement $m): int => $m->count(), $this->measurements);
    }

    /**
     * Per-fingerprint occurrence counts, indexed by fingerprint key, with a
     * counts entry for every measured scale (0 where absent).
     *
     * @return array<string, array{fingerprint: QueryFingerprint, counts: array<int, int>}>
     */
    public function fingerprintCountsByScale(): array
    {
        $scales = $this->scales();

        /** @var array<string, QueryFingerprint> $fingerprints */
        $fingerprints = [];
        /** @var array<string, array<int, int>> $captured */
        $captured = [];

        foreach ($this->measurements as $measurement) {
            foreach ($measurement->fingerprints() as $key => $fingerprint) {
                $fingerprints[$key] = $fingerprint;
            }
            foreach ($measurement->fingerprintCounts() as $key => $count) {
                $captured[$key][$measurement->scale] = $count;
            }
        }

        $perFingerprint = [];
        foreach ($fingerprints as $key => $fingerprint) {
            $counts = array_fill_keys($scales, 0);
            foreach ($captured[$key] ?? [] as $scale => $count) {
                $counts[$scale] = $count;
            }
            $perFingerprint[$key] = ['fingerprint' => $fingerprint, 'counts' => $counts];
        }

        return $perFingerprint;
    }

    /**
     * Fingerprints whose occurrence count grew between the smallest and the
     * largest measured scale. Ordered deterministically: net growth desc,
     * then SQL asc, then connection asc.
     *
     * @return list<array{fingerprint: QueryFingerprint, counts: array<int, int>, netGrowth: int}>
     */
    public function growingFingerprints(): array
    {
        $scales = $this->scales();
        $first = $scales[0];
        $last = $scales[count($scales) - 1];

        $growing = [];
        foreach ($this->fingerprintCountsByScale() as $entry) {
            $netGrowth = $entry['counts'][$last] - $entry['counts'][$first];
            if ($netGrowth > 0) {
                $growing[] = [
                    'fingerprint' => $entry['fingerprint'],
                    'counts' => $entry['counts'],
                    'netGrowth' => $netGrowth,
                ];
            }
        }

        usort($growing, static function (array $a, array $b): int {
            return $b['netGrowth'] <=> $a['netGrowth']
                ?: strcmp($a['fingerprint']->sql, $b['fingerprint']->sql)
                ?: strcmp($a['fingerprint']->connection, $b['fingerprint']->connection);
        });

        return $growing;
    }
}
