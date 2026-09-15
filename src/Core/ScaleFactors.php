<?php

declare(strict_types=1);

namespace Ralder\QueryScaling\Core;

use Countable;
use InvalidArgumentException;
use IteratorAggregate;
use Traversable;

/**
 * Validated, strictly ascending list of scale factors.
 *
 * @implements IteratorAggregate<int, int>
 */
final class ScaleFactors implements Countable, IteratorAggregate
{
    /** @var non-empty-list<int> */
    private readonly array $scales;

    /**
     * @param  non-empty-list<int>  $scales
     */
    private function __construct(array $scales)
    {
        $this->scales = $scales;
    }

    /**
     * @param  array<int|string, mixed>  $scales
     */
    public static function make(array $scales): self
    {
        $values = array_values($scales);

        /** @var list<int> $ints */
        $ints = [];
        foreach ($values as $value) {
            if (! is_int($value)) {
                throw new InvalidArgumentException(
                    'Scale factors must be positive integers; got '.var_export($value, true).'.'
                );
            }
            if ($value < 1) {
                throw new InvalidArgumentException(
                    "Scale factors must be positive integers; got {$value}."
                );
            }
            $ints[] = $value;
        }

        if (count($ints) < 2) {
            throw new InvalidArgumentException(
                'Scale factors must contain at least two values, e.g. [2, 5, 10]; a single point cannot describe growth.'
            );
        }

        if (count(array_unique($ints)) !== count($ints)) {
            throw new InvalidArgumentException('Scale factors must be unique; duplicates describe the same scale.');
        }

        for ($i = 1, $n = count($ints); $i < $n; $i++) {
            if ($ints[$i] <= $ints[$i - 1]) {
                throw new InvalidArgumentException(
                    'Scale factors must be given in strictly ascending order (e.g. [2, 5, 10]); sorting is not done implicitly.'
                );
            }
        }

        return new self($ints);
    }

    /**
     * @return non-empty-list<int>
     */
    public function toArray(): array
    {
        return $this->scales;
    }

    public function count(): int
    {
        return count($this->scales);
    }

    /**
     * @return Traversable<int, int>
     */
    public function getIterator(): Traversable
    {
        yield from $this->scales;
    }
}
