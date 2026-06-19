<?php

declare(strict_types=1);

use Hibla\Sqlite\Interfaces\ConnectionInterface;
use Hibla\Sqlite\Internals\ConnectionFactory;
use Hibla\Sqlite\Manager\PoolManager;
use Hibla\Sqlite\SqliteClient;
use Hibla\Sqlite\ValueObjects\SqliteConfig;

use function Hibla\await;

function tempDbFile(): string
{
    return sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'hibla_sqlite_' . bin2hex(random_bytes(8)) . '.db';
}

/**
 * @param array<string, mixed> $overrides
 */
function dbConfig(array $overrides = []): SqliteConfig
{
    $db = $overrides['database'] ?? tempDbFile();

    return SqliteConfig::fromArray(array_merge([
        'database' => $db,
        'journal_mode' => 'WAL',
        'busy_timeout' => 5000,
    ], $overrides));
}

/**
 * @param array<string, mixed> $overrides
 */
function sqliteConn(array $overrides = []): ConnectionInterface
{
    return await(ConnectionFactory::create(dbConfig($overrides)));
}

/**
 * @param array<string, mixed> $overrides
 */
function makeClient(array $overrides = []): SqliteClient
{
    return new SqliteClient(
        config: dbConfig($overrides),
        minConnections: $overrides['minConnections'] ?? 0,
        maxConnections: $overrides['maxConnections'] ?? 5,
        idleTimeout: $overrides['idleTimeout'] ?? 60,
        maxLifetime: $overrides['maxLifetime'] ?? 3600,
        statementCacheSize: $overrides['statementCacheSize'] ?? 16,
        enableStatementCache: $overrides['enableStatementCache'] ?? true,
        maxWaiters: $overrides['maxWaiters'] ?? 0,
        acquireTimeout: $overrides['acquireTimeout'] ?? 10.0,
        onConnect: $overrides['onConnect'] ?? null,
        deleteDatabaseOnShutdown: $overrides['deleteDatabaseOnShutdown'] ?? true,
    );
}

/**
 * @param array<string, mixed> $overrides
 */
function makePool(array $overrides = []): PoolManager
{
    return new PoolManager(
        config: dbConfig($overrides),
        maxSize: $overrides['maxSize'] ?? 5,
        minSize: $overrides['minSize'] ?? 0,
        idleTimeout: $overrides['idleTimeout'] ?? 300,
        maxLifetime: $overrides['maxLifetime'] ?? 3600,
        maxWaiters: $overrides['maxWaiters'] ?? 0,
        acquireTimeout: $overrides['acquireTimeout'] ?? 0.0,
        onConnect: $overrides['onConnect'] ?? null,
        deleteDatabaseOnShutdown: $overrides['deleteDatabaseOnShutdown'] ?? true,
    );
}

function generateHeavyRowsQuery(int $limit = 50000): string
{
    return "
        WITH RECURSIVE
          t1(x) AS (SELECT 1 UNION ALL SELECT x+1 FROM t1 LIMIT 100), -- 100 rows
          t2(x) AS (SELECT 1 UNION ALL SELECT x+1 FROM t2 LIMIT 100), -- 100 rows
          t3(x) AS (SELECT 1 UNION ALL SELECT x+1 FROM t3 LIMIT 10)   -- 10 rows
        SELECT 
            (a.x * 1000) + (b.x * 10) + c.x AS id, 
            'some random heavy text to measure memory usage' AS payload
        FROM t1 a CROSS JOIN t2 b CROSS JOIN t3 c
        LIMIT {$limit};
    ";
}

function slowCteQuery(): string
{
    // A CPU-heavy, memory-light query to simulate a slow transaction
    return '
        WITH RECURSIVE cnt(x) AS (
            SELECT 1 UNION ALL SELECT x+1 FROM cnt WHERE x < 500000
        )
        SELECT max(x) FROM cnt;
    ';
}

function streamCancelQuery(): string
{
    $ten = 'SELECT 1 UNION ALL SELECT 1 UNION ALL SELECT 1 UNION ALL SELECT 1 UNION ALL SELECT 1 UNION ALL SELECT 1 UNION ALL SELECT 1 UNION ALL SELECT 1 UNION ALL SELECT 1 UNION ALL SELECT 1';

    return "
        SELECT 1 AS val FROM 
            ({$ten}) a CROSS JOIN 
            ({$ten}) b CROSS JOIN 
            ({$ten}) c CROSS JOIN 
            ({$ten}) d;
    ";
}
