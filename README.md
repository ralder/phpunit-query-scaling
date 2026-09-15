# ralder/phpunit-query-scaling

Controlled multi-scale **query-count assertions** for Laravel tests (PHPUnit and Pest).

Instead of asserting *"this scenario runs at most N queries"* — a number nobody can defend six
months later — this package measures your scenario at **several dataset scales** inside real
database transactions and asserts a contract on **how the query count changes**:

- `assertQueriesScaleConstantly(...)` — the scenario issues the **same** number of queries
  whether the dataset holds 2 or 10 entities (eager loading done right), or
- `assertQueryScaling(..., expectation: QueryGrowth::atMostSlope(1, 0))` — the count may grow,
  but **no faster than one query per added item** on every measured segment.

When a contract is violated, every SQL fingerprint that grew across the scales is reported, so
the N+1 query (or worse) is found without digging through logs.

## Installation

```bash
composer require --dev ralder/phpunit-query-scaling
```

Requirements: PHP 8.3+, Laravel 12/13, PHPUnit 12/13 or Pest 4/5
(the CI matrix verifies Laravel ^12/^13 × PHP 8.3–8.5, plus a separate PHPUnit 13 / Pest 5 job —
support claims reflect the verified CI matrix, including minimum dependency versions).

## Usage

### PHPUnit

```php
use PHPUnit\Framework\TestCase;
use Ralder\QueryScaling\Assertions\QueryScalingAssertions;
use Ralder\QueryScaling\QueryGrowth;

final class PostIndexQueryTest extends TestCase
{
    use QueryScalingAssertions;

    public function test_index_scales_constantly(): void
    {
        $this->assertQueriesScaleConstantly(
            scales: [2, 5, 10],

            // Populate $scale rows on EVERY scale run; runs against a clean dataset.
            populate: function (int $scale): void {
                Post::factory()->count($scale)->hasComments(2)->create();
            },

            // The measured scenario; exactly $run() queries are counted.
            run: function (): void {
                $posts = Post::with('comments')->get();

                $this->assertTrue($posts->every->comments->isNotEmpty());
            },
        );
    }

    public function test_comment_counts_scale_linearly_at_most(): void
    {
        $this->assertQueryScaling(
            scales: [2, 5, 10],
            expectation: QueryGrowth::atMostSlope(1, 0),
            populate: function (int $scale): void {
                Post::factory()->count($scale)->create();
            },
            run: function (): void {
                // one lookup query per post is acceptable; two per post is not
                Post::all()->each->comments->count();
            },
        );
    }
}
```

### Pest

```php
use Ralder\QueryScaling\Assertions\QueryScalingAssertions;
use Ralder\QueryScaling\QueryGrowth;

uses(Tests\TestCase::class, QueryScalingAssertions::class);

test('index scales constantly', function (): void {
    $this->assertQueriesScaleConstantly(
        scales: [2, 5, 10],
        populate: fn (int $scale) => Post::factory()->count($scale)->create(),
        run: function (): void {
            $this->getJson('/api/posts')->assertSuccessful();
        },
    );
});
```

The trait works with any Laravel-aware test case: Orchestra Testbench and
`Illuminate\Foundation\Testing\TestCase` alike.

## What happens when it detects growth

A failing assertion throws PHPUnit's `ExpectationFailedException` with a deterministic,
CI-friendly report:

```
Query scaling assertion failed

Expected:
  query count to remain constant

Measurements:

Scale  Queries
2      3
5      6
10     11

Violated growth segments:
  scale 2 -> 5: 3 -> 6 queries (+3 observed, +0 allowed)
  scale 5 -> 10: 6 -> 11 queries (+5 observed, +0 allowed)

Observed diagnostic trend:
  approximately Q(n) = 1 + 1n over the measured scales
  (empirical fit over the measured checkpoints, not an asymptotic complexity proof)

Growing query fingerprints:

1. select * from "comments" where "comments"."post_id" = ? and ...
   Scale  Occurrences
   2      2
   5      5
   10     10
   Connection: mysql
```

