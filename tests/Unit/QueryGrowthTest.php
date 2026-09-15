<?php

declare(strict_types=1);

namespace Ralder\QueryScaling\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Ralder\QueryScaling\Core\AtMostSlopeQueryCount;
use Ralder\QueryScaling\Core\ConstantQueryCount;
use Ralder\QueryScaling\QueryGrowth;

final class QueryGrowthTest extends TestCase
{
    public function test_constant_factory(): void
    {
        $expectation = QueryGrowth::constant(2);

        $this->assertInstanceOf(ConstantQueryCount::class, $expectation);
        $this->assertSame('query count to remain constant (tolerance: 2)', $expectation->summary());
    }

    public function test_at_most_slope_factory_accepts_ints(): void
    {
        $expectation = QueryGrowth::atMostSlope(2, 1);

        $this->assertInstanceOf(AtMostSlopeQueryCount::class, $expectation);
        $this->assertSame('query count to grow at most 2 per additional item (tolerance: 1)', $expectation->summary());
    }
}
