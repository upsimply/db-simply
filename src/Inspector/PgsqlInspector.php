<?php

declare(strict_types=1);

namespace DbSimply\Inspector;

use DbSimply\PgsqlClient;

/**
 * PostgreSQL, read through pg_catalog.
 *
 * A database's tables are those of the connection's current schema, as the
 * {@see PgsqlClient} qualifies them. The shapes are the ones MariaDB and
 * MySQL fill, so everything above reads them the same way: `dataType` is
 * PostgreSQL's own type name (`integer`, `character varying`, `jsonb`), the
 * row count is the planner's estimate, and an identity or serial column
 * reads as auto-increment.
 */
final class PgsqlInspector implements Inspector
{
    /**
     * Type names as pg_type spells them, mapped to the SQL names the rest of
     * the app compares against.
     */
    private const array TYPE_NAMES = [
        'int2' => 'smallint',
        'int4' => 'integer',
        'int8' => 'bigint',
        'float4' => 'real',
        'float8' => 'double precision',
        'bool' => 'boolean',
        'varchar' => 'character varying',
        'bpchar' => 'character',
        'timestamptz' => 'timestamp with time zone',
        'timestamp' => 'timestamp without time zone',
        'timetz' => 'time with time zone',
        'time' => 'time without time zone',
    ];

    /**
     * pg_constraint's referential action codes.
     */
    private const array ACTIONS = [
        'a' => 'NO ACTION',
        'r' => 'RESTRICT',
        'c' => 'CASCADE',
        'n' => 'SET NULL',
        'd' => 'SET DEFAULT',
    ];

    /**
     * The octet length given to an unbounded character varying, so a value
     * of one is read for a page as the start of a long one.
     */
    private const int UNBOUNDED = 1 << 30;

    public function __construct(private readonly PgsqlClient $client) {}

    public function databaseNames(): array
    {
        return array_map(static fn (array $row): string => (string) $row[0], $this->client->selectRows(
            "SELECT datname FROM pg_database
             WHERE NOT datistemplate AND datallowconn AND has_database_privilege(datname, 'CONNECT')
             ORDER BY datname"
        ));
    }

    /**
     * Sizes come from the server for every database; tables can only be
     * counted in the one connected to, so the others' counts are unknown.
     */
    public function databaseStats(array $names): array
    {
        if ($names === []) {
            return [];
        }

        $stats = [];

        foreach ($this->client->select(sprintf(
            'SELECT datname AS name, pg_database_size(datname) AS size FROM pg_database WHERE datname IN (%s)',
            implode(', ', array_map($this->client->quote(...), $names)),
        )) as $row) {
            $stats[(string) $row['name']] = ['tables' => null, 'size' => (int) $row['size']];
        }

        $current = $this->client->database();

        if (isset($stats[$current])) {
            $stats[$current]['tables'] = count($this->tables($current));
        }

        return $stats;
    }

    public function tables(string $database): array
    {
        $this->client->useDatabase($database);

        return array_map($this->tableRow(...), $this->client->select(self::tableQuery('')));
    }

    public function table(string $database, string $name): ?array
    {
        $this->client->useDatabase($database);

        $rows = $this->client->select(self::tableQuery(' AND c.relname = '.$this->client->quote($name)));

        return $rows === [] ? null : $this->tableRow($rows[0]);
    }

    public function columns(string $database, string $table): array
    {
        $keys = $this->primaryColumns($database, $table);

        return array_map(function (array $row) use ($keys): array {
            $typeName = (string) $row['typname'];
            $dataType = match (true) {
                $row['typcategory'] === 'A' => 'array',
                $row['typtype'] === 'e' => 'enum',
                default => self::TYPE_NAMES[$typeName] ?? $typeName,
            };

            $default = $row['default_value'];
            $extra = match (true) {
                $row['generated'] === 's' => 'STORED GENERATED',
                $row['identity'] === 'a' => 'GENERATED ALWAYS AS IDENTITY',
                $row['identity'] === 'd', is_string($default) && str_starts_with($default, 'nextval(') => 'auto_increment',
                default => '',
            };

            $modifier = (int) $row['atttypmod'];

            return [
                'name' => (string) $row['name'],
                'type' => (string) $row['type'],
                'dataType' => $dataType,
                'nullable' => $row['nullable'] === 't',
                // A generated column's expression is not a default.
                'default' => $row['generated'] === 's' ? null : $default,
                'key' => in_array((string) $row['name'], $keys, true) ? 'PRI' : '',
                'extra' => $extra,
                'collation' => $row['collation'],
                'comment' => (string) $row['comment'],
                'octetLength' => match ($typeName) {
                    'varchar', 'bpchar' => $modifier > 4 ? ($modifier - 4) * 4 : self::UNBOUNDED,
                    default => null,
                },
                'expression' => $row['generated'] === 's' ? $default : null,
                'identity' => $row['identity'],
            ];
        }, $this->client->select(sprintf(
            "SELECT a.attname AS name, format_type(a.atttypid, a.atttypmod) AS type, a.atttypmod,
                    t.typname, t.typcategory, t.typtype, NOT a.attnotnull AS nullable,
                    pg_get_expr(d.adbin, d.adrelid) AS default_value, a.attidentity AS identity,
                    a.attgenerated AS generated, col_description(a.attrelid, a.attnum) AS comment,
                    CASE WHEN a.attcollation <> t.typcollation THEN co.collname END AS collation
             FROM pg_attribute a
             JOIN pg_type t ON t.oid = a.atttypid
             LEFT JOIN pg_attrdef d ON d.adrelid = a.attrelid AND d.adnum = a.attnum
             LEFT JOIN pg_collation co ON co.oid = a.attcollation
             WHERE a.attrelid = %s AND a.attnum > 0 AND NOT a.attisdropped
             ORDER BY a.attnum",
            $this->relation($database, $table),
        )));
    }

