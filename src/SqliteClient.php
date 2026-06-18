<?php

declare(strict_types=1);

namespace Hibla\Sqlite;

use Hibla\Cache\ArrayCache;
use Hibla\Promise\Exceptions\CancelledException;
use Hibla\Promise\Interfaces\PromiseInterface;
use Hibla\Promise\Promise;
use Hibla\Sql\IsolationLevelInterface;
use Hibla\Sql\Result as ResultInterface;
use Hibla\Sql\RowStream as RowStreamInterface;
use Hibla\Sql\SqlClientInterface;
use Hibla\Sql\Transaction as TransactionInterface;
use Hibla\Sql\TransactionOptions;
use Hibla\Sqlite\Exceptions\NotInitializedException;
use Hibla\Sqlite\Interfaces\ConnectionInterface;
use Hibla\Sqlite\Interfaces\SqliteResult;
use Hibla\Sqlite\Internals\ManagedPreparedStatement;
use Hibla\Sqlite\Internals\PreparedStatement;
use Hibla\Sqlite\Internals\Transaction;
use Hibla\Sqlite\Manager\PoolManager;
use Hibla\Sqlite\ValueObjects\SqliteConfig;

use function Hibla\async;
use function Hibla\await;

/**
 * Instance-based Asynchronous SQLite Client with Connection Pooling.
 *
 * This class provides a high-level API for managing SQLite database connections.
 * Each instance is completely independent, allowing true multi-database support.
 */
final class SqliteClient implements SqlClientInterface
{
    private ?PoolManager $pool = null;

    /**
     * @var \WeakMap<ConnectionInterface, ArrayCache>|null
     */
    private ?\WeakMap $statementCaches = null;

    private int $statementCacheSize;

    private bool $enableStatementCache;

    private bool $resetConnectionEnabled = false;

    private bool $isClosing = false;

    /**
     * @var PromiseInterface<void>|null
     */
    private ?PromiseInterface $closePromise = null;

    /**
     * Creates a new independent SqliteClient instance.
     *
     * @param SqliteConfig|array<string, mixed>|string $config Database configuration.
     * @param int $minConnections Minimum number of connections to keep open.
     * @param int $maxConnections Maximum number of connections in the pool.
     * @param int $idleTimeout Seconds a connection can remain idle before being closed.
     * @param int $maxLifetime Maximum seconds a connection can live before being rotated (Async only).
     * @param int $statementCacheSize Maximum number of prepared statements to cache per connection.
     * @param bool $enableStatementCache Whether to enable prepared statement caching. Defaults to true.
     * @param int $maxWaiters Maximum number of requests that can wait for a connection.
     * @param float $acquireTimeout Maximum seconds to wait for a connection from the pool.
     * @param callable|null $onConnect Optional hook invoked on new connections.
     * @param bool $deleteDatabaseOnShutdown Whether to delete the physical database file upon pool close.
     *
     * @throws \InvalidArgumentException If configuration is invalid.
     */
    public function __construct(
        SqliteConfig|array|string $config,
        int $minConnections = 0,
        int $maxConnections = 10,
        int $idleTimeout = 60,
        int $maxLifetime = 3600,
        int $statementCacheSize = 256,
        bool $enableStatementCache = true,
        int $maxWaiters = 0,
        float $acquireTimeout = 10.0,
        ?callable $onConnect = null,
        bool $deleteDatabaseOnShutdown = false,
    ) {
        $params = match (true) {
            $config instanceof SqliteConfig => $config,
            \is_array($config) => SqliteConfig::fromArray($config),
            \is_string($config) => SqliteConfig::fromUri($config),
        };

        $this->pool = new PoolManager(
            config: $params,
            maxSize: $maxConnections,
            minSize: $minConnections,
            idleTimeout: $idleTimeout,
            maxLifetime: $maxLifetime,
            maxWaiters: $maxWaiters,
            acquireTimeout: $acquireTimeout,
            onConnect: $onConnect,
            deleteDatabaseOnShutdown: $deleteDatabaseOnShutdown,
        );

        $this->resetConnectionEnabled = $params->resetConnection;
        $this->statementCacheSize = $statementCacheSize;
        $this->enableStatementCache = $enableStatementCache;

        if ($this->enableStatementCache) {
            /** @var \WeakMap<ConnectionInterface, ArrayCache> $map */
            $map = new \WeakMap();
            $this->statementCaches = $map;
        }
    }

