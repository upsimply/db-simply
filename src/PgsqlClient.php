<?php

declare(strict_types=1);

namespace DbSimply;

use Closure;
use PgSql\Connection as PgConnection;
use PgSql\Result;

/**
 * A connection to PostgreSQL.
 *
 * A PostgreSQL connection is to one database, and a statement cannot reach
 * into another: moving to another database is a new connection, made with
 * the same credentials by the closure {@see Connection} hands over. Tables
 * are those of the database's current schema (`public`, unless the role's
 * search_path says otherwise), which is where applications keep theirs.
 */
final class PgsqlClient extends Client
{
    private ?string $version = null;

    private ?string $schema = null;

    private int $affected = 0;

    private ?string $insertId = null;

    /**
     * @param  Closure(string): PgConnection  $connect  opens a connection to the named database
     */
    public function __construct(
        private PgConnection $connection,
        private string $database,
        private readonly Closure $connect,
    ) {}

    public function driver(): string
    {
        return self::PGSQL;
    }

    public function connection(): PgConnection
    {
        return $this->connection;
    }

    public function database(): string
    {
        return $this->database;
    }

    /**
     * The schema tables are listed from and qualified by.
     */
    public function schema(): string
    {
        return $this->schema ??= (string) ($this->value('SELECT current_schema()') ?? 'public');
    }

    /**
     * Run one statement and return its result, or fail with the server's own message.
     */
    public function run(string $sql): Result
    {
        if (! pg_send_query($this->connection, $sql)) {
            throw new UserError(pg_last_error($this->connection) ?: 'The query could not be sent.');
        }

        $result = pg_get_result($this->connection);

        // Drain anything after the first result, so the connection is ready
        // for the next statement whatever was sent.
        while (pg_get_result($this->connection) !== false) {
            // nothing
        }

        if ($result === false) {
            throw new UserError('The server returned no result.');
        }

        $status = pg_result_status($result);

        if ($status === PGSQL_FATAL_ERROR || $status === PGSQL_BAD_RESPONSE || $status === PGSQL_NONFATAL_ERROR) {
            $message = (string) (pg_result_error_field($result, PGSQL_DIAG_MESSAGE_PRIMARY) ?: pg_result_error($result));
            $detail = pg_result_error_field($result, PGSQL_DIAG_MESSAGE_DETAIL);

            throw new UserError(trim($message.(is_string($detail) && $detail !== '' ? ' '.$detail : '')));
        }

        return $result;
    }

    public function statement(string $sql): int
    {
        $result = $this->run($sql);
        $this->affected = max(0, pg_affected_rows($result));

        return $this->affected;
    }

    /**
     * PostgreSQL has no connection-wide "last insert id"; an INSERT that
     * wants one asks for it with RETURNING and records it here.
     */
    public function insertId(): ?string
    {
        return $this->insertId;
    }

    /**
     * Run an INSERT … RETURNING of one column, and keep the value it returned.
     */
    public function insertReturning(string $sql): ?string
    {
        $result = $this->run($sql);
        $value = pg_num_rows($result) > 0 ? pg_fetch_result($result, 0, 0) : null;

        return $this->insertId = $value === false || $value === null ? null : (string) $value;
    }

    public function quote(?string $value): string
    {
        if ($value === null) {
            return 'NULL';
        }

        $quoted = pg_escape_literal($this->connection, $value);

        if ($quoted === false) {
            throw new UserError('That value cannot be used in a query.');
        }

        return $quoted;
    }

    public function identifier(string $name): string
    {
        if (! Identifier::isValid($name)) {
            throw new UserError('Invalid name.');
        }

        return '"'.str_replace('"', '""', $name).'"';
    }

    public function qualified(string $database, string $name): string
    {
        if ($database !== $this->database) {
            $this->useDatabase($database);
        }

        return $this->identifier($this->schema()).'.'.$this->identifier($name);
    }

    public function useDatabase(string $database): void
    {
        if ($database === $this->database) {
            return;
        }

        $connection = ($this->connect)($database);
        pg_close($this->connection);

        $this->connection = $connection;
        $this->database = $database;
        $this->schema = null;
    }

    public function version(): string
    {
        return $this->version ??= (string) ($this->value('SHOW server_version') ?? '');
    }

    public function asText(string $quotedColumn): string
    {
        return $quotedColumn.'::text';
    }

    /**
     * ILIKE: PostgreSQL compares text case-sensitively, unlike the usual
     * MariaDB and MySQL collations a search box is expected to behave like.
     */
    public function likeOperator(bool $negated = false): string
    {
        return $negated ? 'NOT ILIKE' : 'ILIKE';
    }

