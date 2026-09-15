<?php

declare(strict_types=1);

namespace Ralder\QueryScaling\Tests\Integration;

use Closure;
use Illuminate\Database\Schema\Blueprint;
use InvalidArgumentException;
use PHPUnit\Framework\ExpectationFailedException;
use Ralder\QueryScaling\Assertions\QueryScalingAssertions;
use Ralder\QueryScaling\Core\IsolationStrategy;
use Ralder\QueryScaling\QueryGrowth;
use Ralder\QueryScaling\Tests\Integration\Fixtures\Post;
use RuntimeException;

final class QueryScalingAssertionsTest extends TestCase
{
    use QueryScalingAssertions;

    protected function setUp(): void
    {
        parent::setUp();

        $this->createBlogTables();
    }

    // ------------------------------------------------------------------
    // Required scenario 1: eager loading passes the constant contract
    // ------------------------------------------------------------------

    public function test_eager_loaded_scenario_scales_constantly(): void
    {
        $this->assertQueriesScaleConstantly(
            scales: [2, 5, 10],
            populate: $this->blogPopulate(),
            run: function (): void {
                Post::with('comments')->get()->each(static function (Post $post): void {
                    $post->comments->count();
                });
            },
        );
    }

    // ------------------------------------------------------------------
    // Required scenario 2: classic N+1 fails with the fingerprint report
    // ------------------------------------------------------------------

    public function test_classic_n_plus_one_fails_and_reports_growing_fingerprint(): void
    {
        try {
            $this->assertQueriesScaleConstantly(
                scales: [2, 5, 10],
                populate: $this->blogPopulate(),
                run: function (): void {
                    Post::query()->get()->each(static function (Post $post): void {
                        $post->comments()->count();
                    });
                },
            );
        } catch (ExpectationFailedException $e) {
            $message = $e->getMessage();
            $this->assertStringContainsString('query count to remain constant', $message);
            $this->assertStringContainsString('Growing query fingerprints', $message);
            $this->assertStringContainsString('from "comments" where "comments"."post_id" = ?', $message);
            $this->assertStringContainsString('Connection: testing', $message);

            return;
        }

        $this->fail('Expected ExpectationFailedException for N+1 scenario.');
    }

    // ------------------------------------------------------------------
    // Required scenarios 4 and 5: slope contracts
    // ------------------------------------------------------------------

    public function test_linear_growth_passes_at_slope_one(): void
    {
        $this->assertQueryScaling(
            scales: [2, 5, 10],
            populate: $this->blogPopulate(),
            expectation: QueryGrowth::atMostSlope(1, 0),
            run: function (): void {
                Post::query()->get()->each(static function (Post $post): void {
                    $post->comments()->count();
                });
            },
        );
    }

    public function test_linear_growth_fails_at_slope_zero(): void
    {
        $this->expectException(ExpectationFailedException::class);

        $this->assertQueryScaling(
            scales: [2, 5, 10],
            populate: $this->blogPopulate(),
            expectation: QueryGrowth::atMostSlope(0, 0),
            run: function (): void {
                Post::query()->get()->each(static function (Post $post): void {
                    $post->comments()->count();
                });
            },
        );
    }

    // ------------------------------------------------------------------
    // Required scenario 6: population queries are not measured
    // ------------------------------------------------------------------

    public function test_population_queries_are_not_measured(): void
    {
        // populate issues ~3*scale INSERTs; if any leaked into the
        // measurement, this constant assertion could not hold.
        $this->assertQueriesScaleConstantly(
            scales: [2, 5, 10],
            populate: $this->blogPopulate(),
            run: static fn () => null,
        );
    }

    // ------------------------------------------------------------------
    // Required scenario 7: warmup is executed per scale and not measured
    // ------------------------------------------------------------------

    public function test_warmup_queries_are_not_measured(): void
    {
        $warmupCalls = 0;

        $this->assertQueriesScaleConstantly(
            scales: [2, 5, 10],
            populate: $this->blogPopulate(),
            warmup: function () use (&$warmupCalls): void {
                $warmupCalls++;
                // deliberately N+1 in warmup; if counted, the contract would fail
                Post::query()->get()->each(static function (Post $post): void {
                    $post->comments()->count();
                });
            },
            run: static fn () => null,
        );

        $this->assertSame(3, $warmupCalls);
    }

    // ------------------------------------------------------------------
    // Required scenario 8: population data does not leak between runs
    // ------------------------------------------------------------------

    public function test_scale_runs_do_not_leak_population_data(): void
    {
        $observedRowCounts = [];

        $this->assertQueriesScaleConstantly(
            scales: [2, 5, 10],
            populate: $this->blogPopulate(),
            run: function () use (&$observedRowCounts): void {
                $observedRowCounts[] = Post::query()->count();
            },
        );

        $this->assertSame([2, 5, 10], $observedRowCounts);
        $this->assertSame(0, Post::query()->count(), 'no population data survives the assertion');
    }

