# Design: phpunit-query-scaling (MVP) — approved 2026-09-15

> Requirements source: `input.md`. This document is the approved design baseline for the MVP.

## Package identity

- Repository: `phpunit-query-scaling`
- Composer package: `ralder/phpunit-query-scaling`
- PHP namespace: `Ralder\QueryScaling`
- License: MIT
- Current year context: 2026

## Supported versions

| Component | Support | Rationale |
|---|---|---|
| PHP | 8.3, 8.4, 8.5 | 8.3 is the floor for PHPUnit 12 / Laravel 13; 8.5 current stable |
| Laravel (illuminate/*) | ^12.0 / ^13.0 | 12 security fixes until 2027-02; 13 current major |
| PHPUnit | ^12.0 core; 13.x in CI on PHP 8.4/8.5 | PHPUnit 13 requires PHP >= 8.4.1 |
| Orchestra Testbench | ^10 (Laravel 12) / ^11 (Laravel 13) | official harness |
| Pest | smoke job: Pest 4 (PHPUnit 12), Pest 5 (PHPUnit 13, PHP 8.4+) | no separate Pest plugin |

Rule: composer constraints and README claim only what the CI matrix actually verifies. Final matrix is locked at stage 0 against real Testbench constraints.

## Public API (chosen: variant A — trait + named args)

```php
use Ralder\QueryScaling\Assertions\QueryScalingAssertions;
use Ralder\QueryScaling\QueryGrowth;

class PostsApiTest extends TestCase
{
    use QueryScalingAssertions;

    public function test_posts_endpoint_keeps_query_count_constant(): void
    {
        $this->assertQueriesScaleConstantly(
            scales: [2, 5, 10],
            populate: fn (int $scale) => Post::factory()->count($scale)->create(),
            run: fn () => $this->getJson('/api/posts')->assertSuccessful(),
        );
    }

    public function test_job_processor_grows_at_most_linearly(): void
    {
        $this->assertQueryScaling(
            scales: [2, 5, 10],
            populate: fn (int $scale) => Job::factory()->count($scale)->create(),
            run: fn () => app(JobProcessor::class)->process(),
            expectation: QueryGrowth::atMostSlope(1, tolerance: 0),
        );
    }
}
```

Pest: `uses(QueryScalingAssertions::class);` — no plugin.

Why variant A over fluent builder / scenario object: earliest possible validation (invalid scales throw before any DB work), no partial-state machine, trivial Pest ergonomics, matches PHPUnit assertion mental model. Fluent builder can be added later without BC break.

## Contract semantics (exact)

Scales `s1 < ... < sk`: strictly ascending positive integers, k >= 2. Duplicates, unsorted input, values < 1, k < 2 => `InvalidArgumentException` before any DB work. Counts `qi` are the measured query counts.

- **ConstantQueryCount(tolerance T = 0)**: passes iff `max(q) - min(q) <= T`. T is an absolute number of queries. A least-squares slope may be computed for diagnostics only; it never replaces the exact check.
- **AtMostSlopeQueryCount(maxSlope M >= 0, tolerance T = 0)**: passes iff for EVERY consecutive measured pair: `q(i+1) - q(i) <= M * (s(i+1) - s(i)) + T`. A decreasing count between two scales always passes (the contract constrains growth). No global regression averaging: every real segment is checked.
- `constant(0)` is NOT equivalent to `atMostSlope(0)`: atMostSlope(0) passes a strictly decreasing series, constant does not allow any movement beyond the tolerance band.
- Vocabulary: reports and docs say "controlled scaling assertion over measured scale factors". Never frame measured points as a mathematical proof of asymptotic complexity; README carries an explicit disclaimer.

## Scale run lifecycle

For each scale n (ascending):

1. `isolation.begin()` on every selected connection (records depth, opens nested transaction/savepoint)
2. `populate(n)` — collector inactive
3. optional `warmup()` — collector inactive; documented contract: must not mutate the dataset
4. `collector.measure(run)` — activates session, invokes run, deactivates in finally
5. record `ScaleMeasurement(n, queries, fingerprints)`
6. `isolation.restore()` — always in `finally`, connections in reverse order

Exceptions in `populate`/`run` propagate as-is after restore; the test fails with the original cause.

## Isolation

Default: `LaravelTransactionIsolation` per connection.

- `begin()`: record `before = transactionLevel()` and `PDO::inTransaction()`; `beginTransaction()`; `measured = before + 1`.
- `restore()`:
  - `level == measured` -> `rollBack()` back to `before`.
  - `level > measured` (callback left nested transactions open) -> `rollBack($before)` (Laravel savepoint semantics).
  - `level <= before`, or physical transaction state contradicts expected depth (e.g. implicit commit from DDL on MySQL) -> `IsolationViolationException` with before/after depth and connection name.
- Works under `DatabaseTransactions` / `RefreshDatabase` (we simply nest one savepoint deeper) and without any test transaction.
- Custom strategies implement `IsolationStrategy` (`begin(): void`, `restore(): void`) and are passed via `isolation:`.
- Out of scope and documented: Redis, filesystem, external APIs, queued side effects are NOT auto-rolled back.

## Query collection

- `LaravelQueryCollector` registers ONE `DB::listen()` listener per event-dispatcher instance (a `WeakMap` keyed by dispatcher identity, so a collected dispatcher releases its registration and a rebuilt app — whose fresh dispatcher may recycle the old object id — always gets its own listener; Testbench rebuilds the app per test). Repeat assertions on the same dispatcher add zero listeners.
- The listener is inert without an active `MeasurementSession`; sessions flip an active flag, they never register listeners.
- Overlapping sessions are rejected with an exception. Deactivation always happens in `finally`.
- Default connection only by default; `connections:` accepts `string|array`. `connectionName` is part of the query identity.

## Fingerprints

Fingerprint = `{connectionName, normalizedSql}`.
Normalization MVP: `trim` + whitespace collapse (`/\s+/` -> single space). Eloquent `?` placeholders group naturally. Optional literal-normalization heuristic (numbers, quoted strings -> `?`) is OFF by default and documented as a heuristic.
Per fingerprint per scale: occurrence count + up to 3 example bindings; bindings render in reports only with explicit `showBindings: true`.

## Failure report

Deterministic: growing fingerprints sorted by net growth desc, tie -> SQL asc, connection asc; monospace tables; least-squares trend labelled "approximately ... over the measured scales"; no ANSI; throws `ExpectationFailedException` with the rendered message.

## Risks (tracked)

1. Callbacks corrupting transaction depth (partial commits, DDL implicit commit on MySQL) -> strict depth checks + `PDO::inTransaction()` verification + clear `IsolationViolationException`.
2. Transaction/savepoint behaviour differences between Laravel 12/13 -> CI matrix.
3. Testbench app rebuild between tests -> per-dispatcher listener registration.
4. `DB::withoutEvents()` / mid-run connection swapping silently drops queries -> documented constraint.
5. Literal-normalization heuristics can over-group -> off by default.
6. Pest 5 / PHPUnit 13 / Testbench 11 drift -> only CI-verified combos are claimed.

## MVP stages

0. Toolchain (local PHP 8.5 + Composer 2), skeleton: composer.json, PSR-4, strict_types, Pint, PHPStan level max, GH Actions matrix, LICENSE.
1. Core: value objects, fingerprint, expectations, scale validation + unit tests.
2. Core: ScaleRunner on interfaces + unit tests with fakes.
3. Laravel adapters: collector/session + transaction isolation + Testbench integration tests.
4. Trait API + expectation wiring + integration tests (eager-loaded pass, N+1 fail, slope pass/fail, validation, tolerance).
5. Failure report + warmup.
6. Pest smoke, README/CHANGELOG/CONTRIBUTING, full verification, tag v0.1.0.

## MVP boundaries (non-goals)

No suite scanning, cross-commit baselines, global N+1 detection, EXPLAIN, duration budgets, static analysis, dashboards, CI storage, growth classification beyond constant/slope, non-Laravel frameworks, production monitoring, rollback of external side effects.
