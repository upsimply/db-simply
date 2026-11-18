<?php

declare(strict_types=1);

namespace DbSimply\Inspector;

use DbSimply\Identifier;
use DbSimply\MysqlClient;

/**
 * MariaDB and MySQL, read through information_schema and SHOW.
 */
final class MysqlInspector implements Inspector
{
    public function __construct(private readonly MysqlClient $client) {}

    public function databaseNames(): array
    {
        return array_map(static fn (array $row): string => (string) $row[0], $this->client->selectRows('SHOW DATABASES'));
    }

    public function databaseStats(array $names): array
    {
        $stats = [];

        foreach ($this->client->select(sprintf(
            'SELECT TABLE_SCHEMA AS name, COUNT(*) AS tables, SUM(COALESCE(DATA_LENGTH, 0) + COALESCE(INDEX_LENGTH, 0)) AS size
             FROM information_schema.TABLES WHERE TABLE_SCHEMA IN (%s) GROUP BY TABLE_SCHEMA',
            implode(', ', array_map($this->client->quote(...), $names)),
        )) as $row) {
            $stats[(string) $row['name']] = ['tables' => (int) $row['tables'], 'size' => (int) $row['size']];
        }

        return $stats;
    }

    public function tables(string $database): array
    {
        return array_map($this->tableRow(...), $this->client->select(sprintf(
            '%s WHERE TABLE_SCHEMA = %s ORDER BY TABLE_NAME',
            self::TABLE_COLUMNS,
            $this->client->quote($database),
        )));
    }

    public function table(string $database, string $name): ?array
    {
        $rows = $this->client->select(sprintf(
            '%s WHERE TABLE_SCHEMA = %s AND TABLE_NAME = %s',
            self::TABLE_COLUMNS,
            $this->client->quote($database),
            $this->client->quote($name),
        ));

        // Table names compare case-insensitively on some servers; only the
        // exact name counts, so a request always names what it gets.
        foreach ($rows as $row) {
            if ($row['name'] === $name) {
                return $this->tableRow($row);
            }
        }

        return null;
    }

    /**
     * The columns of a table, in order.
     *
     * @return list<array{name: string, type: string, dataType: string, nullable: bool, default: ?string, key: string, extra: string, collation: ?string, comment: string, octetLength: ?int}>
     */
    public function columns(string $database, string $table): array
    {
        return array_map(static fn (array $row): array => [
            'name' => (string) $row['name'],
            'type' => (string) $row['type'],
            'dataType' => strtolower((string) $row['data_type']),
            'nullable' => $row['nullable'] === 'YES',
            'default' => $row['default_value'],
            'key' => (string) $row['column_key'],
            'extra' => (string) $row['extra'],
            'collation' => $row['collation'],
            'comment' => (string) $row['comment'],
            'octetLength' => $row['octet_length'] === null ? null : (int) $row['octet_length'],
        ], $this->client->select(sprintf(
            'SELECT COLUMN_NAME AS name, COLUMN_TYPE AS type, DATA_TYPE AS data_type, IS_NULLABLE AS nullable,
                    COLUMN_DEFAULT AS default_value, COLUMN_KEY AS column_key, EXTRA AS extra,
                    COLLATION_NAME AS collation, COLUMN_COMMENT AS comment, CHARACTER_OCTET_LENGTH AS octet_length
             FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = %s AND TABLE_NAME = %s
             ORDER BY ORDINAL_POSITION',
            $this->client->quote($database),
            $this->client->quote($table),
        )));
    }

    /**
     * The indexes of a table, each with its columns in order.
     *
     * @return list<array{name: string, unique: bool, primary: bool, type: string, columns: list<array{name: string, subPart: ?int}>, comment: string}>
     */
    public function indexes(string $database, string $table): array
    {
        $indexes = [];

        foreach ($this->client->select('SHOW INDEX FROM '.Identifier::qualified($database, $table)) as $row) {
            $name = (string) $row['Key_name'];

            $indexes[$name] ??= [
                'name' => $name,
                'unique' => (string) $row['Non_unique'] === '0',
                'primary' => $name === 'PRIMARY',
                'type' => (string) ($row['Index_type'] ?? ''),
                'columns' => [],
                'comment' => (string) ($row['Index_comment'] ?? ''),
            ];

            $indexes[$name]['columns'][] = [
                'name' => (string) ($row['Column_name'] ?? ''),
                'subPart' => isset($row['Sub_part']) ? (int) $row['Sub_part'] : null,
            ];
        }

        return array_values($indexes);
    }

