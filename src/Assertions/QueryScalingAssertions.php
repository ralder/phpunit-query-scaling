<?php

declare(strict_types=1);

namespace Ralder\QueryScaling\Assertions;

use Closure;
use Illuminate\Container\Container;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Database\DatabaseManager;
use InvalidArgumentException;
use PHPUnit\Framework\ExpectationFailedException;
use Ralder\QueryScaling\Core\ConstantQueryCount;
use Ralder\QueryScaling\Core\IsolationStrategy;
use Ralder\QueryScaling\Core\Report\FailureReportRenderer;
use Ralder\QueryScaling\Core\ScaleFactors;
use Ralder\QueryScaling\Core\ScaleRunner;
use Ralder\QueryScaling\Core\ScalingExpectation;
use Ralder\QueryScaling\Laravel\LaravelQueryCollector;
use Ralder\QueryScaling\Laravel\LaravelTransactionIsolation;
use RuntimeException;

/**
 * Scaling-aware query assertions for Laravel tests (PHPUnit and Pest).
 *
 * Resolves the Laravel application from the active container instance, as
 * maintained by a booted Orchestra Testbench or Foundation TestCase. The
 * using test case must also be PHPUnit Assert-compatible because successful
 * assertions are registered with addToAssertionCount().
 */
trait QueryScalingAssertions
{
    /**
     * Assert that the measured scenario issues the same number of queries at
     * every measured dataset scale.
     *
     * @param  list<mixed>  $scales  ascending positive scale factors, at least two
     * @param  Closure(int): void  $populate  insert $scale entities (must be pure w.r.t. other state)
     * @param  Closure(): void  $run  measured scenario; executed once per scale factor
     * @param  (Closure(int): void)|null  $warmup  optional per-scale warmup; its queries are not measured
     * @param  list<mixed>|string|null  $connections  measured connections; null = default
     */
    protected function assertQueriesScaleConstantly(
        array $scales,
        Closure $populate,
        Closure $run,
        ?Closure $warmup = null,
        int $tolerance = 0,
        array|string|null $connections = null,
        ?IsolationStrategy $isolation = null,
        bool $showBindings = false,
        bool $normalizeLiterals = false,
    ): void {
        $this->assertQueryScaling(
            scales: $scales,
            populate: $populate,
            run: $run,
            expectation: new ConstantQueryCount($tolerance),
            warmup: $warmup,
            connections: $connections,
            isolation: $isolation,
            showBindings: $showBindings,
            normalizeLiterals: $normalizeLiterals,
        );
    }

    /**
     * Assert a scaling contract over controlled measurement points.
     *
     * The measured points verify the contract on exactly the measured inputs;
     * they do not prove asymptotic complexity for unbounded n.
     *
     * @param  list<mixed>  $scales  ascending positive scale factors, at least two
     * @param  Closure(int): void  $populate
     * @param  Closure(): void  $run
     * @param  (Closure(int): void)|null  $warmup
     * @param  list<mixed>|string|null  $connections
     */
    protected function assertQueryScaling(
        array $scales,
        Closure $populate,
        Closure $run,
        ScalingExpectation $expectation,
        ?Closure $warmup = null,
        array|string|null $connections = null,
        ?IsolationStrategy $isolation = null,
        bool $showBindings = false,
        bool $normalizeLiterals = false,
    ): void {
        $factors = ScaleFactors::make($scales);
        $connectionNames = $this->resolveQueryScalingConnections($connections);

        $runner = new ScaleRunner(
            new LaravelQueryCollector($this->queryScalingDispatcher(), $connectionNames),
            $isolation ?? LaravelTransactionIsolation::forConnections($this->queryScalingDatabase(), $connectionNames),
        );

        $series = $runner->run($factors, $populate, $warmup, $run, $normalizeLiterals);

        $result = $expectation->evaluate($series);

        if (! $result->passed) {
            throw new ExpectationFailedException(
                (new FailureReportRenderer)->render($series, $expectation, $result, $showBindings)
            );
        }

        self::addToAssertionCount(1);
    }

    /**
     * @param  list<mixed>|string|null  $connections
     * @return non-empty-list<string>
     */
    private function resolveQueryScalingConnections(array|string|null $connections): array
    {
        if ($connections === null) {
            $config = $this->queryScalingBinding('config');
            $default = $config instanceof ConfigRepository ? $config->get('database.default') : null;
            if (! is_string($default) || $default === '') {
                throw new RuntimeException(
                    'Cannot resolve the default database connection. Pass $connections explicitly.'
                );
            }

            return [$default];
        }

        $names = is_string($connections) ? [$connections] : $connections;

        $normalized = [];
        foreach ($names as $name) {
            if (! is_string($name) || $name === '') {
                throw new InvalidArgumentException('Every database connection name must be a non-empty string.');
            }

            $normalized[] = $name;
        }

        if ($normalized === []) {
            throw new InvalidArgumentException('At least one connection name must be given.');
        }

        return $normalized;
    }

    private function queryScalingDispatcher(): Dispatcher
    {
        $dispatcher = $this->queryScalingBinding('events');
        if (! $dispatcher instanceof Dispatcher) {
            throw new RuntimeException('The Laravel event dispatcher is not available on the application resolved from the container.');
        }

        return $dispatcher;
    }

    private function queryScalingDatabase(): DatabaseManager
    {
        $manager = $this->queryScalingBinding('db');
        if (! $manager instanceof DatabaseManager) {
            throw new RuntimeException('The Laravel database manager is not available on the application resolved from the container.');
        }

        return $manager;
    }

    /**
     * Resolve a container binding through the contracts interface (no offset
     * access), so the trait depends only on illuminate/contracts.
     */
    private function queryScalingBinding(string $abstract): mixed
    {
        $application = $this->queryScalingApplication();

        return $application->bound($abstract) ? $application->make($abstract) : null;
    }

    private function queryScalingApplication(): Application
    {
        // In a Laravel test context the application is the active container.
        // The runtime check makes a misapplied trait fail with an actionable
        // message instead of an engine error.
        $container = Container::getInstance();
        if (! $container instanceof Application) {
            throw new RuntimeException(
                'QueryScalingAssertions requires a booted Laravel application as the active container. '
                .'Use the trait in a class extending a Laravel-aware TestCase (Orchestra Testbench or Illuminate\\Foundation\\Testing\\TestCase).'
            );
        }

        return $container;
    }
}
