<?php

declare(strict_types=1);

namespace Ralder\QueryScaling\Core;

/**
 * One violated growth segment between two measured scale factors.
 */
final class ScalingViolation
{
    public function __construct(
        public readonly int $fromScale,
        public readonly int $toScale,
        public readonly int $fromCount,
        public readonly int $toCount,
        public readonly float $observedDelta,
        public readonly float $allowedDelta,
    ) {}
}