    public function previewExpression(string $quotedColumn, int $length, string $dataType): string
    {
        // bytea is cut as bytes and comes back in its hex text form; every
        // other type is cut as its text.
        return $dataType === 'bytea'
            ? sprintf('substring(%s from 1 for %d)', $quotedColumn, $length)
            : sprintf('left(%s::text, %d)', $quotedColumn, $length);
    }

    public function lengthExpression(string $quotedColumn, string $dataType): string
    {
        return $dataType === 'bytea'
            ? sprintf('octet_length(%s)', $quotedColumn)
            : sprintf('octet_length(%s::text)', $quotedColumn);
    }

    /**
     * Nothing: PostgreSQL has no LIMIT on UPDATE or DELETE. The row key's
     * condition matches one row at most on its own.
     */
    public function limitOne(): string
    {
        return '';
    }

    public function insertDefaults(string $qualifiedTable): string
    {
        return sprintf('INSERT INTO %s DEFAULT VALUES', $qualifiedTable);
    }

    /**
     * Stream a SELECT through a cursor, a batch of rows at a time.
     *
     * pg_query holds a whole result in memory, which a table export cannot
     * afford; a cursor hands the rows over in batches instead. A cursor
     * lives in a transaction, so one is opened unless the session is in one.
     */
    public function split(string $sql): array
    {
        return Sql\PgsqlSplitter::split($sql);
    }

    /**
     * Whether a statement can be read through a cursor: a cursor takes a
     * SELECT or VALUES (TABLE is a SELECT), and nothing that writes.
     */
    public static function isCursorable(string $sql): bool
    {
        $analysis = Sql\Analyzer::analyze($sql);

        return $analysis['readOnly'] && in_array($analysis['keyword'], ['SELECT', 'VALUES', 'TABLE'], true);
    }

    public function stream(string $sql, Closure $columns, Closure $row): void
    {
        // SHOW, EXPLAIN and the like cannot be a cursor's query; their
        // results are small, so they are read whole.
        if (! self::isCursorable($sql)) {
            $result = $this->run($sql);
            $names = [];

            for ($i = 0, $count = pg_num_fields($result); $i < $count; $i++) {
                $names[] = (string) pg_field_name($result, $i);
            }

            if ($names === []) {
                throw new UserError('That statement returns no rows to export.');
            }

            $columns($names);

            while (($values = pg_fetch_row($result)) !== false) {
                $row(array_map(static fn (mixed $value): ?string => $value === null ? null : (string) $value, $values));
            }

            pg_free_result($result);

            return;
        }

        $this->withCursor($sql, function (string $cursor) use ($columns, $row): void {
            $first = true;

            do {
                $result = $this->run(sprintf('FETCH FORWARD 1000 FROM %s', $cursor));

                if ($first) {
                    $names = [];

                    for ($i = 0, $count = pg_num_fields($result); $i < $count; $i++) {
                        $names[] = (string) pg_field_name($result, $i);
                    }

                    $columns($names);
                    $first = false;
                }

                $fetched = 0;

                while (($values = pg_fetch_row($result)) !== false) {
                    $row(array_map(static fn (mixed $value): ?string => $value === null ? null : (string) $value, $values));
                    $fetched++;
                }

                pg_free_result($result);
            } while ($fetched === 1000);
        });
    }

    /**
     * Declare a cursor for a SELECT, hand its name to the callback, and close it.
     *
     * @template T
     *
     * @param  Closure(string): T  $callback
     * @return T
     */
    public function withCursor(string $sql, Closure $callback): mixed
    {
        $cursor = 'db_simply_cursor';
        $ownTransaction = pg_transaction_status($this->connection) === PGSQL_TRANSACTION_IDLE;

        if ($ownTransaction) {
            $this->run('BEGIN');
        }

        try {
            $this->run(sprintf('DECLARE %s NO SCROLL CURSOR FOR %s', $cursor, $sql));
            $value = $callback($cursor);
            $this->run('CLOSE '.$cursor);
        } catch (UserError $e) {
            if ($ownTransaction) {
                @pg_query($this->connection, 'ROLLBACK');
            }

            throw $e;
        }

        if ($ownTransaction) {
            $this->run('COMMIT');
        }

        return $value;
    }

    public function close(): void
    {
        pg_close($this->connection);
    }

    protected function fetch(string $sql, bool $associative): array
    {
        $result = $this->run($sql);
        $rows = [];

        while (($values = $associative ? pg_fetch_assoc($result) : pg_fetch_row($result)) !== false) {
            $rows[] = array_map(static fn (mixed $value): ?string => $value === null ? null : (string) $value, $values);
        }

        pg_free_result($result);

        return $rows;
    }
}