    public function indexes(string $database, string $table): array
    {
        $indexes = [];

        foreach ($this->client->select(sprintf(
            "SELECT i.relname AS name, x.indisunique AS is_unique, x.indisprimary AS is_primary,
                    x.indpred IS NOT NULL AS partial, am.amname AS type,
                    COALESCE(a.attname, '') AS column_name, obj_description(i.oid, 'pg_class') AS comment
             FROM pg_index x
             JOIN pg_class i ON i.oid = x.indexrelid
             JOIN pg_am am ON am.oid = i.relam
             CROSS JOIN LATERAL unnest(x.indkey) WITH ORDINALITY AS k(attnum, ord)
             LEFT JOIN pg_attribute a ON a.attrelid = x.indrelid AND a.attnum = k.attnum AND k.attnum > 0
             WHERE x.indrelid = %s AND k.ord <= x.indnkeyatts
             ORDER BY x.indisprimary DESC, i.relname, k.ord",
            $this->relation($database, $table),
        )) as $row) {
            $name = (string) $row['name'];

            $indexes[$name] ??= [
                'name' => $name,
                // A partial index is unique only over the rows its predicate
                // covers, so it cannot pick a row out of the whole table.
                'unique' => $row['is_unique'] === 't' && $row['partial'] !== 't',
                'primary' => $row['is_primary'] === 't',
                'type' => strtoupper((string) $row['type']),
                'columns' => [],
                'comment' => (string) $row['comment'],
            ];

            // An expression in the index has no column; an empty name is
            // what Catalog::rowKey() skips such an index by.
            $indexes[$name]['columns'][] = ['name' => (string) $row['column_name'], 'subPart' => null];
        }

        return array_values($indexes);
    }

    public function foreignKeys(string $database, string $table): array
    {
        $keys = [];

        foreach ($this->client->select(sprintf(
            "SELECT c.conname AS name, a.attname AS column_name, fa.attname AS ref_column,
                    fn.nspname AS ref_schema, fc.relname AS ref_table,
                    c.confupdtype AS on_update, c.confdeltype AS on_delete
             FROM pg_constraint c
             CROSS JOIN LATERAL unnest(c.conkey, c.confkey) WITH ORDINALITY AS k(attnum, fattnum, ord)
             JOIN pg_attribute a ON a.attrelid = c.conrelid AND a.attnum = k.attnum
             JOIN pg_class fc ON fc.oid = c.confrelid
             JOIN pg_namespace fn ON fn.oid = fc.relnamespace
             JOIN pg_attribute fa ON fa.attrelid = c.confrelid AND fa.attnum = k.fattnum
             WHERE c.conrelid = %s AND c.contype = 'f'
             ORDER BY c.conname, k.ord",
            $this->relation($database, $table),
        )) as $row) {
            $name = (string) $row['name'];

            $keys[$name] ??= [
                'name' => $name,
                'columns' => [],
                'referencedDatabase' => (string) $row['ref_schema'],
                'referencedTable' => (string) $row['ref_table'],
                'referencedColumns' => [],
                'onUpdate' => self::ACTIONS[(string) $row['on_update']] ?? 'NO ACTION',
                'onDelete' => self::ACTIONS[(string) $row['on_delete']] ?? 'NO ACTION',
            ];

            $keys[$name]['columns'][] = (string) $row['column_name'];
            $keys[$name]['referencedColumns'][] = (string) $row['ref_column'];
        }

        return array_values($keys);
    }

    public function triggers(string $database, string $table): array
    {
        $this->client->useDatabase($database);

        return array_map(static fn (array $row): array => [
            'name' => (string) $row['name'],
            'timing' => (string) $row['timing'],
            'event' => (string) $row['event'],
            'statement' => (string) $row['statement'],
        ], $this->client->select(sprintf(
            "SELECT trigger_name AS name, action_timing AS timing,
                    string_agg(event_manipulation, ' OR ' ORDER BY event_manipulation) AS event,
                    action_statement AS statement
             FROM information_schema.triggers
             WHERE event_object_schema = current_schema() AND event_object_table = %s
             GROUP BY trigger_name, action_timing, action_statement
             ORDER BY trigger_name",
            $this->client->quote($table),
        )));
    }

