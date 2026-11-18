<?php

declare(strict_types=1);

namespace DbSimply;

/**
 * What the signed-in user can see: databases, tables and their structure.
 *
 * The reading itself is the {@see Inspector\Inspector}'s for the server the
 * connection is to -- MariaDB and MySQL, or PostgreSQL -- so the shapes
 * handed on are the same whichever it is.
 *
 * It is also the gatekeeper for names. A database or table named by the
 * browser is used only once it is found here, in what the server itself
 * reports to this user, so nothing can be reached that the server's own
 * privileges and the hidden-database list do not already allow.
 */
final class Catalog
{
    /**
     * @var list<string>|null
     */
    private ?array $databaseNames = null;

    private readonly Inspector\Inspector $inspector;

    /**
     * @param  list<string>  $hidden
     */
    public function __construct(private readonly Client $client, private readonly array $hidden)
    {
        $this->inspector = match (true) {
            $client instanceof PgsqlClient => new Inspector\PgsqlInspector($client),
            $client instanceof MysqlClient => new Inspector\MysqlInspector($client),
            default => throw new UserError('Unsupported database connection.', 500),
        };
    }

    /**
     * The databases the user can open, with their table count and size. A
     * count or size the server cannot give without opening the database is
     * null.
     *
     * @return list<array{name: string, tables: ?int, size: ?int}>
     */
    public function databases(): array
    {
        $names = $this->databaseNames();

        if ($names === []) {
            return [];
        }

        $stats = $this->inspector->databaseStats($names);

        return array_map(static fn (string $name): array => [
            'name' => $name,
            'tables' => array_key_exists($name, $stats) ? $stats[$name]['tables'] : 0,
            'size' => array_key_exists($name, $stats) ? $stats[$name]['size'] : 0,
        ], $names);
    }

    /**
     * @return list<string>
     */
    public function databaseNames(): array
    {
        if ($this->databaseNames !== null) {
            return $this->databaseNames;
        }

        $hidden = array_map(strtolower(...), $this->hidden);
        $names = array_values(array_filter(
            $this->inspector->databaseNames(),
            static fn (string $name): bool => ! in_array(strtolower($name), $hidden, true),
        ));

        sort($names, SORT_NATURAL | SORT_FLAG_CASE);

        return $this->databaseNames = $names;
    }

    /**
     * Resolve a database named by the browser, or fail.
     */
    public function database(mixed $name): string
    {
        if (! Identifier::isValid($name) || ! in_array($name, $this->databaseNames(), true)) {
            throw new UserError('That database does not exist, or you cannot open it.', 404);
        }

        return $name;
    }

    /**
     * The tables and views in a database.
     *
     * @return list<array<string, mixed>>
     */
    public function tables(string $database): array
    {
        return $this->inspector->tables($database);
    }

    /**
     * One table's summary, resolving a table named by the browser, or failing.
     *
     * @return array<string, mixed>
     */
    public function table(string $database, mixed $name): array
    {
        $table = Identifier::isValid($name) ? $this->inspector->table($database, $name) : null;

        return $table ?? throw new UserError('That table does not exist.', 404);
    }

    /**
     * The columns of a table, in order.
     *
     * @return list<array{name: string, type: string, dataType: string, nullable: bool, default: ?string, key: string, extra: string, collation: ?string, comment: string, octetLength: ?int}>
     */
    public function columns(string $database, string $table): array
    {
        return $this->inspector->columns($database, $table);
    }

    /**
     * The indexes of a table, each with its columns in order.
     *
     * @return list<array{name: string, unique: bool, primary: bool, type: string, columns: list<array{name: string, subPart: ?int}>, comment: string}>
     */
    public function indexes(string $database, string $table): array
    {
        return $this->inspector->indexes($database, $table);
    }

    /**
     * The foreign keys a table declares.
     *
     * @return list<array{name: string, columns: list<string>, referencedDatabase: string, referencedTable: string, referencedColumns: list<string>, onUpdate: string, onDelete: string}>
     */
    public function foreignKeys(string $database, string $table): array
    {
        return $this->inspector->foreignKeys($database, $table);
    }

    /**
     * The triggers on a table.
     *
     * @return list<array{name: string, timing: string, event: string, statement: string}>
     */
    public function triggers(string $database, string $table): array
    {
        return $this->inspector->triggers($database, $table);
    }

    /**
     * The table's CREATE TABLE (or CREATE VIEW) statement.
     */
    public function createStatement(string $database, string $table): string
    {
        return $this->inspector->createStatement($database, $table);
    }

    /**
     * The columns that identify one row: the primary key, or else the first
     * unique index whose columns are all NOT NULL. Null when there is none,
     * and the rows of that table cannot be told apart safely.
     *
     * @param  list<array{name: string, nullable: bool}>  $columns
     * @param  list<array{unique: bool, primary: bool, columns: list<array{name: string, subPart: ?int}>}>  $indexes
     * @return list<string>|null
     */
    public static function rowKey(array $columns, array $indexes): ?array
    {
        $nullable = [];

        foreach ($columns as $column) {
            $nullable[$column['name']] = $column['nullable'];
        }

        usort($indexes, static fn (array $a, array $b): int => $b['primary'] <=> $a['primary']);

        foreach ($indexes as $index) {
            if (! $index['unique']) {
                continue;
            }

            $names = [];

            foreach ($index['columns'] as $column) {
                // A prefix index is unique on the prefix only; an expression
                // index has no column. Neither names a row by its values.
                if ($column['subPart'] !== null || ! isset($nullable[$column['name']]) || $nullable[$column['name']]) {
                    continue 2;
                }

                $names[] = $column['name'];
            }

            if ($names !== []) {
                return $names;
            }
        }

        return null;
    }
}
