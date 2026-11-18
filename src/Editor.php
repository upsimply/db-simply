<?php

declare(strict_types=1);

namespace DbSimply;

/**
 * Reads one row in full for editing, and inserts, updates and deletes rows.
 *
 * An existing row is only ever reached through its {@see RowKey}, and every
 * change touches at most one row per key: an UPDATE or DELETE carries a
 * condition that can match one row only, and LIMIT 1 on top of it where the
 * server has one (MariaDB and MySQL; PostgreSQL does not). Values are
 * written exactly as given -- null is NULL, and a column left out of an
 * insert gets its default -- and generated columns are never written.
 */
final class Editor
{
    private const int MAX_DELETE = 1000;

    private const array BINARY_TYPES = [
        'binary', 'varbinary', 'tinyblob', 'blob', 'mediumblob', 'longblob',
        'geometry', 'point', 'linestring', 'polygon', 'multipoint', 'multilinestring', 'multipolygon', 'geometrycollection',
    ];

    private const array LONG_TYPES = ['text', 'mediumtext', 'longtext', 'json', 'blob', 'mediumblob', 'longblob', 'jsonb', 'bytea', 'xml'];

    private const array NUMERIC_TYPES = [
        'tinyint', 'smallint', 'mediumint', 'int', 'integer', 'bigint', 'decimal', 'numeric', 'float', 'double', 'real', 'year', 'bit',
        'double precision',
    ];

    public function __construct(
        private readonly Client $client,
        private readonly Catalog $catalog,
        private readonly int $valuePreview,
    ) {}

    /**
     * The columns of a table as an edit form needs them.
     *
     * @return list<array<string, mixed>>
     */
    public function fields(string $database, string $table): array
    {
        return array_map(static fn (array $column): array => [
            'name' => $column['name'],
            'type' => $column['type'],
            'dataType' => $column['dataType'],
            'nullable' => $column['nullable'],
            'default' => $column['default'],
            'extra' => $column['extra'],
            'generated' => self::isGenerated($column),
            'autoIncrement' => stripos($column['extra'], 'auto_increment') !== false,
            'numeric' => in_array($column['dataType'], self::NUMERIC_TYPES, true),
            'binary' => in_array($column['dataType'], self::BINARY_TYPES, true),
            'long' => in_array($column['dataType'], self::LONG_TYPES, true),
            // MariaDB and MySQL spell the allowed values in the type itself;
            // a PostgreSQL enum is a type of its own and is edited as text.
            'options' => preg_match('/^(enum|set)\(/i', $column['type']) === 1 ? self::options($column['type']) : null,
            'comment' => $column['comment'],
        ], $this->catalog->columns($database, $table));
    }

    /**
     * One row's values, each in full up to the value preview limit. A value
     * longer than that comes back truncated and cannot be edited here.
     *
     * @return array{fields: list<array<string, mixed>>, values: array<string, mixed>, key: list<string>}
     */
    public function row(string $database, string $table, mixed $key): array
    {
        $rowKey = RowKey::of($this->catalog, $database, $table);
        $fields = $this->fields($database, $table);

        $select = [];

        foreach ($fields as $field) {
            $quoted = $this->client->identifier($field['name']);
            $select[] = $this->isBit($field)
                ? $quoted.' + 0'
                : $this->client->previewExpression($quoted, $this->valuePreview + 1, $field['dataType']);
            $select[] = $this->client->lengthExpression($quoted, $field['dataType']);
        }

        $rows = $this->client->selectRows(sprintf(
            'SELECT %s FROM %s WHERE %s LIMIT 2',
            implode(', ', $select),
            $this->client->qualified($database, $table),
            $rowKey->where($this->client, $key),
        ));

        if (count($rows) !== 1) {
            throw new UserError($rows === [] ? 'That row no longer exists.' : 'More than one row matches.', 404);
        }

        $values = [];

        foreach ($fields as $index => $field) {
            $value = $rows[0][$index * 2];
            $length = $rows[0][$index * 2 + 1];
            [$preview, $cut] = Codec::preview($value, $this->valuePreview);
            $values[$field['name']] = Codec::cell($preview, $this->isBit($field) ? null : ($length === null ? $cut : (int) $length));
        }

        return ['fields' => $fields, 'values' => $values, 'key' => $rowKey->columns];
    }

    /**
     * Insert a row. Columns left out get their default.
     *
     * @param  mixed  $values  column => value
     * @return array{insertId: ?string}
     */
    public function insert(string $database, string $table, mixed $values): array
    {
        $assignments = $this->assignments($database, $table, $values);
        $qualified = $this->client->qualified($database, $table);

        $sql = $assignments === []
            ? $this->client->insertDefaults($qualified)
            : sprintf(
                'INSERT INTO %s (%s) VALUES (%s)',
                $qualified,
                implode(', ', array_keys($assignments)),
                implode(', ', $assignments),
            );

        // PostgreSQL keeps no "last insert id": the generated value is asked
        // for with the INSERT itself, from the one auto-increment column.
        if ($this->client instanceof PgsqlClient) {
            $generated = array_values(array_filter(
                $this->catalog->columns($database, $table),
                static fn (array $column): bool => stripos($column['extra'], 'auto_increment') !== false || stripos($column['extra'], 'identity') !== false,
            ));

            if (count($generated) === 1) {
                return ['insertId' => $this->client->insertReturning($sql.' RETURNING '.$this->client->identifier($generated[0]['name']))];
            }

            $this->client->statement($sql);

            return ['insertId' => null];
        }

        $this->client->statement($sql);

        return ['insertId' => $this->client->insertId()];
    }

