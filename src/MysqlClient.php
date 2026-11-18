<?php

declare(strict_types=1);

namespace DbSimply;

use Closure;
use mysqli;
use mysqli_result;
use mysqli_sql_exception;

/**
 * A mysqli connection to MariaDB or MySQL.
 *
 * Statements a user types run through {@see Console}, which works with the
 * connection directly.
 */
final class MysqlClient extends Client
{
    private ?string $version = null;

    public function __construct(private readonly mysqli $mysqli) {}

    public function driver(): string
    {
        return self::MYSQL;
    }

    public function mysqli(): mysqli
    {
        return $this->mysqli;
    }

    public function query(string $sql): mysqli_result|bool
    {
        try {
            return $this->mysqli->query($sql);
        } catch (mysqli_sql_exception $e) {
            throw new UserError($e->getMessage());
        }
    }

    public function statement(string $sql): int
    {
        $result = $this->query($sql);

        if ($result instanceof mysqli_result) {
            $result->free();
        }

        return max(0, (int) $this->mysqli->affected_rows);
    }

    public function insertId(): ?string
    {
        $id = $this->mysqli->insert_id;

        return $id ? (string) $id : null;
    }

    public function quote(?string $value): string
    {
        return $value === null ? 'NULL' : "'".$this->mysqli->real_escape_string($value)."'";
    }

    public function identifier(string $name): string
    {
        return Identifier::quote($name);
    }

    public function qualified(string $database, string $name): string
    {
        return Identifier::qualified($database, $name);
    }

    public function useDatabase(string $database): void
    {
        try {
            $this->mysqli->select_db($database);
        } catch (mysqli_sql_exception $e) {
            throw new UserError($e->getMessage());
        }
    }

    public function version(): string
    {
        return $this->version ??= (string) $this->value('SELECT VERSION()');
    }

    public function isMariaDb(): bool
    {
        return stripos($this->version(), 'mariadb') !== false;
    }

    public function stream(string $sql, Closure $columns, Closure $row): void
    {
        try {
            $result = $this->mysqli->query($sql, MYSQLI_USE_RESULT);
        } catch (mysqli_sql_exception $e) {
            throw new UserError($e->getMessage());
        }

        if (! $result instanceof mysqli_result) {
            throw new UserError('The query returned no rows to export.');
        }

        $columns(array_map(static fn (object $field): string => (string) $field->name, $result->fetch_fields()));

        while (($values = $result->fetch_row()) !== null && $values !== false) {
            $row(array_map(static fn (mixed $value): ?string => $value === null ? null : (string) $value, $values));
        }

        $result->free();
    }

    public function close(): void
    {
        $this->mysqli->close();
    }

    protected function fetch(string $sql, bool $associative): array
    {
        $result = $this->query($sql);

        if (! $result instanceof mysqli_result) {
            return [];
        }

        $rows = $result->fetch_all($associative ? MYSQLI_ASSOC : MYSQLI_NUM);
        $result->free();

        return $rows;
    }
}