The trend line is a purely diagnostic least-squares fit over the measured checkpoints. No
package can (and this one does not) prove an asymptotic complexity class from measurements:
**the assertion verifies the contract at the measured scale factors only.**

## Expectations

| Factory | Passes when |
| --- | --- |
| `QueryGrowth::constant(int $tolerance = 0)` | `max(queries) − min(queries) ≤ tolerance` |
| `QueryGrowth::atMostSlope(float $maxSlope, float $tolerance = 0)` | every consecutive segment satisfies `queries(i+1) − queries(i) ≤ maxSlope × (scale(i+1) − scale(i)) + tolerance`; decreasing segments always pass |

- `QueryGrowth::constant(0)` **is not** `QueryGrowth::atMostSlope(0)`: the constant contract
  tolerates no upward segment at all, while `atMostSlope(0)` tolerates decreases.
- Tolerance is a deliberate safety valve (cache state, statistics-dependent plans), documented
  in `summary()` and reported in failures.

## API reference

```php
protected function assertQueriesScaleConstantly(
    array $scales,                    // ascending positive ints, at least two: [2, 5], [2, 5, 10], ...
    Closure(int): void $populate,     // populate $scale entities; runs off-meter
    Closure(): void $run,             // measured scenario
    ?Closure(int): void $warmup = null,        // optional per-scale warmup; runs off-meter
    int $tolerance = 0,                        // absolute query-count spread allowed
    array|string|null $connections = null,     // measured connections; null = the default connection
    ?IsolationStrategy $isolation = null,      // custom isolation override
    bool $showBindings = false,                // print ≤3 example bindings per growing fingerprint
    bool $normalizeLiterals = false,           // opt-in literal folding in fingerprints
): void;

protected function assertQueryScaling(
    array $scales,
    Closure(int): void $populate,
    Closure(): void $run,
    ScalingExpectation $expectation,    // QueryGrowth::constant() / atMostSlope() or your own
    ?Closure(int): void $warmup = null,
    array|string|null $connections = null,
    ?IsolationStrategy $isolation = null,
    bool $showBindings = false,
    bool $normalizeLiterals = false,
): void;
```

`assertQueryScaling()` receives its tolerance through the expectation factory,
for example `QueryGrowth::constant(tolerance: 1)` or
`QueryGrowth::atMostSlope(maxSlope: 1, tolerance: 1)`. Prefer named arguments
when calling either assertion so optional parameters remain unambiguous.

Invalid scale lists (unsorted, duplicates, fewer than two factors, non-positive factors) are
rejected with `InvalidArgumentException` **before** any database work happens.

## How isolation works

For every scale factor the package:

1. records the current transaction depth of each measured connection,
2. begins one nested transaction (savepoint) inside it,
3. runs `populate($scale)`, optional `warmup($scale)` (unmeasured), then measures `run()`,
4. restores the transaction depth — usually by rolling back — **even if your callbacks throw**.

If your `run()` callback committed or rolled back transactions outside its own scope (or issued
implicit-commit DDL on MySQL), the restore step throws `IsolationViolationException`, explaining
the observed depth and connection. The package fails loudly instead of pretending the
environment is clean.

A callback that commits the isolation transaction and then opens a new one — ending at exactly
the measured depth — is caught the same way: the package watches the connection's transaction
lifecycle events and reports the breach instead of silently rolling back the replacement
transaction. Watching those events requires an event dispatcher on the connection (the normal
Laravel setup); without one, restore() falls back to transaction-depth checks, which cannot see
a balanced commit-and-re-open.

**Isolation is limited to the database connection.** Redis, the filesystem, queues, HTTP calls
are not isolated — keep your `run()` callback idempotent with respect to them. This limitation
is contractual; it will not change silently with new isolation strategies.

`populate()` and `warmup()` run in the same isolation scope; they receive the scale factor and
must be pure functions of it (only populate the requested amount, no external side effects).