    /**
     * A CREATE statement put together from the catalog.
     *
     * PostgreSQL keeps no CREATE TABLE text and has no SHOW CREATE TABLE, so
     * the statement is rebuilt the way pg_dump would write the table: its
     * columns, then its constraints, then the indexes no constraint owns.
     * A view's is its definition.
     */
    public function createStatement(string $database, string $table): string
    {
        $summary = $this->table($database, $table);
        $qualified = $this->client->qualified($database, $table);

        if ($summary !== null && $summary['view']) {
            $definition = (string) $this->client->value(sprintf('SELECT pg_get_viewdef(%s, true)', $this->relation($database, $table)));
            $materialized = ($summary['kind'] ?? '') === 'm';

            return sprintf("CREATE %sVIEW %s AS\n%s", $materialized ? 'MATERIALIZED ' : '', $qualified, rtrim(trim($definition), ';').';');
        }

        $lines = [];

        foreach ($this->columns($database, $table) as $column) {
            $line = '    '.$this->client->identifier($column['name']).' '.$column['type'];

            if ($column['collation'] !== null) {
                $line .= ' COLLATE '.$this->client->identifier($column['collation']);
            }

            $line .= match (true) {
                $column['expression'] !== null => ' GENERATED ALWAYS AS ('.$column['expression'].') STORED',
                $column['identity'] === 'a' => ' GENERATED ALWAYS AS IDENTITY',
                $column['identity'] === 'd' => ' GENERATED BY DEFAULT AS IDENTITY',
                $column['default'] !== null => ' DEFAULT '.$column['default'],
                default => '',
            };

            if (! $column['nullable']) {
                $line .= ' NOT NULL';
            }

            $lines[] = $line;
        }

        $relation = $this->relation($database, $table);

        foreach ($this->client->select(sprintf(
            "SELECT conname AS name, pg_get_constraintdef(oid, true) AS definition
             FROM pg_constraint WHERE conrelid = %s AND contype IN ('p', 'u', 'f', 'c', 'x')
             ORDER BY CASE contype WHEN 'p' THEN 0 WHEN 'u' THEN 1 WHEN 'c' THEN 2 ELSE 3 END, conname",
            $relation,
        )) as $constraint) {
            $lines[] = '    CONSTRAINT '.$this->client->identifier((string) $constraint['name']).' '.$constraint['definition'];
        }

        $statements = [sprintf("CREATE TABLE %s (\n%s\n);", $qualified, implode(",\n", $lines))];

        foreach ($this->client->selectRows(sprintf(
            'SELECT pg_get_indexdef(x.indexrelid) FROM pg_index x
             WHERE x.indrelid = %s AND NOT EXISTS (SELECT 1 FROM pg_constraint c WHERE c.conindid = x.indexrelid)
             ORDER BY x.indexrelid',
            $relation,
        )) as $index) {
            $statements[] = $index[0].';';
        }

        if (($summary['comment'] ?? '') !== '') {
            $statements[] = sprintf('COMMENT ON TABLE %s IS %s;', $qualified, $this->client->quote((string) $summary['comment']));
        }

        return implode("\n\n", $statements);
    }

    /**
     * The columns of the primary key.
     *
     * @return list<string>
     */
    private function primaryColumns(string $database, string $table): array
    {
        foreach ($this->indexes($database, $table) as $index) {
            if ($index['primary']) {
                return array_column($index['columns'], 'name');
            }
        }

        return [];
    }

    /**
     * The table as a regclass literal, resolved in the current schema.
     */
    private function relation(string $database, string $table): string
    {
        return $this->client->quote($this->client->qualified($database, $table)).'::regclass';
    }

    /**
     * The relations of the current schema a user may read, with their sizes.
     */
    private static function tableQuery(string $condition): string
    {
        return "SELECT c.relname AS name, c.relkind AS kind, c.reltuples::bigint AS table_rows,
                       pg_table_size(c.oid) AS data_length, pg_indexes_size(c.oid) AS index_length,
                       obj_description(c.oid, 'pg_class') AS comment
                FROM pg_class c
                JOIN pg_namespace n ON n.oid = c.relnamespace
                WHERE n.nspname = current_schema() AND c.relkind IN ('r', 'p', 'v', 'm', 'f')
                  AND has_table_privilege(c.oid, 'SELECT')".$condition.'
                ORDER BY c.relname';
    }

    /**
     * @param  array<string, ?string>  $row
     * @return array<string, mixed>
     */
    private function tableRow(array $row): array
    {
        $isView = in_array($row['kind'], ['v', 'm'], true);
        $rows = (int) $row['table_rows'];

        return [
            'name' => (string) $row['name'],
            'view' => $isView,
            'kind' => (string) $row['kind'],
            'engine' => null,
            // reltuples is the planner's estimate, and -1 before the table
            // was first analysed: unknown, not empty.
            'rows' => $isView || $rows < 0 ? null : $rows,
            'rowsExact' => false,
            'dataLength' => (int) $row['data_length'],
            'indexLength' => (int) $row['index_length'],
            'dataFree' => 0,
            'collation' => null,
            'autoIncrement' => null,
            'created' => null,
            'updated' => null,
            'comment' => (string) $row['comment'],
        ];
    }
}
