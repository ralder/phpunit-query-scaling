<?php

declare(strict_types=1);

namespace Ralder\QueryScaling\Core;

use RuntimeException;

/**
 * Thrown when a scale run callback destroyed the isolation structure beyond
 * safe recovery (e.g. committed or rolled back transactions outside its own
 * scope). The assertion cannot guarantee a clean environment anymore, so it
 * fails loudly instead of silently leaking state into the rest of the test.
 */
final class IsolationViolationException extends RuntimeException {}