**SQLite note:** in-memory test databases are perfectly fine. Real `FOREIGN` keys and
savepoints work with the transaction driver.

## Multi-connection measurement

```php
$this->assertQueriesScaleConstantly(
    scales: [2, 5, 10],
    populate: /* writes to both connections */,
    run: fn () => $this->getJson('/api/dashboard'),
    connections: ['mysql', 'tenant'],   // measure exactly these
);
```

## Query fingerprints

A fingerprint is `{connection, normalized SQL}` where normalization is conservative: trim +
whitespace collapse. Two statements count as the same fingerprint iff they are identical on the
same connection. Parameter values never change the fingerprint.

Opt-in `normalizeLiterals: true` additionally folds string and numeric literals into `?`. It is
a heuristic (useful when Eloquent is bypassed and raw SQL interpolates values) and is off by
default; report the SQL you actually saw before enabling it.

## Custom isolation

Provide your own `Ralder\QueryScaling\Core\IsolationStrategy` implementation (e.g. schema
dumps or read-only connections) and pass it as the `isolation:` argument. Throw
`Ralder\QueryScaling\Core\IsolationViolationException` when your strategy detects a callback
broke its invariants — the framework will surface it instead of the measurement result.

## What this package is not

- Not a *count budget* or *threshold* tool — it measures growth across scale factors.
- Not an asymptotic complexity detector — measured points support empirical contracts, not
  O() proofs.
- Not a replacement for query logging during debugging; use `DB::listen()`/Telescope for that.
- Not a machine-readable report format (yet): failures are rendered as deterministic, aligned
  text on the PHPUnit exception message. There is no JSON/structured payload in 0.1.0 — parse
  the text, or open an issue if you need a structured report for CI tooling.

## Compatibility

The supported combinations are the ones exercised by the repository's CI workflow:

| PHP | Laravel | Testbench | PHPUnit / Pest | CI coverage |
| --- | --- | --- | --- | --- |
| 8.3, 8.4, 8.5 | ^12 | ^10 | PHPUnit 12 / Pest 4 | standard matrix; PHP 8.3 also uses `prefer-lowest` |
| 8.3, 8.4, 8.5 | ^13 | ^11 | PHPUnit 12 / Pest 4 | standard matrix |
| 8.5 | ^13 | ^11 | PHPUnit 13 / Pest 5 | dedicated compatibility job |

## Warmup

Pass `warmup: fn (int $scale) => ...` when a route, container, metadata cache, or other
read-only application state should be initialized before measurement. Warmup runs once per
scale after `populate`, with the collector disabled, and its queries are not counted. It runs
inside the same isolation scope and **must not mutate the dataset**; use `populate` for all
scale-dependent data setup.

## Troubleshooting

- **`IsolationViolationException`:** the callback committed or rolled back beyond its scope,
  left the physical transaction unavailable, or ran implicit-commit DDL such as MySQL
  `CREATE TABLE`/`ALTER TABLE`. Keep transaction ownership inside the assertion scope and move
  schema changes to setup/migrations.
- **Overlapping measurement `LogicException`:** a scaling assertion was started from inside
  another measured callback. Move the nested assertion outside the callback.
- **Misapplied-trait `RuntimeException`:** use `QueryScalingAssertions` on a booted
  Laravel-aware PHPUnit/Pest test case (Orchestra Testbench or Foundation TestCase).
- **Cannot resolve the default database connection:** pass `connections: 'connection-name'`
  or `connections: ['primary', 'tenant']` explicitly, and ensure the Laravel database config
  has a non-empty `database.default` value.

## Development

```bash
composer test           # PHPUnit suites (Unit + Integration on Testbench)
composer test:pest      # Pest smoke suite
composer analyse        # PHPStan level max
composer format:test    # Pint (check)
```

CI runs Laravel ^12/^13 × PHP 8.3/8.4/8.5 (including `prefer-lowest`) and a separate
PHPUnit 13 / Pest 5 job on PHP 8.5.

## License

MIT. See [LICENSE.md](LICENSE.md).
