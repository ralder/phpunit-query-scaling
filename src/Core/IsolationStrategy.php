<?php

declare(strict_types=1);

namespace Ralder\QueryScaling\Core;

/**
 * Isolates one scale run and discards everything it persisted.
 *
 * Contracts:
 *  - begin() establishes an isolation scope;
 *  - restore() discards the scope and returns the environment to the state
 *    observed by begin(), even after failures;
 *  - restore() throws IsolationViolationException when the callback destroyed
 *    the expected structure (e.g. committed the surrounding transaction) and
 *    safe recovery is impossible.
 */
interface IsolationStrategy
{
    public function begin(): void;

    /**
     * @throws IsolationViolationException
     */
    public function restore(): void;
}