    // ------------------------------------------------------------------
    // Required scenario 9: works inside an open test transaction
    // ------------------------------------------------------------------

    public function test_works_inside_an_already_open_transaction(): void
    {
        $db = $this->db();
        $db->connection()->beginTransaction();
        $this->assertSame(1, $db->connection()->transactionLevel());

        try {
            $this->assertQueriesScaleConstantly(
                scales: [2, 5],
                populate: $this->blogPopulate(),
                run: static function (): void {
                    Post::query()->count();
                },
            );

            $this->assertSame(1, $db->connection()->transactionLevel());
            $this->assertSame(0, $db->connection()->table('posts')->count(), 'scale data must not leak into the test transaction');
        } finally {
            $db->connection()->rollBack();
        }
    }

    // ------------------------------------------------------------------
    // Required scenario 10: exception in populate still restores
    // ------------------------------------------------------------------

    public function test_exception_in_populate_restores_state_and_propagates(): void
    {
        $invoke = 0;

        try {
            $this->assertQueriesScaleConstantly(
                scales: [2, 5],
                populate: function (int $scale) use (&$invoke): void {
                    $invoke++;
                    ($this->blogPopulate())($scale);
                    if ($scale === 5) {
                        throw new RuntimeException('populate failed at scale 5');
                    }
                },
                run: static fn () => null,
            );
            $this->fail('Expected RuntimeException');
        } catch (RuntimeException $e) {
            $this->assertSame('populate failed at scale 5', $e->getMessage());
        }

        $this->assertSame(0, $this->db()->connection()->transactionLevel());
        $this->assertSame(0, Post::query()->count(), 'data from the completed scale-2 run must be rolled back');
    }

    // ------------------------------------------------------------------
    // Required scenario 11: exception in run still restores and deactivates
    // ------------------------------------------------------------------

    public function test_exception_in_run_restores_state_and_a_subsequent_assertion_works(): void
    {
        try {
            $this->assertQueriesScaleConstantly(
                scales: [2, 5],
                populate: $this->blogPopulate(),
                run: function (): void {
                    Post::query()->count();
                    if (Post::query()->count() === 5) {
                        throw new RuntimeException('run failed at scale 5');
                    }
                },
            );
            $this->fail('Expected RuntimeException');
        } catch (RuntimeException $e) {
            $this->assertSame('run failed at scale 5', $e->getMessage());
        }

        $this->assertSame(0, $this->db()->connection()->transactionLevel());
        $this->assertSame(0, Post::query()->count());

        // collector and isolation were deactivated: another assertion works
        $this->assertQueriesScaleConstantly(
            scales: [2, 5],
            populate: $this->blogPopulate(),
            run: static fn () => null,
        );
    }

    // ------------------------------------------------------------------
    // Required scenario 12: repeated assertions do not mix measurements
    // ------------------------------------------------------------------

    public function test_repeated_assertions_in_one_test_do_not_mix_measurements(): void
    {
        foreach ([1, 2] as $round) {
            $this->assertQueriesScaleConstantly(
                scales: [2, 5],
                populate: $this->blogPopulate(),
                run: static function (): void {
                    Post::query()->count();
                },
            );
        }

        $this->addToAssertionCount(2);
    }

    // ------------------------------------------------------------------
    // Required scenario 14: custom isolation strategy
    // ------------------------------------------------------------------

    public function test_custom_isolation_strategy_is_used_per_scale(): void
    {
        $isolation = new class implements IsolationStrategy
        {
            public int $begins = 0;

            public int $restores = 0;

            public function begin(): void
            {
                $this->begins++;
            }

            public function restore(): void
            {
                $this->restores++;
            }
        };

        $this->assertQueriesScaleConstantly(
            scales: [2, 5, 10],
            populate: static fn (int $scale) => null, // no DB writes: fake isolation is enough here
            run: static fn () => null,
            isolation: $isolation,
        );

        $this->assertSame(3, $isolation->begins);
        $this->assertSame(3, $isolation->restores);
    }

    // ------------------------------------------------------------------
    // Required scenario 17: invalid scale lists are rejected before DB work
    // ------------------------------------------------------------------

    public function test_invalid_scale_lists_fail_before_any_population(): void
    {
        $populateWasCalled = false;
        $populate = function () use (&$populateWasCalled): void {
            $populateWasCalled = true;
        };

        foreach ([[5, 5], [10, 5], [0, 10], [-1, 5], [10]] as $scales) {
            try {
                $this->assertQueriesScaleConstantly($scales, $populate, static fn () => null);
                $this->fail('scales '.json_encode($scales).' must be rejected');
            } catch (InvalidArgumentException) {
            }
        }

        $this->assertFalse($populateWasCalled, 'validation must happen before any database work');
    }

