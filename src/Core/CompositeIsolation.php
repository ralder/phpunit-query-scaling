<?php

declare(strict_types=1);

namespace Ralder\QueryScaling\Core;

use Throwable;

/**
 * Composes several strategies into one. begin() runs in order, restore()
 * runs in reverse order. If begin() fails partway, already-initialized
 * strategies are restored on a best-effort basis.
 */
final class CompositeIsolation implements IsolationStrategy
{
    /** @var non-empty-list<IsolationStrategy> */
    private array $strategies;

    /**
     * @param  non-empty-list<IsolationStrategy>  $strategies
     */
    public function __construct(array $strategies)
    {
        $this->strategies = $strategies;
    }

    public function begin(): void
    {
        $begun = [];

        try {
            foreach ($this->strategies as $strategy) {
                $strategy->begin();
                $begun[] = $strategy;
            }
        } catch (Throwable $e) {
            foreach (array_reverse($begun) as $strategy) {
                try {
                    $strategy->restore();
                } catch (Throwable) {
                    // best-effort cleanup; the original failure wins
                }
            }

            throw $e;
        }
    }

    public function restore(): void
    {
        $firstFailure = null;

        foreach (array_reverse($this->strategies) as $strategy) {
            try {
                $strategy->restore();
            } catch (Throwable $e) {
                $firstFailure ??= $e;
            }
        }

        if ($firstFailure !== null) {
            throw $firstFailure;
        }
    }
}
