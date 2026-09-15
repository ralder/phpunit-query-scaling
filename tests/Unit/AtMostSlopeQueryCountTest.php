<?php

declare(strict_types=1);

namespace Ralder\QueryScaling\Tests\Unit;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Ralder\QueryScaling\Core\AtMostSlopeQueryCount;
use Ralder\QueryScaling\Core\MeasurementSeries;
use Ralder\QueryScaling\Core\RecordedQuery;
use Ralder\QueryScaling\Core\ScaleMeasurement;

final class AtMostSlopeQueryCountTest extends TestCase
{
    public function test_linear_growth_passes_with_slope_one(): void
    {
        // scales 2,5,10: +3 queries then +5 queries — exactly 1 query per added item
        $result = (new AtMostSlopeQueryCount(1))->evaluate($this->series([2, 5, 10], [3, 6, 11]));

        $this->assertTrue($result->passed);
    }

    public function test_linear_growth_fails_with_slope_zero(): void
    {
        $result = (new AtMostSlopeQueryCount(0))->evaluate($this->series([2, 5, 10], [3, 6, 11]));

        $this->assertFalse($result->passed);
        $this->assertCount(2, $result->violations);
    }

    public function test_decreasing_counts_always_pass(): void
    {
        $result = (new AtMostSlopeQueryCount(0))->evaluate($this->series([2, 5, 10], [11, 6, 3]));

        $this->assertTrue($result->passed);
    }

    public function test_checks_every_consecutive_pair_not_just_the_trend(): void
    {
        // overall growth is +8 over 8 items (avg slope 1), but the first
        // segment 2->5 adds 8 queries (allowed: 3) and must fail
        $result = (new AtMostSlopeQueryCount(1))->evaluate($this->series([2, 5, 10], [2, 10, 10]));

        $this->assertFalse($result->passed);
        $this->assertCount(1, $result->violations);

        $violation = $result->violations[0];
        $this->assertSame(2, $violation->fromScale);
        $this->assertSame(5, $violation->toScale);
        $this->assertSame(8.0, $violation->observedDelta);
        $this->assertSame(3.0, $violation->allowedDelta);
    }

    public function test_tolerance_is_absolute_per_segment(): void
    {
        // segment 2->5: +4 queries allowed with slope 1 + tolerance 1 (3+1=4) => exactly at the boundary
        $series = $this->series([2, 5, 10], [2, 6, 9]);
        $result = (new AtMostSlopeQueryCount(1, tolerance: 1))->evaluate($series);

        $this->assertTrue($result->passed);
    }

    public function test_growth_beyond_slope_plus_tolerance_fails(): void
    {
        $result = (new AtMostSlopeQueryCount(1, tolerance: 1))->evaluate($this->series([2, 5, 10], [2, 8, 11]));

        $this->assertFalse($result->passed);
    }

    public function test_fractional_slope(): void
    {
        // slope 0.5: 2->5 allows +1.5 => observed integer 2 fails
        $passing = (new AtMostSlopeQueryCount(0.5))->evaluate($this->series([2, 5, 10], [2, 3, 4]));
        $failing = (new AtMostSlopeQueryCount(0.5))->evaluate($this->series([2, 5, 10], [2, 4, 5]));

        $this->assertTrue($passing->passed);
        $this->assertFalse($failing->passed);
    }

    public function test_summary_names_the_contract(): void
    {
        $this->assertSame(
            'query count to grow at most 1 per additional item',
            (new AtMostSlopeQueryCount(1))->summary()
        );
        $this->assertSame(
            'query count to grow at most 0.5 per additional item (tolerance: 2)',
            (new AtMostSlopeQueryCount(0.5, tolerance: 2))->summary()
        );
    }

    public function test_rejects_non_finite_slope(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('finite');

        new AtMostSlopeQueryCount(NAN);
    }

    public function test_rejects_infinite_slope(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('finite');

        new AtMostSlopeQueryCount(INF);
    }

    public function test_rejects_non_finite_tolerance(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('finite');

        new AtMostSlopeQueryCount(1, tolerance: NAN);
    }

    public function test_rejects_negative_slope(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new AtMostSlopeQueryCount(-0.5);
    }

    public function test_rejects_negative_tolerance(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new AtMostSlopeQueryCount(1, tolerance: -1);
    }

    /**
     * @param  list<int>  $scales
     * @param  list<int>  $counts
     */
    private function series(array $scales, array $counts): MeasurementSeries
    {
        $measurements = [];
        foreach ($counts as $i => $count) {
            $measurements[] = new ScaleMeasurement($scales[$i], array_fill(0, $count, new RecordedQuery('testing', 'select 1', [])));
        }

        return new MeasurementSeries($measurements);
    }
}