    // ------------------------------------------------------------------
    // Required scenario 19: tolerance changes only the allowed slack
    // ------------------------------------------------------------------

    public function test_constant_tolerance_admits_spread_within_the_threshold(): void
    {
        $populate = $this->blogPopulate();
        $run = function (): void {
            Post::query()->count();
            Post::query()->count();
            if (Post::query()->count() >= 10) {
                Post::query()->count(); // one extra query at the largest scale
            }
        };

        // strict contract fails: counts are [2, 2, 3]
        try {
            $this->assertQueriesScaleConstantly(scales: [2, 5, 10], populate: $populate, run: $run);
            $this->fail('strict constant contract should fail for counts [2, 2, 3]');
        } catch (ExpectationFailedException) {
        }

        // tolerance 1 passes
        $this->assertQueriesScaleConstantly(scales: [2, 5, 10], populate: $populate, run: $run, tolerance: 1);

        // slope contract with tolerance covers the same scenario
        $this->assertQueryScaling(
            scales: [2, 5, 10],
            populate: $populate,
            expectation: QueryGrowth::atMostSlope(0, 1),
            run: $run,
        );
    }

    // ------------------------------------------------------------------
    // Explicit multi-connection measurement
    // ------------------------------------------------------------------

    public function test_invalid_connection_names_fail_before_population(): void
    {
        foreach ([[''], ['testing', '']] as $connections) {
            $populateWasCalled = false;

            try {
                $this->assertQueriesScaleConstantly(
                    scales: [2, 5],
                    populate: function () use (&$populateWasCalled): void {
                        $populateWasCalled = true;
                    },
                    run: static function (): void {},
                    connections: $connections,
                );
                $this->fail('Expected an invalid connection name to be rejected.');
            } catch (InvalidArgumentException) {
                $this->assertFalse($populateWasCalled);
            }
        }
    }

    public function test_show_bindings_are_included_in_a_failure_report_when_requested(): void
    {
        try {
            $this->assertQueriesScaleConstantly(
                scales: [2, 5, 10],
                populate: $this->blogPopulate(),
                run: static function (): void {
                    Post::query()->get()->each(static function (Post $post): void {
                        Post::query()->where('id', $post->id)->first();
                    });
                },
                showBindings: true,
            );
            $this->fail('Expected a growing query report.');
        } catch (ExpectationFailedException $e) {
            $this->assertStringContainsString('Example bindings:', $e->getMessage());
        }
    }

    public function test_explicit_connections_limit_measurement(): void
    {
        $this->createWidgetsTable('secondary');

        // N+1 lives on the secondary connection only; measuring just the
        // default connection sees a constant scenario.
        $this->assertQueriesScaleConstantly(
            scales: [2, 5, 10],
            populate: $this->blogPopulate(),
            connections: 'testing',
            run: function (): void {
                $n = Post::query()->count();
                $secondary = $this->db()->connection('secondary');
                for ($i = 0; $i < $n; $i++) {
                    $secondary->select('select * from widgets where id = ?', [$i + 1]);
                }
            },
        );

        // measuring the secondary connection exposes the growth
        $this->expectException(ExpectationFailedException::class);
        $this->assertQueriesScaleConstantly(
            scales: [2, 5, 10],
            populate: $this->blogPopulate(),
            connections: 'secondary',
            run: function (): void {
                $n = Post::query()->count();
                $secondary = $this->db()->connection('secondary');
                for ($i = 0; $i < $n; $i++) {
                    $secondary->select('select * from widgets where id = ?', [$i + 1]);
                }
            },
        );
    }

    // ------------------------------------------------------------------

    /**
     * Insert $scale posts, two comments each, via mass INSERTs so population
     * cost stays roughly proportional to the dataset but is never measured.
     */
    private function blogPopulate(): Closure
    {
        return function (int $scale): void {
            $db = $this->db()->connection();

            for ($i = 1; $i <= $scale; $i++) {
                $db->table('posts')->insert(['title' => "post-{$i}"]);
                $postId = (int) $db->getPdo()->lastInsertId();
                $db->table('comments')->insert([
                    ['post_id' => $postId, 'body' => "a{$i}"],
                    ['post_id' => $postId, 'body' => "b{$i}"],
                ]);
            }
        };
    }

    private function createBlogTables(): void
    {
        $schema = $this->db()->connection()->getSchemaBuilder();
        $schema->create('posts', static function (Blueprint $table): void {
            $table->increments('id');
            $table->string('title');
        });
        $schema->create('comments', static function (Blueprint $table): void {
            $table->increments('id');
            $table->unsignedInteger('post_id');
            $table->string('body');
        });
    }
}
