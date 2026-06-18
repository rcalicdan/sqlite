<?php

declare(strict_types=1);

namespace Hibla\Sqlite\ValueObjects;

final readonly class SqliteConfig
{
    /**
     * @param string $database Path to the SQLite database file, or ':memory:'.
     * @param int $busyTimeout Milliseconds to wait when the database is locked.
     * @param string $journalMode SQLite journal mode. Default is 'WAL' (Write-Ahead Logging).
     * @param bool $foreignKeys Whether to enforce foreign key constraints.
     * @param bool $killWorkerOnCancel Whether to forcefully kill the SQLite worker daemon if a query promise is cancelled. Defaults to false.
     * @param int $connectTimeout Compatibility with pool interfaces (seconds).
     * @param bool $forceSync Force synchronous execution, bypassing IPC workers entirely.
     */
    public function __construct(
        public string $database,
        public int $busyTimeout = 5000,
        public string $journalMode = 'WAL',
        public bool $foreignKeys = true,
        public bool $killWorkerOnCancel = false,
        public int $connectTimeout = 10,
        public bool $forceSync = false,
        public bool $resetConnection = false,
    ) {
        if ($this->busyTimeout < 0) {
            throw new \InvalidArgumentException('busyTimeout must be greater than or equal to zero.');
        }
    }

    /**
     * Parses a configuration array into a SqliteConfig instance.
     *
     * @param array<string, mixed> $config
     */
    public static function fromArray(array $config): self
    {
        $database = $config['database'] ?? throw new \InvalidArgumentException('Database path is required.');
        if (! \is_string($database)) {
            throw new \InvalidArgumentException('Database path must be a string.');
        }

        $busyTimeout = $config['busy_timeout'] ?? 5000;
        $busyTimeout = \is_numeric($busyTimeout) ? (int) $busyTimeout : 5000;

        $journalMode = $config['journal_mode'] ?? 'WAL';
        $journalMode = \is_string($journalMode) ? $journalMode : 'WAL';

        $foreignKeys = $config['foreign_keys'] ?? true;
        $foreignKeys = \is_scalar($foreignKeys) ? (bool) $foreignKeys : true;

        $killWorkerOnCancel = $config['kill_worker_on_cancel'] ?? false;
        $killWorkerOnCancel = \is_scalar($killWorkerOnCancel) ? (bool) $killWorkerOnCancel : false;

        $connectTimeout = $config['connect_timeout'] ?? 10;
        $connectTimeout = \is_numeric($connectTimeout) ? (int) $connectTimeout : 10;

        $forceSync = $config['force_sync'] ?? false;
        $forceSync = \is_scalar($forceSync) ? (bool) $forceSync : false;

        $resetConnection = $config['reset_connection'] ?? false;
        $resetConnection = \is_scalar($resetConnection) ? (bool) $resetConnection : false;

        return new self(
            database: $database,
            busyTimeout: $busyTimeout,
            journalMode: $journalMode,
            foreignKeys: $foreignKeys,
            killWorkerOnCancel: $killWorkerOnCancel,
            connectTimeout: $connectTimeout,
            forceSync: $forceSync,
            resetConnection: $resetConnection
        );
    }

    /**
      * Parses a DSN-like URI safely across all operating systems.
      */
    public static function fromUri(string $uri): self
    {
        $normalizedUri = preg_replace('/^sqlite3:\/\//i', 'sqlite://', $uri);
        if ($normalizedUri === null) {
            $normalizedUri = $uri;
        }

        $queryStr = '';
        $pathPart = $normalizedUri;

        if (($pos = strpos($normalizedUri, '?')) !== false) {
            $pathPart = substr($normalizedUri, 0, $pos);
            $queryStr = substr($normalizedUri, $pos + 1);
        }

        $prefix = 'sqlite://';
        if (str_starts_with($pathPart, $prefix)) {
            $path = substr($pathPart, strlen($prefix));
        } else {
            if (str_starts_with(strtolower($pathPart), 'sqlite:')) {
                $path = substr($pathPart, 7);
            } else {
                $path = $pathPart;
            }
        }

        $decodedPath = urldecode($path);
        $cleanPath = ltrim($decodedPath, '/');

        if ($cleanPath === ':memory:') {
            $database = ':memory:';
        } else {
            $database = $decodedPath;

            if (preg_match('/^\/[a-zA-Z]:/', $database) === 1) {
                $database = substr($database, 1);
            }

            if (str_starts_with($database, '//')) {
                $database = '/' . ltrim($database, '/');
            }
        }

        if ($database === '') {
            throw new \InvalidArgumentException('Invalid SQLite URI: ' . $uri);
        }

        $query = [];
        if ($queryStr !== '') {
            parse_str($queryStr, $query);
        }

        $journalMode = isset($query['journal_mode']) && \is_string($query['journal_mode'])
            ? $query['journal_mode']
            : 'WAL';

        return new self(
            database: $database,
            busyTimeout: isset($query['busy_timeout']) && \is_numeric($query['busy_timeout']) ? (int) $query['busy_timeout'] : 5000,
            journalMode: $journalMode,
            foreignKeys: isset($query['foreign_keys']) && \is_scalar($query['foreign_keys']) ? filter_var($query['foreign_keys'], FILTER_VALIDATE_BOOLEAN) : true,
            killWorkerOnCancel: isset($query['kill_worker_on_cancel']) && \is_scalar($query['kill_worker_on_cancel']) ? filter_var($query['kill_worker_on_cancel'], FILTER_VALIDATE_BOOLEAN) : false,
            forceSync: isset($query['force_sync']) && \is_scalar($query['force_sync']) ? filter_var($query['force_sync'], FILTER_VALIDATE_BOOLEAN) : false,
            resetConnection: isset($query['reset_connection']) && \is_scalar($query['reset_connection']) ? filter_var($query['reset_connection'], FILTER_VALIDATE_BOOLEAN) : false,
        );
    }
}
