<?php

declare(strict_types=1);

namespace Ralder\QueryScaling\Tests\Unit;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Ralder\QueryScaling\Core\EvaluationResult;
use Ralder\QueryScaling\Core\ScalingViolation;

final class EvaluationResultTest extends TestCase
{
    public function test_passed_result_carries_no_violations(): void
    {
        $result = EvaluationResult::passed();

        $this->assertTrue($result->passed);
        $this->assertSame([], $result->violations);
    }

    public function test_failed_result_carries_its_violations(): void
    {
        $violation = new ScalingViolation(
            fromScale: 2,
            toScale: 4,
            fromCount: 1,
            toCount: 3,
            observedDelta: 2.0,
            allowedDelta: 0.0,
        );

        $result = EvaluationResult::failed([$violation]);

        $this->assertFalse($result->passed);
        $this->assertSame([$violation], $result->violations);
    }

    public function test_failed_result_without_violations_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('at least one scaling violation');

        EvaluationResult::failed([]);
    }
}
