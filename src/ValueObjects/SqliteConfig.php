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
     */
    public function __construct(
        public string $database,
        public int $busyTimeout = 5000,
        public string $journalMode = 'WAL',
        public bool $foreignKeys = true,
        public bool $killWorkerOnCancel = false,
        public int $connectTimeout = 10,
    ) {
        if ($this->busyTimeout < 0) {
            throw new \InvalidArgumentException('busyTimeout must be greater than or equal to zero.');
        }
    }

    /**
     * Parses a configuration array into a SqliteConfig instance.
     *
     * Recognised keys:
     *   database, busy_timeout, journal_mode, foreign_keys, kill_worker_on_cancel, connect_timeout
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

        return new self(
            database: $database,
            busyTimeout: $busyTimeout,
            journalMode: $journalMode,
            foreignKeys: $foreignKeys,
            killWorkerOnCancel: $killWorkerOnCancel,
            connectTimeout: $connectTimeout
        );
    }

    /**
     * Parses a DSN-like URI, e.g., sqlite:///var/www/data/db.sqlite?busy_timeout=5000
     */
    public static function fromUri(string $uri): self
    {
        $parts = parse_url($uri);
        if ($parts === false || ! isset($parts['path'])) {
            throw new \InvalidArgumentException('Invalid SQLite URI: ' . $uri);
        }

        $query = [];
        if (isset($parts['query']) && \is_string($parts['query'])) {
            parse_str($parts['query'], $query);
        }

        $journalMode = isset($query['journal_mode']) && \is_string($query['journal_mode'])
            ? $query['journal_mode']
            : 'WAL';

        return new self(
            database: $parts['path'] === ':memory:' ? ':memory:' : urldecode($parts['path']),
            busyTimeout: isset($query['busy_timeout']) && \is_numeric($query['busy_timeout']) ? (int) $query['busy_timeout'] : 5000,
            journalMode: $journalMode,
            foreignKeys: isset($query['foreign_keys']) && \is_scalar($query['foreign_keys']) ? filter_var($query['foreign_keys'], FILTER_VALIDATE_BOOLEAN) : true,
            killWorkerOnCancel: isset($query['kill_worker_on_cancel']) && \is_scalar($query['kill_worker_on_cancel']) ? filter_var($query['kill_worker_on_cancel'], FILTER_VALIDATE_BOOLEAN) : false,
        );
    }

    /**
     * Helper to clone with a modified cancellation setting.
     */
    public function withQueryCancellation(bool $enabled): self
    {
        return new self(
            database: $this->database,
            busyTimeout: $this->busyTimeout,
            journalMode: $this->journalMode,
            foreignKeys: $this->foreignKeys,
            killWorkerOnCancel: $enabled,
            connectTimeout: $this->connectTimeout,
        );
    }
}
