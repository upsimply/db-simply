<?php

declare(strict_types=1);

namespace DbSimply;

/**
 * The signed-in user's own connections to the server, and stopping them.
 *
 * Without the PROCESS privilege a MariaDB or MySQL user sees only its own
 * threads anyway, and a PostgreSQL role sees the queries of its own sessions
 * only; the list is filtered to the user all the same, so a session never
 * sees or stops another account's queries even where the privilege is
 * broader. The connection doing the listing is left out.
 */
final class Processes
{
    public function __construct(private readonly Client $client, private readonly string $user) {}

    /**
     * @return list<array{id: int, database: ?string, command: string, seconds: int, state: ?string, query: ?string}>
     */
    public function all(): array
    {
        return $this->client->isPostgres() ? $this->postgres() : $this->mysql();
    }

    /**
     * Stop a process's running query, or the whole connection.
     */
    public function kill(mixed $id, bool $connection): void
    {
        foreach ($this->all() as $process) {
            if ($process['id'] === (is_numeric($id) ? (int) $id : -1)) {
                $this->client->statement($this->client->isPostgres()
                    ? sprintf('SELECT %s(%d)', $connection ? 'pg_terminate_backend' : 'pg_cancel_backend', $process['id'])
                    : sprintf('KILL %s %d', $connection ? 'CONNECTION' : 'QUERY', $process['id']));

                return;
            }
        }

        throw new UserError('That process has already finished.', 404);
    }

    /**
     * @return list<array{id: int, database: ?string, command: string, seconds: int, state: ?string, query: ?string}>
     */
    private function mysql(): array
    {
        $own = (int) $this->client->value('SELECT CONNECTION_ID()');
        $processes = [];

        foreach ($this->client->select('SHOW FULL PROCESSLIST') as $row) {
            if ((string) $row['User'] !== $this->user || (int) $row['Id'] === $own) {
                continue;
            }

            $processes[] = [
                'id' => (int) $row['Id'],
                'database' => $row['db'],
                'command' => (string) $row['Command'],
                'seconds' => (int) $row['Time'],
                'state' => $row['State'] === '' ? null : $row['State'],
                'query' => $row['Info'] === null ? null : mb_strcut((string) $row['Info'], 0, 2000, 'UTF-8'),
            ];
        }

        return $processes;
    }

    /**
     * The role's own backends, from pg_stat_activity. A query is shown while
     * it runs; an idle session shows the last one it ran, as PostgreSQL does.
     *
     * @return list<array{id: int, database: ?string, command: string, seconds: int, state: ?string, query: ?string}>
     */
    private function postgres(): array
    {
        return array_map(static fn (array $row): array => [
            'id' => (int) $row['pid'],
            'database' => $row['datname'],
            'command' => (string) ($row['state'] ?? 'unknown'),
            'seconds' => max(0, (int) $row['seconds']),
            'state' => $row['wait_event'],
            'query' => $row['query'] === null || $row['query'] === '' ? null : mb_strcut((string) $row['query'], 0, 2000, 'UTF-8'),
        ], $this->client->select(sprintf(
            "SELECT pid, datname, state, wait_event, query,
                    COALESCE(EXTRACT(EPOCH FROM now() - COALESCE(query_start, backend_start))::bigint, 0) AS seconds
             FROM pg_stat_activity
             WHERE usename = %s AND pid <> pg_backend_pid() AND backend_type = 'client backend'
             ORDER BY pid",
            $this->client->quote($this->user),
        )));
    }
}
