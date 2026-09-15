<?php

declare(strict_types=1);

namespace Ralder\QueryScaling\Core;

use Closure;

/**
 * Framework-facing seam: measures exactly the queries issued by $callback.
 *
 * Implementations must deactivate recording in a finally block, reject
 * overlapping sessions, and never leak queries into neighbouring assertions.
 */
interface QueryCollectorContract
{
    /**
     * @return list<RecordedQuery> queries recorded while running $callback
     */
    public function measure(Closure $callback): array;
}
