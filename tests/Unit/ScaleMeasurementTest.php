<?php

declare(strict_types=1);

namespace Ralder\QueryScaling\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Ralder\QueryScaling\Core\QueryFingerprint;
use Ralder\QueryScaling\Core\RecordedQuery;
use Ralder\QueryScaling\Core\ScaleMeasurement;

final class ScaleMeasurementTest extends TestCase
{
    public function test_counts_recorded_queries(): void
    {
        $measurement = new ScaleMeasurement(5, [
            new RecordedQuery('mysql', 'select * from "posts"', []),
            new RecordedQuery('mysql', 'select * from "posts"', []),
            new RecordedQuery('mysql', 'select * from "users" where "id" = ?', [3]),
        ]);

        $this->assertSame(3, $measurement->count());
    }

    public function test_groups_queries_into_fingerprint_counts(): void
    {
        $measurement = new ScaleMeasurement(5, [
            new RecordedQuery('mysql', 'select *    from "posts"', []),
            new RecordedQuery('mysql', 'select * from "posts"', []),
            new RecordedQuery('mysql', 'select * from "users" where "id" = ?', [3]),
            new RecordedQuery('sqlite', 'select * from "posts"', []),
        ]);

        $counts = $measurement->fingerprintCounts();

        $this->assertSame(2, $counts[QueryFingerprint::from('mysql', 'select * from "posts"')->key()]);
        $this->assertSame(1, $counts[QueryFingerprint::from('mysql', 'select * from "users" where "id" = ?')->key()]);
        $this->assertSame(1, $counts[QueryFingerprint::from('sqlite', 'select * from "posts"')->key()]);
        $this->assertCount(3, $counts);
    }

    public function test_keeps_up_to_three_bindings_examples_per_fingerprint(): void
    {
        $measurement = new ScaleMeasurement(5, [
            new RecordedQuery('mysql', 'select * from "users" where "id" = ?', [1]),
            new RecordedQuery('mysql', 'select * from "users" where "id" = ?', [2]),
            new RecordedQuery('mysql', 'select * from "users" where "id" = ?', [3]),
            new RecordedQuery('mysql', 'select * from "users" where "id" = ?', [4]),
            new RecordedQuery('mysql', 'select * from "users" where "id" = ?', [5]),
        ]);

        $key = QueryFingerprint::from('mysql', 'select * from "users" where "id" = ?')->key();

        $this->assertSame([[1], [2], [3]], $measurement->bindingsExamples($key));
    }

    public function test_bindings_are_not_part_of_fingerprint(): void
    {
        $measurement = new ScaleMeasurement(5, [
            new RecordedQuery('mysql', 'select * from "users" where "id" = ?', [1]),
            new RecordedQuery('mysql', 'select * from "users" where "id" = ?', [99]),
        ]);

        $this->assertSame(1, count($measurement->fingerprints()));
    }

    public function test_fingerprints_returns_query_fingerprint_objects(): void
    {
        $measurement = new ScaleMeasurement(5, [
            new RecordedQuery('mysql', "select *\nfrom \"posts\"", []),
        ]);

        $fingerprints = $measurement->fingerprints();
        $fingerprint = reset($fingerprints);

        $this->assertInstanceOf(QueryFingerprint::class, $fingerprint);
        $this->assertSame('mysql', $fingerprint->connection);
        $this->assertSame('select * from "posts"', $fingerprint->sql);
    }
}