    /**
     * {@inheritDoc}
     *
     * @return array<string, bool|float|int>
     */
    public array $stats {
        get {
            $stats = $this->getPool()->stats;

            /** @var array<string, bool|int|float> $clientStats */
            $clientStats = [];

            foreach ($stats as $key => $val) {
                if (\is_string($key) && (\is_bool($val) || \is_int($val) || \is_float($val))) {
                    $clientStats[$key] = $val;
                }
            }

            $clientStats['statement_cache_enabled'] = $this->enableStatementCache;
            $clientStats['statement_cache_size'] = $this->statementCacheSize;

            return $clientStats;
        }
    }

    /**
     * {@inheritDoc}
     *
     * @return PromiseInterface<ManagedPreparedStatement>
     */
    public function prepare(string $sql): PromiseInterface
    {
        $pool = $this->getPool();
        $connection = null;
        $innerPromise = null;

        $promise = $this->borrowConnection()
            ->then(function (ConnectionInterface $conn) use ($sql, $pool, &$connection, &$innerPromise) {
                $connection = $conn;

                $innerPromise = $conn->prepare($sql)
                    ->then(function (PreparedStatement $stmt) use ($conn, $pool) {
                        return new ManagedPreparedStatement($stmt, $conn, $pool);
                    })
                ;

                return $innerPromise;
            })
            ->catch(function (\Throwable $e) use ($pool, &$connection) {
                if ($connection !== null) {
                    $pool->release($connection);
                }

                throw $e;
            })
        ;

        Promise::forwardCancellation($promise, $innerPromise);

        return Promise::propagateCancellation($promise);
    }

    /**
     * {@inheritDoc}
     *
     * @return PromiseInterface<SqliteResult>
     */
    public function query(string $sql, array $params = []): PromiseInterface
    {
        $pool = $this->getPool();
        $connection = null;
        $innerPromise = null;

        $promise = $this->borrowConnection()
            ->then(function (ConnectionInterface $conn) use ($sql, $params, &$connection, &$innerPromise) {
                $connection = $conn;

                if (\count($params) === 0) {
                    $innerPromise = $conn->query($sql);

                    return $innerPromise;
                }

                if ($this->enableStatementCache) {
                    $innerPromise = $this->getCachedStatement($conn, $sql)
                        ->then(function (PreparedStatement $stmt) use ($params) {
                            return $stmt->execute($params);
                        })
                    ;

                    return $innerPromise;
                }

                $innerPromise = $conn->prepare($sql)
                    ->then(function (PreparedStatement $stmt) use ($params) {
                        return $stmt->execute($params)
                            ->finally(function () use ($stmt): void {
                                $stmt->close();
                            })
                        ;
                    })
                ;

                return $innerPromise;
            })
            ->finally(function () use ($pool, &$connection): void {
                if ($connection !== null) {
                    $pool->release($connection);
                }
            })
        ;

        Promise::forwardCancellation($promise, $innerPromise);

        return Promise::propagateCancellation($promise);
    }

    /**
     * {@inheritDoc}
     */
    public function execute(string $sql, array $params = []): PromiseInterface
    {
        return Promise::propagateCancellation(
            $this->query($sql, $params)->then(fn (ResultInterface $result) => $result->affectedRows)
        );
    }

    /**
     * {@inheritDoc}
     */
    public function executeGetId(string $sql, array $params = []): PromiseInterface
    {
        return Promise::propagateCancellation(
            $this->query($sql, $params)->then(fn (ResultInterface $result) => $result->lastInsertId)
        );
    }

    /**
     * {@inheritDoc}
     */
    public function fetchOne(string $sql, array $params = []): PromiseInterface
    {
        return Promise::propagateCancellation(
            $this->query($sql, $params)->then(fn (ResultInterface $result) => $result->fetchOne())
        );
    }

    /**
     * {@inheritDoc}
     */
    public function fetchValue(string $sql, string|int|null $column = null, array $params = []): PromiseInterface
    {
        return Promise::propagateCancellation(
            $this->query($sql, $params)
                ->then(function (ResultInterface $result) use ($column) {
                    $row = $result->fetchOne();

                    if ($row === null) {
                        return null;
                    }

                    if ($column === null) {
                        $value = \reset($row);

                        return $value !== false ? $value : null;
                    }

                    if (\is_int($column)) {
                        $values = \array_values($row);

                        return $values[$column] ?? null;
                    }

                    return $row[$column] ?? null;
                })
        );
    }

