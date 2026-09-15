# Contributing

Thanks for your interest in `ralder/phpunit-query-scaling`!

## Setup

```bash
composer install
```

Requirements: PHP 8.3+ and Composer 2.

## Workflow

This package is developed test-first:

1. Write or extend a failing test (`tests/Unit` for engine internals — no Laravel involved;
   `tests/Integration` for Testbench-backed scenarios; `tests/Pest` for the Pest smoke suite).
2. Implement the minimal change that makes it pass.
3. Keep public API changes explicit: new optional parameters or new classes, no silent
   signature changes between minor versions after 1.0.

## Checks (all must pass before a PR)

```bash
composer test            # PHPUnit: Unit + Integration suites
composer test:pest       # Pest smoke suite
composer analyse         # PHPStan level max (no baseline, no inline suppressions)
composer format:test     # Pint code style check (laravel preset + strict_types)
```

## Conventions

- `declare(strict_types=1)` in every PHP file.
- Layering: `src/Core` must not import `illuminate/*`; Laravel specifics live in `src/Laravel`
  and `src/Assertions`.
- Never claim that measured points prove a complexity class. Reports and docs use
  "scaling contract" / "controlled scaling assertion" language.
- Version support claims in `README.md` must match the verified CI matrix in
  `.github/workflows/`.
- Commit messages: small, atomic, describing the assertion/diagnostic surface change.

## Reporting issues

Include: PHP / Laravel / PHPUnit versions, minimal failing scenario, and the full failure
report the package printed.
