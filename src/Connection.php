<?php

declare(strict_types=1);

namespace DbSimply;

use mysqli_sql_exception;
use PgSql\Connection as PgConnection;

/**
 * Opens the database connection a grant allows.
 *
 * Where the connection goes -- the driver, host, port or socket, and TLS --
 * comes from the configuration only. The grant supplies nothing but the
 * credentials (and, on PostgreSQL, the database to start in), and the
 * server's own privileges decide what those can reach.
 */
final class Connection
{
    public function __construct(private readonly Config $config) {}

    /**
     * @param  array{user: string, password: ?string, readonly?: bool, database?: ?string}  $grant
     */
    public function open(array $grant): Client
    {
        return $this->config->get('db.driver') === Client::PGSQL
            ? $this->openPostgres($grant)
            : $this->openMysql($grant);
    }

    /**
     * The port to connect to: the configured one, or the driver's own.
     */
    private function port(int $default): int
    {
        $port = $this->config->int('db.port');

        return $port > 0 ? $port : $default;
    }

    /**
     * @param  array{user: string, password: ?string, readonly?: bool}  $grant
     */
    private function openMysql(array $grant): Client
    {
        mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

        $mysqli = mysqli_init();

        if ($mysqli === false) {
            throw new UserError('Could not start a database connection.', 503);
        }

        // LOAD DATA LOCAL INFILE would let a query read files the web server
        // can see -- other sessions' sign-on tokens among them. Nothing here
        // needs it, so it is off whatever php.ini says.
        $mysqli->options(MYSQLI_OPT_LOCAL_INFILE, false);
        $mysqli->options(MYSQLI_OPT_CONNECT_TIMEOUT, max(1, $this->config->int('db.timeout')));
        $mysqli->options(MYSQLI_OPT_READ_TIMEOUT, max(1, $this->config->int('db.read_timeout')));
        $mysqli->options(MYSQLI_OPT_INT_AND_FLOAT_NATIVE, false);

        $flags = 0;

        if ((bool) $this->config->get('db.ssl')) {
            $ca = $this->config->get('db.ssl_ca');
            $mysqli->ssl_set(null, null, is_string($ca) && $ca !== '' ? $ca : null, null, null);
            $flags |= MYSQLI_CLIENT_SSL;

            if (! (bool) $this->config->get('db.ssl_verify')) {
                $flags |= MYSQLI_CLIENT_SSL_DONT_VERIFY_SERVER_CERT;
            }
        }

        $socket = $this->config->get('db.socket');

        try {
            $mysqli->real_connect(
                is_string($socket) && $socket !== '' ? 'localhost' : (string) $this->config->get('db.host'),
                $grant['user'],
                $grant['password'] ?? '',
                null,
                $this->port(3306),
                is_string($socket) && $socket !== '' ? $socket : null,
                $flags,
            );

            $mysqli->set_charset('utf8mb4');

            // A read-only session is held to read-only transactions from the
            // moment it connects, whatever path its statements take: the
            // console's checks, a query exported as CSV, or anything added
            // later. A stored function called from a SELECT cannot write
            // either.
            if (($grant['readonly'] ?? false) === true) {
                $mysqli->query('SET SESSION TRANSACTION READ ONLY');
            }
        } catch (mysqli_sql_exception $e) {
            // Access denied: the panel's credentials no longer work, which a
            // fresh sign-on from the panel repairs.
            if ($e->getCode() === 1045) {
                throw new UserError('The database refused these credentials. Open DB Simply again from your control panel.', 401);
            }

            error_log('db-simply: connection failed: '.$e->getMessage());

            throw new UserError('Could not connect to the database server.', 503);
        }

        return new MysqlClient($mysqli);
    }

    /**
     * Open a PostgreSQL connection to the grant's database, or the configured
     * one, or `postgres`. Every database it moves to later is a new
     * connection with the same credentials and the same session settings.
     *
     * @param  array{user: string, password: ?string, readonly?: bool, database?: ?string}  $grant
     */
    private function openPostgres(array $grant): Client
    {
        if (! function_exists('pg_connect')) {
            throw new UserError('The PHP pgsql extension is not installed.', 503);
        }

        $default = $this->config->get('db.database');
        $database = is_string($grant['database'] ?? null) && $grant['database'] !== ''
            ? $grant['database']
            : (is_string($default) && $default !== '' ? $default : 'postgres');

        $connect = fn (string $name): PgConnection => $this->connectPostgres($grant, $name);

        return new PgsqlClient($connect($database), $database, $connect);
    }

    /**
     * @param  array{user: string, password: ?string, readonly?: bool}  $grant
     */
    private function connectPostgres(array $grant, string $database): PgConnection
    {
        $socket = $this->config->get('db.socket');
        $parameters = [
            // A directory for a Unix socket, as libpq takes it.
            'host' => is_string($socket) && $socket !== '' ? $socket : (string) $this->config->get('db.host'),
            'port' => (string) $this->port(5432),
            'dbname' => $database,
            'user' => $grant['user'],
            'password' => $grant['password'] ?? '',
            'connect_timeout' => (string) max(1, $this->config->int('db.timeout')),
            'application_name' => 'db-simply',
            'client_encoding' => 'UTF8',
        ];

        if ((bool) $this->config->get('db.ssl')) {
            $ca = $this->config->get('db.ssl_ca');
            $parameters['sslmode'] = (bool) $this->config->get('db.ssl_verify') ? 'verify-full' : 'require';

            if (is_string($ca) && $ca !== '') {
                $parameters['sslrootcert'] = $ca;
            }
        } else {
            $parameters['sslmode'] = 'prefer';
        }

        $dsn = implode(' ', array_map(
            static fn (string $key, string $value): string => $key."='".addcslashes($value, "'\\")."'",
            array_keys($parameters),
            $parameters,
        ));

        $error = null;
        set_error_handler(static function (int $level, string $message) use (&$error): bool {
            $error = $message;

            return true;
        });

        try {
            $connection = pg_connect($dsn, PGSQL_CONNECT_FORCE_NEW);
        } finally {
            restore_error_handler();
        }

        if (! $connection instanceof PgConnection) {
            $message = (string) $error;

            if (str_contains($message, 'password authentication failed') || str_contains($message, 'no pg_hba.conf entry')) {
                throw new UserError('The database refused these credentials. Open DB Simply again from your control panel.', 401);
            }

            if (str_contains($message, 'permission denied for database') || (str_contains($message, 'database "') && str_contains($message, 'does not exist'))) {
                throw new UserError('That database does not exist, or you cannot open it.', 404);
            }

            error_log('db-simply: connection failed: '.$message);

            throw new UserError('Could not connect to the database server.', 503);
        }

        // The same guarantees as on MariaDB and MySQL: a read-only session
        // starts every transaction read-only, and no statement outlives the
        // read timeout the web server would give up after anyway.
        $settings = [sprintf('SET statement_timeout = %d', max(1, $this->config->int('db.read_timeout')) * 1000)];

        if (($grant['readonly'] ?? false) === true) {
            $settings[] = 'SET default_transaction_read_only = on';
        }

        foreach ($settings as $setting) {
            if (@pg_query($connection, $setting) === false) {
                pg_close($connection);

                throw new UserError('Could not prepare the database session.', 503);
            }
        }

        return $connection;
    }
}