    /**
     * {@inheritDoc}
     *
     * @return PromiseInterface<RowStreamInterface>
     */
    public function stream(string $sql, array $params = [], int $bufferSize = 100): PromiseInterface
    {
        $pool = $this->getPool();
        $innerPromise = null;

        $state = new class () {
            public ?ConnectionInterface $connection = null;

            public bool $released = false;
        };

        $releaseOnce = function () use ($pool, $state): void {
            if ($state->released || $state->connection === null) {
                return;
            }
            $state->released = true;
            $pool->release($state->connection);
        };

        $promise = $this->borrowConnection()
            ->then(function (ConnectionInterface $conn) use ($sql, $params, $bufferSize, $pool, $state, &$innerPromise) {
                $state->connection = $conn;

                if (\count($params) === 0) {
                    $innerPromise = $conn->streamQuery($sql, $bufferSize);
                } else {
                    $innerPromise = $this->getCachedStatement($conn, $sql)
                        ->then(function (PreparedStatement $stmt) use ($params, $bufferSize) {
                            return $stmt->executeStream($params, $bufferSize);
                        })
                    ;
                }

                $query = $innerPromise->then(
                    function (RowStreamInterface $stream) use ($conn, $pool, $state): RowStreamInterface {
                        if (\method_exists($stream, 'onClose')) {
                            $state->released = true;
                            /** @var PromiseInterface<mixed> $closePromise */
                            $closePromise = $stream->onClose();

                            $closePromise
                                ->catch(function (\Throwable $e): void {
                                    // Suppress unhandled rejection errors on stream cancellation/closure
                                })
                                ->finally(function () use ($pool, $conn): void {
                                    $pool->release($conn);
                                })
                            ;
                        } else {
                            $state->released = true;
                            $pool->release($conn);
                        }

                        return $stream;
                    },
                    function (\Throwable $e) use ($conn, $pool, $state): never {
                        if (! $state->released) {
                            $state->released = true;
                            $pool->release($conn);
                        }

                        throw $e;
                    }
                );

                return $query;
            })
            ->finally($releaseOnce)
        ;

        Promise::forwardCancellation($promise, $innerPromise);

        return Promise::propagateCancellation($promise);
    }

    /**
     * {@inheritDoc}
     */
    public function beginTransaction(?IsolationLevelInterface $isolationLevel = null): PromiseInterface
    {
        $pool = $this->getPool();

        return Promise::propagateCancellation(
            $this->borrowConnection()->then(function (ConnectionInterface $conn) use ($isolationLevel, $pool) {
                $cache = $this->getCacheForConnection($conn);

                if ($isolationLevel !== null) {
                    $isReadUncommitted = $isolationLevel->toSql() === 'READ UNCOMMITTED';
                    $flag = $isReadUncommitted ? 'ON' : 'OFF';

                    $promise = $conn->query("PRAGMA read_uncommitted = {$flag}")
                        ->then(fn () => $conn->query('BEGIN'))
                    ;
                } else {
                    $promise = $conn->query('BEGIN');
                }

                return $promise->then(fn () => new Transaction($conn, $pool, $cache));
            })
        );
    }

    /**
     * {@inheritDoc}
     *
     * @template TResult
     *
     * @param callable(TransactionInterface): TResult $callback
     *
     * @return PromiseInterface<TResult>
     */
    public function transaction(callable $callback, ?TransactionOptions $options = null): PromiseInterface
    {
        $options ??= TransactionOptions::default();

        /** @var Transaction|null $activeTx */
        $activeTx = null;

        $fiberPromise = async(function () use ($callback, $options, &$activeTx) {
            $lastError = null;

            for ($attempt = 1; $attempt <= $options->attempts; $attempt++) {
                try {
                    $activeTx = await($this->beginTransaction($options->isolationLevel));
                    $innerWorkPromise = async(fn () => $callback($activeTx));
                    $result = await($innerWorkPromise);
                    await($activeTx->commit());

                    return $result;
                } catch (\Throwable $e) {
                    $lastError = $e;

                    if ($e instanceof CancelledException && isset($innerWorkPromise) && ! $innerWorkPromise->isSettled()) {
                        $innerWorkPromise->cancel();
                    }

                    if ($activeTx instanceof Transaction && $activeTx->isActive()) {
                        try {
                            $activeTx->forceCancelCurrentQuery();
                            await($activeTx->rollback());
                        } catch (\Throwable $t) {
                            // Suppress rollback errors
                        }
                    }

                    if ($attempt === $options->attempts) {
                        break;
                    }

                    if (! $options->shouldRetry($e)) {
                        throw $e;
                    }
                } finally {
                    $activeTx = null;
                }
            }

            throw $lastError ?? new \RuntimeException('Transaction failed with no recorded error.');
        });

        $fiberPromise->onCancel(function () use (&$activeTx) {
            if ($activeTx instanceof Transaction && $activeTx->isActive()) {
                $activeTx->forceCancelCurrentQuery();
            }
        });

        return Promise::propagateCancellation($fiberPromise);
    }

