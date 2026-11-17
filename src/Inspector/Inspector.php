<?php

declare(strict_types=1);

namespace DbSimply\Inspector;

/**
 * Reads what a server holds -- databases, tables and their structure -- in
 * the shapes the {@see \DbSimply\Catalog} hands on, whatever the server is.
 *
 * Nothing here decides what a user may open: the catalog filters and checks
 * the names, and passes only names it has resolved.
 */
interface Inspector
{
    /**
     * Every database the user can connect to, unfiltered.
     *
     * @return list<string>
     */
    public function databaseNames(): array;

    /**
     * Table count and size of each of the given databases, where known.
     *
     * @param  list<string>  $names
     * @return array<string, array{tables: ?int, size: ?int}>
     */
    public function databaseStats(array $names): array;

    /**
     * The tables and views in a database, in name order.
     *
     * @return list<array<string, mixed>>
     */
    public function tables(string $database): array;

    /**
     * The table or view with exactly this name, or null.
     *
     * @return array<string, mixed>|null
     */
    public function table(string $database, string $name): ?array;

    /**
     * @return list<array{name: string, type: string, dataType: string, nullable: bool, default: ?string, key: string, extra: string, collation: ?string, comment: string, octetLength: ?int}>
     */
    public function columns(string $database, string $table): array;

    /**
     * @return list<array{name: string, unique: bool, primary: bool, type: string, columns: list<array{name: string, subPart: ?int}>, comment: string}>
     */
    public function indexes(string $database, string $table): array;

    /**
     * @return list<array{name: string, columns: list<string>, referencedDatabase: string, referencedTable: string, referencedColumns: list<string>, onUpdate: string, onDelete: string}>
     */
    public function foreignKeys(string $database, string $table): array;

    /**
     * @return list<array{name: string, timing: string, event: string, statement: string}>
     */
    public function triggers(string $database, string $table): array;

    /**
     * The statement that would create the table or view.
     */
    public function createStatement(string $database, string $table): string;
}