    /**
     * The foreign keys a table declares.
     *
     * @return list<array{name: string, columns: list<string>, referencedDatabase: string, referencedTable: string, referencedColumns: list<string>, onUpdate: string, onDelete: string}>
     */
    public function foreignKeys(string $database, string $table): array
    {
        $keys = [];

        foreach ($this->client->select(sprintf(
            'SELECT k.CONSTRAINT_NAME AS name, k.COLUMN_NAME AS column_name,
                    k.REFERENCED_TABLE_SCHEMA AS ref_schema, k.REFERENCED_TABLE_NAME AS ref_table,
                    k.REFERENCED_COLUMN_NAME AS ref_column, r.UPDATE_RULE AS on_update, r.DELETE_RULE AS on_delete
             FROM information_schema.KEY_COLUMN_USAGE k
             JOIN information_schema.REFERENTIAL_CONSTRAINTS r
               ON r.CONSTRAINT_SCHEMA = k.CONSTRAINT_SCHEMA AND r.CONSTRAINT_NAME = k.CONSTRAINT_NAME AND r.TABLE_NAME = k.TABLE_NAME
             WHERE k.TABLE_SCHEMA = %s AND k.TABLE_NAME = %s AND k.REFERENCED_TABLE_NAME IS NOT NULL
             ORDER BY k.CONSTRAINT_NAME, k.ORDINAL_POSITION',
            $this->client->quote($database),
            $this->client->quote($table),
        )) as $row) {
            $name = (string) $row['name'];

            $keys[$name] ??= [
                'name' => $name,
                'columns' => [],
                'referencedDatabase' => (string) $row['ref_schema'],
                'referencedTable' => (string) $row['ref_table'],
                'referencedColumns' => [],
                'onUpdate' => (string) $row['on_update'],
                'onDelete' => (string) $row['on_delete'],
            ];

            $keys[$name]['columns'][] = (string) $row['column_name'];
            $keys[$name]['referencedColumns'][] = (string) $row['ref_column'];
        }

        return array_values($keys);
    }

    /**
     * The triggers on a table.
     *
     * @return list<array{name: string, timing: string, event: string, statement: string}>
     */
    public function triggers(string $database, string $table): array
    {
        return array_map(static fn (array $row): array => [
            'name' => (string) $row['name'],
            'timing' => (string) $row['timing'],
            'event' => (string) $row['event'],
            'statement' => (string) $row['statement'],
        ], $this->client->select(sprintf(
            'SELECT TRIGGER_NAME AS name, ACTION_TIMING AS timing, EVENT_MANIPULATION AS event, ACTION_STATEMENT AS statement
             FROM information_schema.TRIGGERS
             WHERE EVENT_OBJECT_SCHEMA = %s AND EVENT_OBJECT_TABLE = %s
             ORDER BY TRIGGER_NAME',
            $this->client->quote($database),
            $this->client->quote($table),
        )));
    }

    /**
     * The table's CREATE TABLE (or CREATE VIEW) statement.
     */
    public function createStatement(string $database, string $table): string
    {
        $rows = $this->client->selectRows('SHOW CREATE TABLE '.Identifier::qualified($database, $table));

        return (string) ($rows[0][1] ?? '');
    }

    private const string TABLE_COLUMNS = 'SELECT TABLE_NAME AS name, TABLE_TYPE AS type, ENGINE AS engine, TABLE_ROWS AS table_rows,
            DATA_LENGTH AS data_length, INDEX_LENGTH AS index_length, DATA_FREE AS data_free,
            TABLE_COLLATION AS collation, AUTO_INCREMENT AS auto_increment, CREATE_TIME AS created,
            UPDATE_TIME AS updated, TABLE_COMMENT AS comment
        FROM information_schema.TABLES';

    /**
     * @param  array<string, ?string>  $row
     * @return array<string, mixed>
     */
    private function tableRow(array $row): array
    {
        $isView = $row['type'] === 'VIEW';

        return [
            'name' => (string) $row['name'],
            'view' => $isView,
            'engine' => $row['engine'],
            'rows' => $row['table_rows'] === null ? null : (int) $row['table_rows'],
            // InnoDB only estimates its row count; MyISAM and Aria know it.
            'rowsExact' => ! $isView && in_array(strtolower((string) $row['engine']), ['myisam', 'aria', 'memory'], true),
            'dataLength' => (int) $row['data_length'],
            'indexLength' => (int) $row['index_length'],
            'dataFree' => (int) $row['data_free'],
            'collation' => $row['collation'],
            'autoIncrement' => $row['auto_increment'] === null ? null : (int) $row['auto_increment'],
            'created' => $row['created'],
            'updated' => $row['updated'],
            // Views report "VIEW" as their comment.
            'comment' => $isView ? '' : (string) $row['comment'],
        ];
    }
}