    /**
     * {@inheritDoc}
     */
    public function healthCheck(): PromiseInterface
    {
        return $this->getPool()->healthCheck();
    }

    /**
     * {@inheritDoc}
     */
    public function clearStatementCache(): void
    {
        if ($this->statementCaches !== null) {
            /** @var \WeakMap<ConnectionInterface, ArrayCache> $map */
            $map = new \WeakMap();
            $this->statementCaches = $map;
        }
    }

    /**
     * {@inheritDoc}
     */
    public function closeAsync(float $timeout = 0.0): PromiseInterface
    {
        if ($this->pool === null) {
            return Promise::resolved();
        }

        if ($this->closePromise !== null) {
            return $this->closePromise;
        }

        $pool = $this->pool;
        $this->closePromise = $pool->closeAsync($timeout)->then(function (): void {
            if ($this->isClosing) {
                return;
            }
            $this->pool = null;
            $this->statementCaches = null;
            $this->closePromise = null;
        });

        return $this->closePromise;
    }

    /**
     * {@inheritDoc}
     */
    public function close(): void
    {
        if ($this->pool === null) {
            return;
        }

        $this->isClosing = true;
        $this->pool->close();
        $this->pool = null;
        $this->statementCaches = null;
        $this->closePromise = null;
        $this->isClosing = false;
    }

    /**
     * @return PromiseInterface<ConnectionInterface>
     */
    private function borrowConnection(): PromiseInterface
    {
        $pool = $this->getPool();

        return $pool->get()->then(function (ConnectionInterface $conn) {
            if ($this->resetConnectionEnabled && $this->statementCaches !== null) {
                $this->statementCaches->offsetUnset($conn);
            }

            return $conn;
        });
    }

    private function getCacheForConnection(ConnectionInterface $conn): ?ArrayCache
    {
        if (! $this->enableStatementCache || $this->statementCaches === null) {
            return null;
        }

        if (! $this->statementCaches->offsetExists($conn)) {
            $cache = new ArrayCache($this->statementCacheSize, function (string $key, mixed $stmt) use ($conn) {
                if ($stmt instanceof PreparedStatement && ! $conn->isClosed()) {
                    $stmt->close()->catch(fn () => null);
                }
            });
            $this->statementCaches->offsetSet($conn, $cache);
        }

        return $this->statementCaches->offsetGet($conn);
    }

    /**
     * @return PromiseInterface<PreparedStatement>
     */
    private function getCachedStatement(ConnectionInterface $conn, string $sql): PromiseInterface
    {
        $cache = $this->getCacheForConnection($conn);
        if ($cache === null) {
            return $conn->prepare($sql);
        }

        /** @var PromiseInterface<mixed> $cachePromise */
        $cachePromise = $cache->get($sql);

        return $cachePromise->then(function (mixed $stmt) use ($conn, $sql, $cache) {
            if ($stmt instanceof PreparedStatement) {
                return Promise::resolved($stmt);
            }

            return $conn->prepare($sql)->then(function (PreparedStatement $newStmt) use ($sql, $cache) {
                $cache->set($sql, $newStmt);

                return $newStmt;
            });
        });
    }

    private function getPool(): PoolManager
    {
        if ($this->pool === null) {
            throw new NotInitializedException('SqliteClient instance has been closed.');
        }

        return $this->pool;
    }

    public function __destruct()
    {
        $this->close();
    }
}
