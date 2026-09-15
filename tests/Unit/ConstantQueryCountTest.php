<?php

declare(strict_types=1);

namespace Ralder\QueryScaling\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Ralder\QueryScaling\Core\ConstantQueryCount;
use Ralder\QueryScaling\Core\MeasurementSeries;
use Ralder\QueryScaling\Core\RecordedQuery;
use Ralder\QueryScaling\Core\ScaleMeasurement;

final class ConstantQueryCountTest extends TestCase
{
    public function test_passes_when_counts_are_equal(): void
    {
        $result = (new ConstantQueryCount)->evaluate($this->series([4, 4, 4]));

        $this->assertTrue($result->passed);
        $this->assertSame([], $result->violations);
    }

    public function test_fails_when_counts_grow(): void
    {
        $result = (new ConstantQueryCount)->evaluate($this->series([4, 7, 12]));

        $this->assertFalse($result->passed);
        $this->assertNotEmpty($result->violations);
    }

    public function test_fails_when_counts_shrink(): void
    {
        $result = (new ConstantQueryCount)->evaluate($this->series([12, 7, 4]));

        $this->assertFalse($result->passed);
    }

    public function test_tolerance_allows_small_band(): void
    {
        $result = (new ConstantQueryCount(tolerance: 2))->evaluate($this->series([4, 5, 6]));

        $this->assertTrue($result->passed);
    }

    public function test_band_wider_than_tolerance_fails_even_without_single_pair_exceeding_it(): void
    {
        // pairs: +1, +1 within tolerance 1, but the band is 2 > 1
        $result = (new ConstantQueryCount(tolerance: 1))->evaluate($this->series([4, 5, 6]));

        $this->assertFalse($result->passed);
        $this->assertNotEmpty($result->violations);
    }

    public function test_violations_carry_scale_and_count_context(): void
    {
        $result = (new ConstantQueryCount)->evaluate($this->series([4, 7, 12]));

        $violation = $result->violations[0];
        $this->assertSame(2, $violation->fromScale);
        $this->assertSame(5, $violation->toScale);
        $this->assertSame(4, $violation->fromCount);
        $this->assertSame(7, $violation->toCount);
    }

    public function test_summary_names_the_contract(): void
    {
        $this->assertSame('query count to remain constant', (new ConstantQueryCount)->summary());
        $this->assertSame('query count to remain constant (tolerance: 1)', (new ConstantQueryCount(1))->summary());
    }

    public function test_rejects_negative_tolerance(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new ConstantQueryCount(-1);
    }

    /** @param list<int> $counts */
    private function series(array $counts): MeasurementSeries
    {
        $scales = [2, 5, 10];
        $measurements = [];
        foreach ($counts as $i => $count) {
            $measurements[] = new ScaleMeasurement($scales[$i], array_fill(0, $count, new RecordedQuery('testing', 'select 1', [])));
        }

        return new MeasurementSeries($measurements);
    }
}
