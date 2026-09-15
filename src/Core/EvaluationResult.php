<?php

declare(strict_types=1);

namespace Ralder\QueryScaling\Core;

use InvalidArgumentException;

final class EvaluationResult
{
    /**
     * @param  list<ScalingViolation>  $violations
     */
    private function __construct(
        public readonly bool $passed,
        public readonly array $violations,
    ) {}

    public static function passed(): self
    {
        return new self(true, []);
    }

    /**
     * A failed result always carries the reason it failed.
     *
     * @param  list<ScalingViolation>  $violations  at least one violation; an empty list is rejected
     *
     * @throws InvalidArgumentException when no violations are given
     */
    public static function failed(array $violations): self
    {
        if ($violations === []) {
            throw new InvalidArgumentException(
                'A failed evaluation result needs at least one scaling violation; use passed() for a passing result.'
            );
        }

        return new self(false, $violations);
    }
}
