<?php

declare(strict_types=1);

namespace DbSimply;

use Closure;
use mysqli;

/**
 * A connection to the database server, for the queries the app builds itself.
 *
 * Two drivers stand behind it: {@see MysqlClient} for MariaDB and MySQL, and
 * {@see PgsqlClient} for PostgreSQL. Everything that differs between the two
 * SQL dialects in what the browsing, editing and console code builds -- how a
 * name is quoted, how a value is cut for a preview, how a search ignores case
 * -- is asked of the client, so that code is written once for both.
 *
 * Everything here quotes what it is given, and turns a server error into one
 * the user can read.
 */
abstract class Client
{
    public const string MYSQL = 'mysql';

    public const string PGSQL = 'pgsql';

    /**
     * The driver behind the connection: {@see self::MYSQL} or {@see self::PGSQL}.
     */
    abstract public function driver(): string;

    /**
     * Run a query and return its rows, as name => value or as lists of values.
     *
     * @return list<array<string, ?string>>|list<list<?string>>
     */
    abstract protected function fetch(string $sql, bool $associative): array;

    /**
     * Run a statement that returns no rows, and return how many rows it changed.
     */
    abstract public function statement(string $sql): int;

    /**
     * The value the last INSERT generated for an auto-increment column, if any.
     */
    abstract public function insertId(): ?string;

    /**
     * Quote a value as a string literal.
     */
    abstract public function quote(?string $value): string;

    /**
     * Quote a name.
     */
    abstract public function identifier(string $name): string;

    /**
     * Quote a table in a database. On PostgreSQL the connection is to one
     * database already, so the name is qualified by its schema instead.
     */
    abstract public function qualified(string $database, string $name): string;

    /**
     * Make the given database the one statements run in.
     */
    abstract public function useDatabase(string $database): void;

    /**
     * The server's version string, e.g. "10.11.6-MariaDB-0+deb12u1" or "18.1".
     */
    abstract public function version(): string;

    /**
     * Run a SELECT and hand its rows to the callbacks one at a time, without
     * holding the whole result in memory.
     *
     * @param  Closure(list<string>): void  $columns  called once with the column names
     * @param  Closure(list<?string>): void  $row  called for every row
     */
    abstract public function stream(string $sql, Closure $columns, Closure $row): void;

    abstract public function close(): void;

    /**
     * Split SQL text into statements, by the rules of this server's SQL.
     *
     * @return list<Sql\Statement>
     */
    public function split(string $sql): array
    {
        return Sql\Splitter::split($sql);
    }

    /**
     * Run a query and return every row as name => value.
     *
     * @return list<array<string, ?string>>
     */
    public function select(string $sql): array
    {
        /** @var list<array<string, ?string>> */
        return $this->fetch($sql, true);
    }

    /**
     * Run a query and return every row as a list of values.
     *
     * @return list<list<?string>>
     */
    public function selectRows(string $sql): array
    {
        /** @var list<list<?string>> */
        return $this->fetch($sql, false);
    }

    /**
     * The first column of the first row, or null.
     */
    public function value(string $sql): ?string
    {
        $rows = $this->selectRows($sql);

        return $rows[0][0] ?? null;
    }

    /**
     * Quote a value for a LIKE pattern, escaping its wildcards. Both servers
     * take a backslash as LIKE's escape character by default.
     */
    public function quoteLike(string $value, string $before = '', string $after = ''): string
    {
        return $this->quote($before.addcslashes($value, '\\%_').$after);
    }

    public function isMariaDb(): bool
    {
        return false;
    }

    public function isPostgres(): bool
    {
        return $this->driver() === self::PGSQL;
    }

    /**
     * The engine for display and for the browser: "mariadb", "mysql" or "postgres".
     */
    public function engine(): string
    {
        return match (true) {
            $this->isPostgres() => 'postgres',
            $this->isMariaDb() => 'mariadb',
            default => 'mysql',
        };
    }

    /**
     * The server's name for messages.
     */
    public function label(): string
    {
        return match ($this->engine()) {
            'postgres' => 'PostgreSQL',
            'mariadb' => 'MariaDB',
            default => 'MySQL',
        };
    }

    /**
     * A column as text, for matching it against a pattern.
     */
    public function asText(string $quotedColumn): string
    {
        return $quotedColumn;
    }

    /**
     * The operator that matches a pattern regardless of case. MariaDB and
     * MySQL compare text case-insensitively under their usual collations.
     */
    public function likeOperator(bool $negated = false): string
    {
        return $negated ? 'NOT LIKE' : 'LIKE';
    }

    /**
     * An expression reading at most the first $length bytes of a column.
     */
    public function previewExpression(string $quotedColumn, int $length, string $dataType): string
    {
        return sprintf('LEFT(%s, %d)', $quotedColumn, $length);
    }

    /**
     * An expression giving a column's full length in bytes.
     */
    public function lengthExpression(string $quotedColumn, string $dataType): string
    {
        return sprintf('OCTET_LENGTH(%s)', $quotedColumn);
    }

    /**
     * What follows an UPDATE or DELETE that must touch one row at most.
     */
    public function limitOne(): string
    {
        return ' LIMIT 1';
    }

    /**
     * An INSERT of a row made entirely of column defaults.
     */
    public function insertDefaults(string $qualifiedTable): string
    {
        return sprintf('INSERT INTO %s () VALUES ()', $qualifiedTable);
    }

    /**
     * The mysqli connection, for the MariaDB and MySQL features that use it
     * directly. Those features are not offered on PostgreSQL; this is the
     * backstop should one be reached anyway.
     */
    public function mysqli(): mysqli
    {
        throw new UserError('This is not available on '.$this->label().'.', 400);
    }

    /**
     * Run a statement on the mysqli connection; see {@see self::mysqli()}.
     */
    public function query(string $sql): mixed
    {
        throw new UserError('This is not available on '.$this->label().'.', 400);
    }
}
