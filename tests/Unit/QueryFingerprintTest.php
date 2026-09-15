<?php

declare(strict_types=1);

namespace Ralder\QueryScaling\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Ralder\QueryScaling\Core\QueryFingerprint;

final class QueryFingerprintTest extends TestCase
{
    public function test_collapses_whitespace(): void
    {
        $fingerprint = QueryFingerprint::from('mysql', "select *\n  from   \"users\"\twhere \"id\" = ?");

        $this->assertSame('select * from "users" where "id" = ?', $fingerprint->sql);
    }

    public function test_trims_sql(): void
    {
        $fingerprint = QueryFingerprint::from('mysql', '   select 1   ');

        $this->assertSame('select 1', $fingerprint->sql);
    }

    public function test_queries_with_placeholders_group_together(): void
    {
        $a = QueryFingerprint::from('mysql', 'select * from "users" where "id" = ? limit 1');
        $b = QueryFingerprint::from('mysql', 'select * from "users" where "id" = ? limit 1');

        $this->assertSame($a->sql, $b->sql);
        $this->assertSame($a->key(), $b->key());
    }

    public function test_connection_is_part_of_identity(): void
    {
        $a = QueryFingerprint::from('mysql', 'select 1');
        $b = QueryFingerprint::from('sqlite', 'select 1');

        $this->assertNotSame($a->connection, $b->connection);
        $this->assertNotSame($a->key(), $b->key());
    }

    public function test_literals_are_preserved_by_default(): void
    {
        $a = QueryFingerprint::from('mysql', 'select * from users where id = 1');
        $b = QueryFingerprint::from('mysql', 'select * from users where id = 2');

        $this->assertNotSame($a->sql, $b->sql);
        $this->assertNotSame($a->key(), $b->key());
    }

    public function test_literal_normalization_groups_numeric_and_string_literals(): void
    {
        $a = QueryFingerprint::from('mysql', "select * from users where id = 1 and name = 'alice'", normalizeLiterals: true);
        $b = QueryFingerprint::from('mysql', "select * from users where id = 42 and name = 'bob'", normalizeLiterals: true);

        $this->assertSame($a->key(), $b->key());
        $this->assertSame('select * from users where id = ? and name = ?', $a->sql);
    }

    public function test_literal_normalization_keeps_digits_inside_identifiers(): void
    {
        $fingerprint = QueryFingerprint::from('mysql', 'select * from `table2` where `utf8mb4_col` = 7', normalizeLiterals: true);

        $this->assertSame('select * from `table2` where `utf8mb4_col` = ?', $fingerprint->sql);
    }

    public function test_escaped_quotes_inside_string_literals_do_not_break_normalization(): void
    {
        $fingerprint = QueryFingerprint::from('mysql', "select * from users where name = 'it\\'s'", normalizeLiterals: true);

        $this->assertSame('select * from users where name = ?', $fingerprint->sql);
    }
}
