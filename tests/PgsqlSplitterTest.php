<?php

declare(strict_types=1);

namespace DbSimply\Tests;

use DbSimply\Sql\PgsqlSplitter;

/**
 * Splitting PostgreSQL text into statements.
 */
final class PgsqlSplitterTest extends TestCase
{
    public function testSemicolonsInsideLiteralsNamesAndCommentsDoNotSplit(): void
    {
        $statements = PgsqlSplitter::split(<<<'SQL'
            SELECT 'a;b', "c;d" FROM t; -- trailing; comment
            SELECT E'it\'s; here', 'it''s; there';
            /* outer /* nested; */ still comment; */ SELECT 3;
            SQL);

        self::assertSame([
            "SELECT 'a;b', \"c;d\" FROM t",
            // A comment goes with the statement after it, as psql sends it.
            "-- trailing; comment\nSELECT E'it\\'s; here', 'it''s; there'",
            '/* outer /* nested; */ still comment; */ SELECT 3',
        ], array_map(static fn ($statement): string => $statement->sql, $statements));
    }

    public function testDollarQuotedBodiesAreKeptWhole(): void
    {
        $statements = PgsqlSplitter::split(<<<'SQL'
            CREATE FUNCTION f() RETURNS int AS $$ SELECT 1; $$ LANGUAGE sql;
            DO $body$ BEGIN PERFORM 1; RAISE NOTICE '$$'; END $body$;
            SELECT a$b FROM t
            SQL);

        self::assertSame(3, count($statements));
        self::assertTrue(str_ends_with($statements[0]->sql, 'LANGUAGE sql'));
        self::assertSame("DO \$body\$ BEGIN PERFORM 1; RAISE NOTICE '\$\$'; END \$body\$", $statements[1]->sql);
        self::assertSame('SELECT a$b FROM t', $statements[2]->sql);
    }

    public function testStatementsRecordTheLineTheyStartOn(): void
    {
        $statements = PgsqlSplitter::split("-- intro\n\nSELECT 1;\nSELECT\n'two\nlines';\n\nSELECT 3;");

        self::assertSame([3, 4, 8], array_map(static fn ($statement): int => $statement->line, $statements));
    }

    public function testEmptyAndCommentOnlyInputHasNoStatements(): void
    {
        self::assertSame([], PgsqlSplitter::split(" ;; -- nothing\n/* here */ "));
    }
}