    /**
     * Change the given columns of one row.
     *
     * @param  mixed  $key  row key column => value
     * @param  mixed  $values  column => value, only the columns that change
     * @return array{changed: int}
     */
    public function update(string $database, string $table, mixed $key, mixed $values): array
    {
        $where = RowKey::of($this->catalog, $database, $table)->where($this->client, $key);
        $assignments = $this->assignments($database, $table, $values);

        if ($assignments === []) {
            return ['changed' => 0];
        }

        $from = $this->client->qualified($database, $table);

        // The row may have gone since the form was opened: say so, rather
        // than reporting a change to nothing as a success.
        if ((int) $this->client->value(sprintf('SELECT COUNT(*) FROM %s WHERE %s', $from, $where)) !== 1) {
            throw new UserError('That row no longer exists.', 404);
        }

        $changed = $this->client->statement(sprintf(
            'UPDATE %s SET %s WHERE %s%s',
            $from,
            implode(', ', array_map(static fn (string $column, string $value): string => $column.' = '.$value, array_keys($assignments), $assignments)),
            $where,
            $this->client->limitOne(),
        ));

        return ['changed' => $changed];
    }

    /**
     * Delete rows by their keys, all or none.
     *
     * @param  mixed  $keys  list of row key column => value
     * @return array{deleted: int}
     */
    public function delete(string $database, string $table, mixed $keys): array
    {
        if (! is_array($keys) || ! array_is_list($keys) || $keys === [] || count($keys) > self::MAX_DELETE) {
            throw new UserError(sprintf('Select between 1 and %d rows.', self::MAX_DELETE));
        }

        $rowKey = RowKey::of($this->catalog, $database, $table);
        $from = $this->client->qualified($database, $table);
        $conditions = array_map(fn (mixed $key): string => $rowKey->where($this->client, $key), $keys);

        $deleted = 0;

        $this->client->statement('START TRANSACTION');

        try {
            foreach ($conditions as $where) {
                $deleted += $this->client->statement(sprintf('DELETE FROM %s WHERE %s%s', $from, $where, $this->client->limitOne()));
            }

            $this->client->statement('COMMIT');
        } catch (UserError $e) {
            $this->client->statement('ROLLBACK');

            throw $e;
        }

        return ['deleted' => $deleted];
    }

    /**
     * Quoted column => SQL value, for the columns given.
     *
     * @return array<string, string>
     */
    private function assignments(string $database, string $table, mixed $values): array
    {
        if (! is_array($values) || ($values !== [] && array_is_list($values))) {
            throw new UserError('Expected column values.');
        }

        $columns = array_column($this->catalog->columns($database, $table), null, 'name');
        $assignments = [];

        foreach ($values as $name => $value) {
            $column = $columns[$name] ?? null;

            if ($column === null) {
                throw new UserError(sprintf('There is no column named "%s".', $name));
            }

            if (self::isGenerated($column)) {
                throw new UserError(sprintf('The column "%s" is generated, and cannot be written.', $name));
            }

            $bytes = Codec::decode($value);
            $sql = $this->client->quote($bytes);

            // A string is not a number to a BIT column: '1' would store the
            // byte 0x31. As a number it stores what was meant.
            if ($bytes !== null && $this->isBit($column)) {
                $sql = sprintf('CAST(%s AS UNSIGNED)', $sql);
            }

            $assignments[$this->client->identifier($name)] = $sql;
        }

        return $assignments;
    }

    /**
     * A MariaDB or MySQL BIT column, whose values are bytes on the wire and
     * are read and written as numbers instead. PostgreSQL's bit strings are
     * text already.
     *
     * @param  array{dataType: string}  $column
     */
    private function isBit(array $column): bool
    {
        return $column['dataType'] === 'bit' && ! $this->client->isPostgres();
    }

    /**
     * @param  array{extra: string}  $column
     */
    private static function isGenerated(array $column): bool
    {
        return stripos($column['extra'], 'generated') !== false && stripos($column['extra'], 'default_generated') === false;
    }

    /**
     * The allowed values of an ENUM or SET column type.
     *
     * @return list<string>
     */
    private static function options(string $type): array
    {
        preg_match_all("/'((?:[^'\\\\]|''|\\\\.)*)'/", $type, $matches);

        return array_map(static fn (string $option): string => str_replace("''", "'", stripcslashes($option)), $matches[1]);
    }
}
