<?php

declare(strict_types=1);

namespace Ralder\QueryScaling\Core\Report;

use DateTimeInterface;
use Ralder\QueryScaling\Core\DecimalFormatter;
use Ralder\QueryScaling\Core\EvaluationResult;
use Ralder\QueryScaling\Core\MeasurementSeries;
use Ralder\QueryScaling\Core\ScalingExpectation;
use Stringable;

/**
 * Deterministic, ANSI-free, CI-friendly failure report.
 *
 * Ordering guarantees: growing fingerprints are sorted by net growth desc,
 * then SQL asc, then connection asc (see MeasurementSeries::growingFingerprints).
 */
final class FailureReportRenderer
{
    public function render(
        MeasurementSeries $series,
        ScalingExpectation $expectation,
        EvaluationResult $result,
        bool $showBindings = false,
        int $maxGrowingFingerprints = 10,
    ): string {
        $lines = [
            'Query scaling assertion failed',
            '',
            'Expected:',
            '  '.$expectation->summary(),
            '',
            'Measurements:',
            '',
        ];

        $lines = [...$lines, ...$this->table(
            ['Scale', 'Queries'],
            array_map(
                static fn (int $scale, int $count): array => [(string) $scale, (string) $count],
                $series->scales(),
                $series->counts(),
            ),
        )];

        if ($result->violations !== []) {
            $lines[] = '';
            $lines[] = 'Violated growth segments:';
            foreach ($result->violations as $violation) {
                $lines[] = sprintf(
                    '  scale %d -> %d: %d -> %d queries (%s observed, %s allowed)',
                    $violation->fromScale,
                    $violation->toScale,
                    $violation->fromCount,
                    $violation->toCount,
                    '+'.$this->number($violation->observedDelta),
                    '+'.$this->number($violation->allowedDelta),
                );
            }
        }

        $lines[] = '';
        $lines[] = 'Observed diagnostic trend:';
        $lines[] = '  approximately '.$this->trendLine($series->scales(), $series->counts()).' over the measured scales';
        $lines[] = '  (empirical fit over the measured checkpoints, not an asymptotic complexity proof)';

        $lines[] = '';
        $lines[] = 'Growing query fingerprints:';
        $lines[] = '';

        $growing = array_slice($series->growingFingerprints(), 0, max(1, $maxGrowingFingerprints));

        if ($growing === []) {
            $lines[] = '  (no fingerprint grew between the smallest and largest measured scale)';
        } else {
            foreach ($growing as $index => $entry) {
                $fingerprint = $entry['fingerprint'];
                $lines[] = ($index + 1).'. '.$fingerprint->sql;
                $rows = [];
                foreach ($entry['counts'] as $scale => $count) {
                    $rows[] = [(string) $scale, (string) $count];
                }
                foreach ($this->table(['Scale', 'Occurrences'], $rows) as $row) {
                    $lines[] = '   '.$row;
                }
                $lines[] = '   Connection: '.$fingerprint->connection;

                if ($showBindings) {
                    $examples = $this->bindingsExamples($series, $fingerprint->key());
                    if ($examples !== []) {
                        $rendered = array_map(
                            fn (array $bindings): string => '['.implode(', ', array_map($this->bindingValue(...), $bindings)).']',
                            $examples,
                        );
                        $lines[] = '   Example bindings: '.implode('  |  ', $rendered);
                    }
                }

                $lines[] = '';
            }
        }

        return implode("\n", $lines);
    }

    /**
     * @param  list<string>  $headers
     * @param  list<list<string>>  $rows
     * @return list<string>
     */
    private function table(array $headers, array $rows): array
    {
        $widths = [];
        foreach ($headers as $i => $header) {
            $widths[$i] = strlen($header);
        }
        foreach ($rows as $row) {
            foreach ($row as $i => $cell) {
                $widths[$i] = max($widths[$i], strlen($cell));
            }
        }

        $lines = [$this->renderRow($headers, $widths)];
        foreach ($rows as $row) {
            $lines[] = $this->renderRow($row, $widths);
        }

        return $lines;
    }

    /**
     * @param  array<int, string>  $cells
     * @param  array<int, int>  $widths
     */
    private function renderRow(array $cells, array $widths): string
    {
        $padded = [];
        foreach ($cells as $i => $cell) {
            $padded[] = str_pad($cell, $widths[$i]);
        }

        return rtrim(implode('  ', $padded));
    }

    /**
     * Least-squares line over the measured checkpoints. Diagnostic only.
     *
     * @param  list<int>  $scales
     * @param  list<int>  $counts
     */
    private function trendLine(array $scales, array $counts): string
    {
        $n = count($scales);
        $meanX = array_sum($scales) / $n;
        $meanY = array_sum($counts) / $n;

        $numerator = 0.0;
        $denominator = 0.0;
        foreach ($scales as $i => $x) {
            $numerator += ($x - $meanX) * ($counts[$i] - $meanY);
            $denominator += ($x - $meanX) ** 2;
        }

        $slope = $denominator > self::EPSILON ? $numerator / $denominator : 0.0;
        $intercept = $meanY - $slope * $meanX;

        return sprintf('Q(n) = %s + %sn', $this->number($intercept), $this->number($slope));
    }

    private const EPSILON = 1e-12;

    private function number(float $value): string
    {
        return DecimalFormatter::format($value);
    }

    /**
     * Example bindings for a fingerprint, preferring the largest scale that
     * recorded it (where growth is most visible).
     *
     * @return list<list<mixed>>
     */
    private function bindingsExamples(MeasurementSeries $series, string $fingerprintKey): array
    {
        $examples = [];
        foreach ($series->measurements() as $measurement) {
            $forScale = $measurement->bindingsExamples($fingerprintKey);
            if ($forScale !== []) {
                $examples = $forScale;
            }
        }

        return $examples;
    }

    private function bindingValue(mixed $value): string
    {
        return match (true) {
            $value === null => 'null',
            is_bool($value) => $value ? 'true' : 'false',
            is_int($value), is_float($value) => (string) $value,
            is_string($value) => "'".$this->truncate($value)."'",
            $value instanceof DateTimeInterface => $value->format('Y-m-d H:i:s'),
            is_object($value) && ! $value instanceof Stringable => $value::class,
            is_array($value) => $this->truncate((string) json_encode($value)),
            default => '<'.get_debug_type($value).'>',
        };
    }

    private function truncate(string $value): string
    {
        return strlen($value) > 60 ? substr($value, 0, 57).'...' : $value;
    }
}
