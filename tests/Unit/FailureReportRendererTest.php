<?php

declare(strict_types=1);

namespace Ralder\QueryScaling\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Ralder\QueryScaling\Core\ConstantQueryCount;
use Ralder\QueryScaling\Core\MeasurementSeries;
use Ralder\QueryScaling\Core\RecordedQuery;
use Ralder\QueryScaling\Core\Report\FailureReportRenderer;
use Ralder\QueryScaling\Core\ScaleMeasurement;

final class FailureReportRendererTest extends TestCase
{
    private function series(): MeasurementSeries
    {
        return new MeasurementSeries([
            new ScaleMeasurement(2, [
                new RecordedQuery('mysql', 'select * from users where id = ? limit 1', [1]),
                new RecordedQuery('mysql', 'select * from posts', []),
            ]),
            new ScaleMeasurement(5, [
                new RecordedQuery('mysql', 'select * from users where id = ? limit 1', [2]),
                new RecordedQuery('mysql', 'select * from users where id = ? limit 1', [3]),
                new RecordedQuery('mysql', 'select * from posts', []),
            ]),
        ]);
    }

    public function test_renders_expected_summary_measurements_and_trend(): void
    {
        $expectation = new ConstantQueryCount;
        $result = $expectation->evaluate($this->series());

        $report = (new FailureReportRenderer)->render($this->series(), $expectation, $result);

        $this->assertStringContainsString('Query scaling assertion failed', $report);
        $this->assertStringContainsString("Expected:\n  query count to remain constant", $report);
        $this->assertStringContainsString("Measurements:\n\nScale  Queries\n2      2\n5      3", $report);
        $this->assertStringContainsString('approximately Q(n) = 1.333333 + 0.333333n', $report);
        $this->assertStringContainsString('not an asymptotic complexity proof', $report);
    }

    public function test_renders_violated_segments(): void
    {
        $expectation = new ConstantQueryCount;
        $result = $expectation->evaluate($this->series());

        $report = (new FailureReportRenderer)->render($this->series(), $expectation, $result);

        $this->assertStringContainsString('Violated growth segments:', $report);
        $this->assertStringContainsString('scale 2 -> 5: 2 -> 3 queries (+1 observed, +0 allowed)', $report);
    }

    public function test_renders_growing_fingerprints_with_occurrence_table_and_connection(): void
    {
        $expectation = new ConstantQueryCount;
        $result = $expectation->evaluate($this->series());

        $report = (new FailureReportRenderer)->render($this->series(), $expectation, $result);

        $this->assertStringContainsString("Growing query fingerprints:\n\n1. select * from users where id = ? limit 1", $report);
        $this->assertStringContainsString("   Scale  Occurrences\n   2      1\n   5      2", $report);
        $this->assertStringContainsString('   Connection: mysql', $report);
        $this->assertStringNotContainsString('select * from posts'."\n", $report, 'fingerprints that did not grow are not listed');
    }

    public function test_bindings_are_hidden_by_default_and_shown_on_request(): void
    {
        $expectation = new ConstantQueryCount;
        $result = $expectation->evaluate($this->series());

        $hidden = (new FailureReportRenderer)->render($this->series(), $expectation, $result);
        $this->assertStringNotContainsString('Example bindings:', $hidden);

        $shown = (new FailureReportRenderer)->render($this->series(), $expectation, $result, showBindings: true);
        $this->assertStringContainsString('Example bindings: [2]  |  [3]', $shown);
    }

    public function test_report_is_deterministic_and_ansi_free(): void
    {
        $expectation = new ConstantQueryCount;
        $result = $expectation->evaluate($this->series());
        $renderer = new FailureReportRenderer;

        $a = $renderer->render($this->series(), $expectation, $result);
        $b = $renderer->render($this->series(), $expectation, $result);

        $this->assertSame($a, $b);
        $this->assertStringNotContainsString("\x1b", $a);
    }

    public function test_lists_growing_fingerprints_in_deterministic_order(): void
    {
        $series = new MeasurementSeries([
            new ScaleMeasurement(2, [
                new RecordedQuery('mysql', 'select a', []),
                new RecordedQuery('mysql', 'select b', []),
                new RecordedQuery('mysql', 'select tie', []),
                new RecordedQuery('sqlite', 'select tie', []),
            ]),
            new ScaleMeasurement(5, [
                new RecordedQuery('mysql', 'select a', []),
                new RecordedQuery('mysql', 'select a', []),
                new RecordedQuery('mysql', 'select a', []),
                new RecordedQuery('mysql', 'select a', []),
                new RecordedQuery('mysql', 'select b', []),
                new RecordedQuery('mysql', 'select b', []),
                new RecordedQuery('mysql', 'select b', []),
                new RecordedQuery('mysql', 'select tie', []),
                new RecordedQuery('mysql', 'select tie', []),
                new RecordedQuery('sqlite', 'select tie', []),
                new RecordedQuery('sqlite', 'select tie', []),
                new RecordedQuery('mysql', 'select z', []),
            ]),
        ]);
        $expectation = new ConstantQueryCount;
        $result = $expectation->evaluate($series);
        $report = (new FailureReportRenderer)->render($series, $expectation, $result);

        $this->assertSame(1, preg_match('/1\\. select a.*2\\. select b.*3\\. select tie.*4\\. select tie.*5\\. select z/s', $report));
        $this->assertStringContainsString("3. select tie\n   Scale  Occurrences\n   2      1\n   5      2\n   Connection: mysql", $report);
        $this->assertStringContainsString("4. select tie\n   Scale  Occurrences\n   2      1\n   5      2\n   Connection: sqlite", $report);
    }

    public function test_limits_the_number_of_listed_fingerprints(): void
    {
        $measurements = [];
        foreach ([2, 5] as $scale) {
            $queries = [];
            foreach (range(1, $scale === 2 ? 1 : 3) as $g) {
                // fingerprints "growth-a".."growth-l" grow together
                for ($i = 0; $i < 12; $i++) {
                    for ($j = 0; $j < $g; $j++) {
                        $queries[] = new RecordedQuery('mysql', sprintf('select a from t%02d', $i), []);
                    }
                }
            }
            $measurements[] = new ScaleMeasurement($scale, $queries);
        }
        $series = new MeasurementSeries($measurements);

        $expectation = new ConstantQueryCount;
        $result = $expectation->evaluate($series);
        $report = (new FailureReportRenderer)->render($series, $expectation, $result, maxGrowingFingerprints: 5);

        $this->assertSame(5, substr_count($report, "\n   Scale  Occurrences"));
    }
}
