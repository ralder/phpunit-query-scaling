<?php

declare(strict_types=1);

namespace Ralder\QueryScaling\Tests\Unit;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Ralder\QueryScaling\Core\ScaleFactors;

final class ScaleFactorsTest extends TestCase
{
    public function test_accepts_valid_ascending_distinct_factors(): void
    {
        $factors = ScaleFactors::make([2, 5, 10]);

        $this->assertSame([2, 5, 10], $factors->toArray());
        $this->assertCount(3, $factors);
    }

    public function test_two_factors_are_the_minimum(): void
    {
        $factors = ScaleFactors::make([1, 2]);

        $this->assertSame([1, 2], $factors->toArray());
    }

    public function test_rejects_fewer_than_two_factors(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('at least two');

        ScaleFactors::make([5]);
    }

    public function test_rejects_empty_list(): void
    {
        $this->expectException(InvalidArgumentException::class);

        ScaleFactors::make([]);
    }

    public function test_rejects_duplicates(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('unique');

        ScaleFactors::make([2, 5, 5]);
    }

    public function test_rejects_zero(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('positive');

        ScaleFactors::make([0, 5]);
    }

    public function test_rejects_negative_values(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('positive');

        ScaleFactors::make([-3, 5]);
    }

    public function test_rejects_unsorted_input(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('ascending');

        ScaleFactors::make([10, 2, 5]);
    }

    public function test_rejects_non_integer_values(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('integer');

        ScaleFactors::make([2, 2.5, 10]);
    }

    public function test_iterates_in_order(): void
    {
        $factors = ScaleFactors::make([2, 5, 10]);

        $seen = [];
        foreach ($factors as $scale) {
            $seen[] = $scale;
        }

        $this->assertSame([2, 5, 10], $seen);
    }
}
