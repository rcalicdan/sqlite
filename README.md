# Hibla SQLite Client

**A modern, async-first, high-performance SQLite3 client for PHP with true non-blocking I/O via isolated worker processes, robust connection pooling, and smart backpressure streaming.**

[![Latest Release](https://img.shields.io/github/release/hiblaphp/sqlite.svg?style=flat-square)](https://github.com/hiblaphp/sqlite/releases)
[![Total Downloads](https://img.shields.io/packagist/dt/hiblaphp/sqlite.svg?style=flat-square)](https://packagist.org/packages/hiblaphp/sqlite)
[![MIT License](https://img.shields.io/badge/license-MIT-blue.svg?style=flat-square)](./LICENSE)

---

## Features

| Feature | Status | Notes |
|---|---|---|
| True Non-Blocking I/O | Supported | Achieved via isolated IPC worker processes. The event loop is never blocked by SQLite disk I/O. |
| Sync Fallback | Supported | Transparently falls back to synchronous execution if `proc_open` is disabled or forced via config. |
| Lazy connection pooling | Supported | No worker processes spawned until the first query. |
| Parameterized queries | Supported | SQL-injection safe via prepared statements. |
| BLOB / Binary Data | Supported | Automatically serializes raw binary data (e.g. UUIDs, encryption keys) over the IPC pipe without corruption. |
| Named parameters (`:name`) | Supported | Parsed client-side, works with `query()`, `prepare()`, and all transaction methods. |
| Positional `?` parameters | Supported | Supported natively. |
| Prepared statements | Supported | Explicit lifecycle control with `prepare()` and `close()`. |
| Statement caching | Supported | Per-connection LRU cache, eliminates repeated `PREPARE` round-trips over IPC. |
| Streaming results | Supported | Row-by-row delivery with IPC backpressure. Safely stream millions of rows with stable memory usage. |
| Transactions | Supported | High-level `transaction()` with auto commit/rollback and retry, low-level `beginTransaction()`. |
| Savepoints | Supported | `savepoint()`, `rollbackTo()`, `releaseSavepoint()`. |
| Query cancellation | Supported | Configurable cancellation logic (`kill_worker_on_cancel`) to drop queries or terminate OS process trees. |
| Self-healing workers | Supported | Workers auto-exit on memory bloat (`memory_limit_mb`) and are transparently respawned by the pool. |
| Boot protection | Supported | "Thundering herd" retry logic prevents `-wal` and `-shm` locking collisions on high-concurrency boot. |
| Health checks | Supported | `healthCheck()` pings idle workers and evicts stale ones. |
| Pool stats | Supported | `$client->stats` for live pool and worker introspection. |
| `hiblaphp/sql` contracts | Supported | Fully implements `SqlClientInterface`, drivers are swappable. |

---

## Contents

**Getting started**
- [Installation](#installation)
- [Quick start](#quick-start)
- [How it works: The IPC Architecture](#how-it-works-the-ipc-architecture)
- [hiblaphp/sql contracts](#hiblaphpsql-contracts)

**Configuration**
- [SqliteConfig](#sqliteconfig)
  - [Construction](#construction)
  - [Properties](#properties)

**Core API**
- [The SqliteClient](#the-sqliteclient)
- [Making queries](#making-queries)
  - [Queries with parameters](#queries-with-parameters-prepared-statements)
  - [Convenience methods](#convenience-methods)
  - [Custom Built-in Functions](#custom-built-in-functions)
- [Prepared statements](#prepared-statements)
- [Streaming results](#streaming-results)
- [Transactions](#transactions)
  - [High-level API: `transaction()`](#high-level-api-transaction)
  - [Automatic retry](#automatic-retry)
  - [Low-level API: `beginTransaction()`](#low-level-api-begintransaction)
  - [Tainted state & Savepoints](#tainted-state--savepoints)

**Advanced features**
- [Connection pooling](#connection-pooling)
- [Worker lifecycle & memory management](#worker-lifecycle--memory-management)
- [Query cancellation](#query-cancellation)
- [Sync Fallback](#sync-fallback)
- [Reset Connection & Caching](#reset-connection--caching)
- [Platform Notes & Quirks](#platform-notes--quirks)

**Working with responses**
- [Result inspection](#result-inspection)

**Development**
- [Development & Testing](#development)

**Meta**
- [License](#license)

---

## Installation

> This package is currently in **beta**. Before installing, ensure your `composer.json` allows beta releases:

```json
{
    "minimum-stability": "beta",
    "prefer-stable": true
}
```

```bash
composer require hiblaphp/sqlite
```

**Requirements:**
- PHP 8.4+
- The `sqlite3` PHP extension

---

## Quick start

```php
use Hibla\Sqlite\SqliteClient;
use function Hibla\await;

// The client is lazy. No workers are spawned until the first query.
$client = new SqliteClient('sqlite:///path/to/database.sqlite');

// Simple query
$users = await($client->query('SELECT * FROM users WHERE active = ?', [true]));
echo $users->rowCount;

// Named parameters
$user = await(
    $client->query(
        'SELECT * FROM users WHERE email = :email AND status = :status',
        ['email' => 'alice@example.com', 'status' => 'active']
    )
);

// Prepared statement (recommended for repeated execution)
$stmt = await(
    $client->prepare('SELECT * FROM users WHERE email = :email')
);
$result = await($stmt->execute(['email' => 'alice@example.com']));
await($stmt->close());

// Streaming large result sets
$stream = await($client->stream('SELECT * FROM logs ORDER BY id DESC'));
foreach ($stream as $row) {
    processLog($row);
}
```

---

## How it works: The IPC Architecture

The native PHP `sqlite3` extension is inherently **blocking**. When you execute a query, it halts the entire PHP process until the disk I/O completes. 

To achieve true non-blocking, asynchronous behaviour, `SqliteClient` relies on network sockets. SQLite doesn't have a network server. To solve this, `hiblaphp/sqlite` uses **Isolated IPC Worker Daemons**.

When an async connection is required, the library spawns a background PHP CLI process (`proc_open`) dedicated to that connection. Commands (queries, prepare, stream) are sent from the parent event loop to the worker via standard input (`STDIN`) using Newline-Delimited JSON (NDJSON). The worker executes the blocking SQLite call and streams the results back to the parent via `STDOUT`. 

This architecture guarantees that your main application's event loop **never blocks on disk I/O**, allowing you to run thousands of concurrent tasks while a pool of background workers safely handles the SQLite database.

> If `proc_open` is disabled in your environment, or if you explicitly request it, the client transparently degrades to a `SyncConnection` fallback, executing queries normally in the main thread.

---

## hiblaphp/sql contracts

`SqliteClient` fully implements the [`hiblaphp/sql`](https://github.com/hiblaphp/sql) contract package, defining the common interfaces shared across all Hibla database drivers:

| Interface | Implemented by |
|---|---|
| `SqlClientInterface` | `SqliteClient` |
| `PreparedStatement` | `ManagedPreparedStatement`, `TransactionPreparedStatement` |
| `Transaction` | `Transaction` |
| `Result` | `Result` (implements `SqliteResult`) |
| `RowStream` | `SqliteRowStream`, `SyncRowStream` |

This means you can type-hint against `SqlClientInterface` in your application code and swap the underlying driver (e.g., to Postgres or MySQL) without changing your business logic.

---

## `SqliteConfig`

`SqliteConfig` is the canonical, immutable connection-level configuration object. All three config formats accepted by `SqliteClient` (DSN string, associative array, and `SqliteConfig` directly) are normalised to this type internally.

### Construction

**Direct constructor:**
```php
use Hibla\Sqlite\ValueObjects\SqliteConfig;

$config = new SqliteConfig(
    database: '/var/data/production.sqlite',
    busyTimeout: 5000,
    journalMode: 'WAL',
    memoryLimitMB: 64,
);
```

**Array parser:**
```php
$config = SqliteConfig::fromArray([
    'database'              => '/var/data/production.sqlite',
    'busy_timeout'          => 5000,
    'journal_mode'          => 'WAL',
    'foreign_keys'          => true,
    'kill_worker_on_cancel' => false,
    'force_sync'            => false,
    'memory_limit_mb'       => 128,
]);
```

**URI / DSN string:**
```php
$config = SqliteConfig::fromUri(
    'sqlite:///var/data/production.sqlite?busy_timeout=5000&journal_mode=WAL&memory_limit_mb=128'
);

// For in-memory testing:
$memoryConfig = SqliteConfig::fromUri('sqlite:///:memory:');
```

### Properties

| Property | Type | Default | Description |
|---|---|---|---|
| `database` | `string` | required | Path to the `.sqlite` file, or `:memory:`. |
| `busyTimeout` | `int` | `5000` | Milliseconds SQLite will wait for a lock before throwing a `LockWaitTimeoutException` (SQLITE_BUSY). |
| `journalMode` | `string` | `'WAL'` | PRAGMA journal_mode. Write-Ahead Logging (`WAL`) is highly recommended for concurrent access. |
| `foreignKeys` | `bool` | `true` | PRAGMA foreign_keys. Enforces relational constraints. |
| `killWorkerOnCancel` | `bool` | `false` | Action taken on promise cancellation. See [Query cancellation](#query-cancellation). |
| `memoryLimitMB` | `int` | `128` | Max RAM the background worker daemon can consume before auto-restarting. See [Worker lifecycle](#worker-lifecycle--memory-management). |
| `resetConnection` | `bool` | `false` | Drops temp tables and resets PRAGMAs mimicking `DISCARD ALL` when released to the pool. |
| `forceSync` | `bool` | `false` | If `true`, bypasses background IPC workers entirely and runs standard blocking SQLite in the main thread. |
| `connectTimeout` | `int` | `10` | Seconds before a connect attempt is aborted. |

---

## The `SqliteClient`

```php
use Hibla\Sqlite\SqliteClient;

$client = new SqliteClient(
    config: 'sqlite:///database.sqlite',
    minConnections: 0,
    maxConnections: 10,
    idleTimeout: 60,
    maxLifetime: 3600,
    statementCacheSize: 256,
    enableStatementCache: true,
    maxWaiters: 100,
    acquireTimeout: 10.0,
    deleteDatabaseOnShutdown: false,
);
```

### Constructor parameters

| Parameter | Type | Default | Description |
|---|---|---|---|
| `$config` | `SqliteConfig\|array\|string` | required | Database configuration. |
| `$minConnections` | `int` | `0` | Minimum number of workers to keep alive. |
| `$maxConnections` | `int` | `10` | Hard cap on the number of concurrent worker processes. |
| `$idleTimeout` | `int` | `60` | Seconds a worker can remain idle before being killed to save RAM. |
| `$maxLifetime` | `int` | `3600` | Maximum seconds an async worker may live before it is safely rotated out to prevent memory fragmentation. |
| `$statementCacheSize` | `int` | `256` | Maximum number of prepared statements to cache per connection. |
| `$enableStatementCache` | `bool` | `true` | When enabled, `query($sql, $params)` reuses server-side statement handles instead of sending `PREPARE` commands over IPC repeatedly. |
| `$maxWaiters` | `int` | `0` | Maximum number of callers that may queue waiting for a free worker before a `PoolException` is thrown immediately. `0` means unlimited. |
| `$acquireTimeout` | `float` | `10.0` | Maximum seconds to wait for a free worker. |
| `$deleteDatabaseOnShutdown` | `bool` | `false` | If true, cleanly unlinks the `.sqlite`, `-wal`, and `-shm` files when the pool is closed. Excellent for ephemeral testing environments. |
| `$onConnect` | `callable\|null` | `null` | Optional hook invoked when a new worker is spawned. |

---

## Making queries

### Queries with parameters (prepared statements)

When parameters are provided, the library automatically uses a prepared statement. Parameters are safely bound natively using their corresponding `SQLITE3_*` types.

> **Note on booleans:** SQLite has no native boolean type. The library automatically normalizes PHP `true`/`false` to `1` and `0` when binding parameters.

> **Note on Binary Data (BLOBs):** SQLite can store raw binary data (like UUIDs, images, or encrypted values) in `BLOB` columns. Because the underlying IPC worker communicates using JSON, raw binary bytes would normally corrupt the stream. The library automatically detects binary data (non-UTF-8 strings) and serializes it safely using a high-performance Base64 codec. It is seamlessly decoded back to raw binary bytes when retrieved, requiring no extra effort from the developer.

**Positional `?` placeholders:**

```php
$result = await(
    $client->query(
        'SELECT id, name FROM users WHERE created_at > ? AND status = ?',
        [$since, 'active']
    )
);
```

**Named `:name` placeholders:**

The library features a robust, SQL-string-aware parser that safely converts named parameters into positional parameters under the hood, ensuring compatibility with all SQLite syntax constraints.

```php
$result = await(
    $client->query(
        'SELECT id, name FROM users WHERE created_at > :since AND status = :status',
        ['since' => $since, 'status' => 'active']
    )
);
```

**Working with Binary Data (BLOBs):**

You can seamlessly insert and retrieve binary data without any manual encoding or decoding:

```php
// Generate 32 bytes of raw binary data (invalid UTF-8)
$token = random_bytes(32);

// Auto-serialized cleanly over the IPC barrier
await($client->query('INSERT INTO sessions (token) VALUES (?)', [$token]));

// Auto-decoded back to identical raw bytes on retrieve
$result = await($client->query('SELECT token FROM sessions WHERE id = 1'));
$retrievedToken = $result->fetchOne()['token']; 

assert($retrievedToken === $token); // True!
```

### Convenience methods

```php
// Returns affected row count
$count = await($client->execute('UPDATE users SET status = ?', ['active']));

// Returns the last inserted row ID (via SQLite3::lastInsertRowID)
$newId = await($client->executeGetId('INSERT INTO users (name) VALUES (:name)', ['name' => 'Alice']));

// Returns first row as associative array, or null
$user = await($client->fetchOne('SELECT * FROM users WHERE id = ?', [$userId]));

// Returns value of first column from first row
$name = await($client->fetchValue('SELECT name FROM users WHERE id = ?', [$userId]));
```

### Custom Built-in Functions

Native SQLite lacks a sleep function, which makes testing timeouts and async concurrency difficult. The `SqliteClient` automatically injects a custom `sleep(seconds)` User-Defined Function (UDF) into every connection. It supports integer and floating-point seconds.

```php
// Pauses the background worker for 1.5 seconds without blocking the main event loop unless `forceSync` is enabled.
await($client->query('SELECT sleep(1.5)'));
```

---

## Prepared statements

Use explicit prepared statements when you need to execute the exact same query many times in a loop, avoiding repeated parsing and IPC overhead.

```php
$stmt = await(
    $client->prepare('SELECT * FROM products WHERE category_id = :categoryId')
);

$electronics = await($stmt->execute(['categoryId' => 1]));
$clothing    = await($stmt->execute(['categoryId' => 2]));

await($stmt->close());
```

> `SqliteClient::query()` handles statement preparation and LRU caching for you transparently. Explicit `prepare()` is best for micro-optimizations within tight loops.

---

## Streaming results

Streaming allows you to process multi-gigabyte result sets without running out of RAM. The `hiblaphp/sqlite` streaming engine features **IPC Backpressure**. If your PHP loop processes rows slower than the worker reads them, the worker is automatically paused, ensuring the IPC pipe buffer never overflows.

```php
$stream = await(
    $client->stream('SELECT * FROM huge_audit_log ORDER BY id DESC', bufferSize: 200)
);

foreach ($stream as $row) {
    processLog($row);
}
```

To cancel a stream before it is fully consumed:

```php
foreach ($stream as $row) {
    if (shouldStop($row)) {
        $stream->cancel(); // Signals the worker to abort the query cleanly
        break;
    }
}
```

---

## Transactions

### High-level API: `transaction()`

The `transaction()` method automatically handles `BEGIN`, commit, rollback, and retry logic. The callback is implicitly wrapped in a Fiber via `async()`.

```php
$result = await(
    $client->transaction(function (TransactionInterface $tx) use ($from, $to) {
        await($tx->execute('UPDATE accounts SET balance = balance - 100 WHERE id = ?', [$from]));
        await($tx->execute('UPDATE accounts SET balance = balance + 100 WHERE id = ?', [$to]));

        return 'Transfer completed';
    })
);
```

If any `await()` inside the callback throws, the client automatically issues a `ROLLBACK` and re-throws the exception.

### Automatic retry

Because SQLite uses database-level locks, concurrent writes often encounter `SQLITE_BUSY` ("database is locked"). The `transaction()` method automatically retries the entire callback on **deadlocks** (`DeadlockException`) and **lock wait timeouts** (`LockWaitTimeoutException`).

Pass `withAttempts()` to enable retry:

```php
use Hibla\Sql\TransactionOptions;

await(
    $client->transaction(
        function (TransactionInterface $tx) {
            // Write logic here
        },
        TransactionOptions::default()->withAttempts(5)
    )
);
```

### Low-level API: `beginTransaction()`

```php
$tx = await($client->beginTransaction());

try {
    await($tx->execute('UPDATE ...'));
    await($tx->commit());
} catch (\Throwable $e) {
    await($tx->rollback());
    throw $e;
}
```

### Tainted state & Savepoints

If any query inside a transaction throws, the transaction is marked **tainted**. Subsequent queries or attempts to `commit()` will immediately throw a `TransactionException` without contacting the database. 

You must either `rollback()` or use `rollbackTo(string $savepoint)` to clear the tainted state.

```php
await(
    $client->transaction(function (TransactionInterface $tx) {
        await($tx->savepoint('sp1'));

        try {
            await($tx->execute('INVALID SQL'));
        } catch (\Throwable $e) {
            // Clears the tainted state, allowing the transaction to continue
            await($tx->rollbackTo('sp1'));
        }
    })
);
```

---

## Worker lifecycle & memory management

Because PHP is not designed to be a long-running daemon, background workers can suffer from memory fragmentation over time.

`hiblaphp/sqlite` implements self-healing enterprise memory management:
1. **Garbage Collection:** Workers run `gc_collect_cycles()` every 1,000 queries.
2. **Memory Limits:** If a worker exceeds `$config->memoryLimitMB` (default 128MB), it cleanly closes its database handle and exits. The `PoolManager` instantly detects the exit, silently spawns a fresh replacement worker, and routes the next query without dropping any client promises.
3. **Thundering Herd Protection:** When the pool boots up and spawns multiple workers concurrently, they coordinate file creation to prevent `-wal` and `-shm` locking collisions.

---

## Query cancellation

SQLite queries cannot be easily interrupted once handed off to the native C extension. You have two choices when calling `$promise->cancel()` on a pending query, controlled by `killWorkerOnCancel`:

1. **`killWorkerOnCancel = false` (Default):** 
   The promise is rejected with a `CancelledException` on the client side immediately. The worker is allowed to finish the query in the background, and is then recycled back into the pool. This is safe and prevents orphaned processes.
2. **`killWorkerOnCancel = true`:**
   The library uses `proc_terminate` to violently kill the entire OS process tree for that specific worker. The query is instantly aborted, and the pool spawns a fresh worker to replace it. Use this if you frequently run runaway multi-minute analytic queries that must be stopped instantly to save CPU.

---

## Sync Fallback

If you deploy to an environment where `proc_open` is disabled (e.g., restrictive shared hosting), the library detects this via `SystemHelper::isAsyncSupported()` and transparently falls back to `SyncConnection`. 

In this mode, SQLite commands execute normally in the main thread. The API remains exactly the same, meaning you do not have to change a single line of your application code to support constrained environments.

You can also force this mode manually:
```php
$config = new SqliteConfig('sqlite.db', forceSync: true);
```

---

## Reset Connection & Caching

If `resetConnection` is enabled, the pool mimics PostgreSQL's `DISCARD ALL` behavior when a worker is released. It automatically:
- Drops all `TEMP` tables and `TEMP` views.
- Clears session-scoped dangerous PRAGMAs (e.g., `read_uncommitted`, `defer_foreign_keys`).
- Triggers `PRAGMA shrink_memory`.
- Clears the client-side statement cache.

This guarantees absolute isolation between requests in a persistent web server environment.

---

## Platform Notes & Quirks

### Native SQLite URIs on Windows
The underlying SQLite C library supports native URI filenames (e.g., `file:database.sqlite?cache=shared`), which allows passing connection-level pragmas directly in the file path. 

However, **PHP's `ext-sqlite3` extension on Windows does not support this.** If you attempt to pass a native `file:` URI to the `database` configuration property on Windows, PHP's internal path resolution will fail before reaching SQLite, resulting in:
> `Exception: Unable to expand filepath`

**The Solution:**
Do not use native SQLite `file:` URIs. Instead, always use Hibla's built-in DSN configuration string (`sqlite://...`). 

Hibla's DSN parser safely extracts query parameters (like timeouts and journal modes) and applies them via discrete `PRAGMA` commands, passing a completely clean, OS-safe file path to the native extension. This guarantees identical behavior across Linux, macOS, and Windows.

```php
// ❌ WRONG: Fails on Windows PHP due to native ext-sqlite3 path resolution bugs
$client = new SqliteClient([
    'database' => 'file:/var/data/db.sqlite?mode=ro&cache=shared' 
]);

// ✅ CORRECT: Hibla parses the DSN, handles the config, and passes a safe path to SQLite
$client = new SqliteClient('sqlite:///var/data/db.sqlite?busy_timeout=5000&journal_mode=WAL');
```

---

## Result inspection

```php
$result = await($client->query('SELECT * FROM users'));

echo $result->rowCount;      // int, rows in result set
echo $result->affectedRows;  // int, rows affected by INSERT/UPDATE/DELETE
echo $result->lastInsertId;  // int, last insert row ID
echo $result->connectionId;  // int, unique ID of the worker connection
echo $result->columnCount;   // int, number of columns
echo $result->columns;       // list<string> of column names

foreach ($result as $row) {
    echo $row['name'];
}

$row = $result->fetchOne();           // first row as associative array, or null
$all = $result->fetchAll();           // all rows as array of associative arrays
$col = $result->fetchColumn('name');  // all values from a named column
```

---

## Development

```bash
git clone https://github.com/hiblaphp/sqlite.git
cd sqlite
composer install
```

### Running tests

The test suite runs against an ephemeral in-memory/temp-file database.

```bash
composer test
```

### Static analysis & Formatting

```bash
composer analyze
composer format
```

---

## License

MIT License. See [LICENSE](./LICENSE) for more information.