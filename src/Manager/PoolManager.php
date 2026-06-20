<?php

declare(strict_types=1);

namespace Hibla\Sqlite\Manager;

use Hibla\EventLoop\Loop;
use Hibla\Promise\Exceptions\TimeoutException;
use Hibla\Promise\Interfaces\PromiseInterface;
use Hibla\Promise\Promise;
use Hibla\Sql\Exceptions\PoolException;
use Hibla\Sqlite\Interfaces\ConnectionInterface;
use Hibla\Sqlite\Interfaces\ConnectionSetupInterface;
use Hibla\Sqlite\Internals\AsyncConnection;
use Hibla\Sqlite\Internals\ConnectionFactory;
use Hibla\Sqlite\Internals\ConnectionSetup;
use Hibla\Sqlite\ValueObjects\SqliteConfig;
use SplQueue;
use Throwable;

use function Hibla\async;
use function Hibla\await;
use function Hibla\sleep;

/**
 * @internal
 *
 * Manages a pool of SQLite connections (both async daemons and sync fallbacks).
 */
final class PoolManager
{
    /**
     * @var SplQueue<ConnectionInterface> Idle connections available for reuse.
     */
    private SplQueue $pool;

    /**
     * @var SplQueue<Promise<ConnectionInterface>> Callers waiting for a connection.
     */
    private SplQueue $waiters;

    private int $activeConnections = 0;

    private bool $configValidated = true;

    /**
     * @var array<int, int> Last-used timestamp (nanoseconds) keyed by spl_object_id.
     */
    private array $connectionLastUsed = [];

    /**
     * @var array<int, int> Creation timestamp (nanoseconds) keyed by spl_object_id.
     */
    private array $connectionCreatedAt = [];

    /**
     * @var array<int, ConnectionInterface> Connections currently checked out.
     */
    private array $activeConnectionsMap = [];

    /**
     * @var array<int, ConnectionInterface> Connections currently resetting.
     */
    private array $drainingConnections = [];

    private bool $isClosing = false;

    private bool $isGracefulShutdown = false;

    /**
     * @var Promise<void>|null
     */
    private ?Promise $shutdownPromise = null;

    private int $idleTimeoutNanos;

    private int $maxLifetimeNanos;

    private PoolException $exhaustedException;

    /**
     * @var (callable(ConnectionSetupInterface): (PromiseInterface<mixed>|void))|null
     */
    private readonly mixed $onConnect;

   /**
     * @param (callable(ConnectionSetupInterface): (PromiseInterface<mixed>|void))|null $onConnect
     * @param bool $deleteDatabaseOnShutdown Whether to delete the physical database file upon pool close.
     */
    public function __construct(
        private readonly SqliteConfig $config,
        private readonly int $maxSize = 10,
        private readonly int $minSize = 0,
        int $idleTimeout = 300,
        int $maxLifetime = 3600,
        private readonly int $maxWaiters = 0,
        private readonly float $acquireTimeout = 0.0,
        ?callable $onConnect = null,
        private readonly bool $deleteDatabaseOnShutdown = false,
    ) {
        if ($this->maxSize <= 0) {
            throw new \InvalidArgumentException('Pool max size must be greater than 0');
        }

        if ($this->minSize < 0 || $this->minSize > $this->maxSize) {
            throw new \InvalidArgumentException('Invalid pool min size configuration');
        }

        $this->idleTimeoutNanos = max(1, $idleTimeout) * 1_000_000_000;
        $this->maxLifetimeNanos = max(1, $maxLifetime) * 1_000_000_000;

        $this->exhaustedException = new PoolException("Connection pool exhausted. Max waiters limit ({$this->maxWaiters}) reached.");

        $this->pool = new SplQueue();
        $this->waiters = new SplQueue();
        $this->onConnect = $onConnect;

        // Clean up stale database or lock files left behind by prior abnormal crashes
        $this->cleanupDatabaseFiles();
        $this->ensureMinConnections();
    }

    private int $pendingWaitersCount {
        get {
            $count = 0;
            foreach ($this->waiters as $waiter) {
                if ($waiter->isPending()) {
                    $count++;
                }
            }

            return $count;
        }
    }

    /**
     * @var array<string, bool|float|int>
     */
    public array $stats {
        get {
            return [
                'active_connections' => \count($this->activeConnectionsMap),
                'total_connections' => $this->activeConnections,
                'pooled_connections' => $this->pool->count(),
                'min_size' => $this->minSize,
                'waiting_requests' => $this->pendingWaitersCount,
                'max_size' => $this->maxSize,
                'max_waiters' => $this->maxWaiters,
                'acquire_timeout' => $this->acquireTimeout,
                'config_validated' => $this->configValidated,
                'tracked_connections' => \count($this->connectionCreatedAt),
                'is_graceful_shutdown' => $this->isGracefulShutdown,
                'delete_database_on_shutdown' => $this->deleteDatabaseOnShutdown,
            ];
        }
    }

    /**
     * @return PromiseInterface<ConnectionInterface>
     */
    public function get(): PromiseInterface
    {
        if ($this->isClosing || $this->isGracefulShutdown) {
            return Promise::rejected(new PoolException('Pool is shutting down'));
        }

        while (! $this->pool->isEmpty()) {
            /** @var ConnectionInterface $connection */
            $connection = $this->pool->dequeue();

            $connId = spl_object_id($connection);
            $now = (int) hrtime(true);
            $lastUsed = $this->connectionLastUsed[$connId] ?? 0;
            $createdAt = $this->connectionCreatedAt[$connId] ?? 0;

            // Rotation (maxLifetime) is disabled for SyncConnection instances (no-op)
            $isAsync = $connection instanceof AsyncConnection;
            $isExpired = ($now - $lastUsed) > $this->idleTimeoutNanos
                || ($isAsync && ($now - $createdAt) > $this->maxLifetimeNanos)
                || $connection->isClosed();

            if ($isExpired) {
                $this->removeConnection($connection);

                continue;
            }

            unset($this->connectionLastUsed[$connId]);
            $this->activeConnectionsMap[$connId] = $connection;
            $connection->resume();

            return Promise::resolved($connection);
        }

        if ($this->activeConnections < $this->maxSize) {
            return $this->createNewConnection();
        }

        if ($this->maxWaiters > 0 && $this->pendingWaitersCount >= $this->maxWaiters) {
            return Promise::rejected($this->exhaustedException);
        }

        /** @var Promise<ConnectionInterface> $waiterPromise */
        $waiterPromise = new Promise();

        if ($this->acquireTimeout > 0.0) {
            $timeout = $this->acquireTimeout;
            $timerId = Loop::addTimer($timeout, static function () use ($waiterPromise, $timeout): void {
                if ($waiterPromise->isPending()) {
                    $waiterPromise->reject(new TimeoutException($timeout));
                }
            });

            $waiterPromise->finally(static function () use ($timerId): void {
                Loop::cancelTimer($timerId);
            })->catch(static function (): void {
            });
        }

        $this->waiters->enqueue($waiterPromise);

        return $waiterPromise;
    }

    public function release(ConnectionInterface $connection): void
    {
        $connId = spl_object_id($connection);

        if (! isset($this->activeConnectionsMap[$connId])) {
            return;
        }

        if ($connection->isClosed()) {
            unset($this->activeConnectionsMap[$connId]);
            $this->removeConnection($connection);
            $this->satisfyNextWaiter();

            return;
        }

        if ($this->config->resetConnection) {
            $this->resetAndRelease($connection);

            return;
        }

        $this->releaseClean($connection);
    }

    private function releaseClean(ConnectionInterface $connection): void
    {
        $connId = spl_object_id($connection);
        $waiter = $this->dequeueActiveWaiter();

        if ($waiter !== null) {
            $connection->resume();
            $waiter->resolve($connection);

            return;
        }

        if ($this->isGracefulShutdown) {
            unset($this->activeConnectionsMap[$connId]);
            $this->removeConnection($connection);

            return;
        }

        $connection->pause();

        $now = (int) hrtime(true);
        $createdAt = $this->connectionCreatedAt[$connId] ?? 0;

        $isAsync = $connection instanceof AsyncConnection;
        if ($isAsync && ($now - $createdAt) > $this->maxLifetimeNanos) {
            unset($this->activeConnectionsMap[$connId]);
            $this->removeConnection($connection);

            return;
        }

        $this->connectionLastUsed[$connId] = $now;
        unset($this->activeConnectionsMap[$connId]);
        $this->pool->enqueue($connection);
    }

    /**
     * @return PromiseInterface<void>
     */
    public function closeAsync(float $timeout = 0.0): PromiseInterface
    {
        if ($this->isClosing || $this->isGracefulShutdown) {
            /** @var PromiseInterface<void> */
            return $this->shutdownPromise ?? Promise::resolved();
        }

        $this->isGracefulShutdown = true;

        $shuttingDownException = new PoolException('Pool is shutting down');
        while (! $this->waiters->isEmpty()) {
            $waiter = $this->waiters->dequeue();
            if ($waiter->isPending()) {
                $waiter->reject($shuttingDownException);
            }
        }

        while (! $this->pool->isEmpty()) {
            $connection = $this->pool->dequeue();
            if (! $connection->isClosed()) {
                $connection->close(true);
            }
            $connId = spl_object_id($connection);
            unset($this->connectionLastUsed[$connId], $this->connectionCreatedAt[$connId]);
            $this->activeConnections--;
        }

        /** @var Promise<void> $shutdownPromise */
        $shutdownPromise = new Promise();
        $this->shutdownPromise = $shutdownPromise;

        $this->checkShutdownComplete();

        if ($timeout > 0.0 && $this->shutdownPromise !== null) {
            $pendingShutdown = $this->shutdownPromise;
            $timerId = Loop::addTimer($timeout, function (): void {
                if ($this->shutdownPromise !== null && $this->shutdownPromise->isPending()) {
                    $this->close();
                }
            });

            $pendingShutdown->finally(static function () use ($timerId): void {
                Loop::cancelTimer($timerId);
            })->catch(static function (): void {
            });
        }

        /** @var PromiseInterface<void> */
        return $this->shutdownPromise ?? Promise::resolved();
    }

    public function close(): void
    {
        if ($this->shutdownPromise !== null && $this->shutdownPromise->isPending()) {
            $this->shutdownPromise->resolve(null);
            $this->shutdownPromise = null;
        }

        $this->isGracefulShutdown = false;
        $this->isClosing = true;

        while (! $this->pool->isEmpty()) {
            $connection = $this->pool->dequeue();
            if (! $connection->isClosed()) {
                $connection->close(true);
            }
        }

        foreach ($this->activeConnectionsMap as $connection) {
            if (! $connection->isClosed()) {
                $connection->close(true);
            }
        }

        foreach ($this->drainingConnections as $connection) {
            if (! $connection->isClosed()) {
                $connection->close(true);
            }
        }

        $this->drainingConnections = [];

        while (! $this->waiters->isEmpty()) {
            $promise = $this->waiters->dequeue();
            if (! $promise->isCancelled()) {
                $promise->reject(new PoolException('Pool is being closed'));
            }
        }

        $this->activeConnectionsMap = [];
        $this->pool = new SplQueue();
        $this->waiters = new SplQueue();
        $this->activeConnections = 0;
        $this->connectionLastUsed = [];
        $this->connectionCreatedAt = [];

        $this->cleanupDatabaseFiles();
    }

    /**
     * @return PromiseInterface<array<string, int>>
     */
    public function healthCheck(): PromiseInterface
    {
        /** @var Promise<array<string, int>> $promise */
        $promise = new Promise();

        $stats = [
            'total_checked' => 0,
            'healthy' => 0,
            'unhealthy' => 0,
        ];

        /** @var SplQueue<ConnectionInterface> $tempQueue */
        $tempQueue = new SplQueue();
        $checkPromises = [];

        while (! $this->pool->isEmpty()) {
            $connection = $this->pool->dequeue();
            $stats['total_checked']++;
            $connection->resume();

            $checkPromises[] = $connection->ping()->then(
                function () use ($connection, $tempQueue, &$stats): void {
                    $stats['healthy']++;
                    $connection->pause();
                    $connId = spl_object_id($connection);
                    $this->connectionLastUsed[$connId] = (int) hrtime(true);
                    $tempQueue->enqueue($connection);
                },
                function () use ($connection, &$stats): void {
                    $stats['unhealthy']++;
                    $this->removeConnection($connection);
                }
            );
        }

        $drainTempQueue = function () use ($tempQueue): void {
            while (! $tempQueue->isEmpty()) {
                $conn = $tempQueue->dequeue();
                if ($this->isClosing || $this->isGracefulShutdown) {
                    $this->removeConnection($conn);
                } else {
                    $this->pool->enqueue($conn);
                }
            }
        };

        Promise::all($checkPromises)->then(
            function () use ($promise, $drainTempQueue, &$stats): void {
                $drainTempQueue();
                $promise->resolve($stats);
            },
            function (Throwable $e) use ($promise, $drainTempQueue): void {
                $drainTempQueue();
                $promise->reject($e);
            }
        );

        return $promise;
    }

    private function checkShutdownComplete(): void
    {
        if (! $this->isGracefulShutdown) {
            return;
        }

        if ($this->activeConnections === 0 && ! $this->waiters->isEmpty()) {
            $shuttingDownException = new PoolException('Pool is shutting down');
            while (! $this->waiters->isEmpty()) {
                $waiter = $this->waiters->dequeue();
                if ($waiter->isPending()) {
                    $waiter->reject($shuttingDownException);
                }
            }
        }

        if ($this->activeConnections > 0 || ! $this->waiters->isEmpty()) {
            return;
        }

        $this->connectionLastUsed = [];
        $this->connectionCreatedAt = [];

        $this->cleanupDatabaseFiles();

        if ($this->shutdownPromise !== null && $this->shutdownPromise->isPending()) {
            $this->shutdownPromise->resolve(null);
        }

        $this->shutdownPromise = null;
    }

    private function ensureMinConnections(): void
    {
        if ($this->isClosing || $this->isGracefulShutdown) {
            return;
        }

        while ($this->activeConnections < $this->minSize) {
            $this->createNewConnection()->then(
                function (ConnectionInterface $connection): void {
                    $waiter = $this->dequeueActiveWaiter();
                    if ($waiter !== null) {
                        $connection->resume();
                        $waiter->resolve($connection);
                    } else {
                        if ($this->isClosing || $this->isGracefulShutdown) {
                            $this->removeConnection($connection);

                            return;
                        }
                        $connection->pause();
                        $connId = spl_object_id($connection);
                        $this->connectionLastUsed[$connId] = (int) hrtime(true);
                        unset($this->activeConnectionsMap[$connId]);
                        $this->pool->enqueue($connection);
                    }
                },
                function (Throwable $e): void {
                }
            );
        }
    }

    /**
     * @return PromiseInterface<ConnectionInterface>
     */
    private function runOnConnectHook(ConnectionInterface $connection): PromiseInterface
    {
        if ($this->onConnect === null) {
            return Promise::resolved($connection);
        }

        $setup = new ConnectionSetup($connection);

        /** @var Promise<ConnectionInterface> $promise */
        $promise = new Promise();

        async(function () use ($setup, $connection, $promise) {
            try {
                $result = ($this->onConnect)($setup);

                if ($result instanceof PromiseInterface) {
                    await($result);
                }

                $promise->resolve($connection);
            } catch (Throwable $e) {
                $promise->reject($e);
            }
        });

        return $promise;
    }

    private function resetAndRelease(ConnectionInterface $connection): void
    {
        $connId = spl_object_id($connection);
        unset($this->activeConnectionsMap[$connId]);

        if ($this->isClosing) {
            $this->removeConnection($connection);

            return;
        }

        $this->drainingConnections[$connId] = $connection;

        $connection->reset()->then(
            function () use ($connection, $connId): void {
                unset($this->drainingConnections[$connId]);

                if ($this->isClosing) {
                    $this->removeConnection($connection);

                    return;
                }

                $this->activeConnectionsMap[$connId] = $connection;

                $this->runOnConnectHook($connection)->then(
                    function () use ($connection): void {
                        $this->releaseClean($connection);
                    },
                    function (Throwable $e) use ($connection): void {
                        $this->removeConnection($connection);
                        $this->satisfyNextWaiter();
                    }
                );
            },
            function () use ($connection, $connId): void {
                unset($this->drainingConnections[$connId]);
                $this->removeConnection($connection);
                $this->satisfyNextWaiter();
            }
        );
    }

    /**
     * @return Promise<ConnectionInterface>
     */
    private function createNewConnection(): Promise
    {
        $this->activeConnections++;

        /** @var Promise<ConnectionInterface> $promise */
        $promise = new Promise();

        ConnectionFactory::create($this->config)->then(
            function (ConnectionInterface $connection) use ($promise): void {
                if ($this->isClosing) {
                    $connection->close();
                    $this->activeConnections--;
                    $promise->reject(new PoolException('Pool is being closed'));
                    $this->checkShutdownComplete();

                    return;
                }

                $connId = spl_object_id($connection);
                $this->connectionCreatedAt[$connId] = (int) hrtime(true);
                $this->activeConnectionsMap[$connId] = $connection;

                $this->runOnConnectHook($connection)->then(
                    function () use ($promise, $connection): void {
                        if ($this->isClosing) {
                            $this->removeConnection($connection);
                            $promise->reject(new PoolException('Pool is being closed'));

                            return;
                        }

                        if ($promise->isCancelled()) {
                            $this->releaseClean($connection);

                            return;
                        }

                        $promise->resolve($connection);
                    },
                    function (Throwable $e) use ($promise, $connection): void {
                        $this->removeConnection($connection, false);
                        $promise->reject($e);
                    }
                );
            },
            function (Throwable $e) use ($promise): void {
                $this->activeConnections--;
                $promise->reject($e);
                $this->checkShutdownComplete();
            }
        );

        return $promise;
    }

    private function createConnectionForWaiter(): void
    {
        $waiter = $this->dequeueActiveWaiter();
        if ($waiter === null) {
            return;
        }

        $this->activeConnections++;

        ConnectionFactory::create($this->config)->then(
            function (ConnectionInterface $connection) use ($waiter): void {
                if ($this->isClosing) {
                    $connection->close();
                    $this->activeConnections--;
                    $waiter->reject(new PoolException('Pool is being closed'));
                    $this->checkShutdownComplete();

                    return;
                }

                $connId = spl_object_id($connection);
                $this->connectionCreatedAt[$connId] = (int) hrtime(true);
                $this->activeConnectionsMap[$connId] = $connection;

                $this->runOnConnectHook($connection)->then(
                    function () use ($connection, $waiter): void {
                        if ($this->isClosing) {
                            $this->removeConnection($connection);
                            $waiter->reject(new PoolException('Pool is being closed'));

                            return;
                        }

                        if ($waiter->isCancelled()) {
                            $this->releaseClean($connection);

                            return;
                        }

                        $waiter->resolve($connection);
                    },
                    function (Throwable $e) use ($connection, $waiter): void {
                        $this->removeConnection($connection, false);
                        $waiter->reject($e);
                    }
                );
            },
            function (Throwable $e) use ($waiter): void {
                $this->activeConnections--;
                $waiter->reject($e);
                $this->checkShutdownComplete();
            }
        );
    }

    /**
     * @return Promise<ConnectionInterface>|null
     */
    private function dequeueActiveWaiter(): ?Promise
    {
        while (! $this->waiters->isEmpty()) {
            $waiter = $this->waiters->dequeue();
            if ($waiter->isPending()) {
                return $waiter;
            }
        }

        return null;
    }

    private function satisfyNextWaiter(): void
    {
        if ($this->isGracefulShutdown || $this->isClosing) {
            return;
        }

        if (! $this->waiters->isEmpty() && $this->activeConnections < $this->maxSize) {
            $this->createConnectionForWaiter();
        }
    }

    private function removeConnection(ConnectionInterface $connection, bool $replenish = true): void
    {
        if (! $connection->isClosed()) {
            $connection->close(true);
        }

        $connId = spl_object_id($connection);
        unset(
            $this->connectionLastUsed[$connId],
            $this->connectionCreatedAt[$connId],
            $this->activeConnectionsMap[$connId]
        );

        $this->activeConnections--;

        if ($replenish && ! $this->isClosing && ! $this->isGracefulShutdown) {
            $this->ensureMinConnections();
        }

        $this->checkShutdownComplete();
    }

    /**
     * Deletes the physical database and companion files if deleteDatabaseOnShutdown is enabled.
     */
    private function cleanupDatabaseFiles(): void
    {
        if (! $this->deleteDatabaseOnShutdown) {
            return;
        }

        $dbFile = $this->config->database;

        if ($dbFile === ':memory:' || $dbFile === '') {
            return;
        }

        $files = [$dbFile, $dbFile . '-wal', $dbFile . '-shm'];

        foreach ($files as $file) {
            if (! \file_exists($file)) {
                continue;
            }

            for ($attempt = 0; $attempt < 50; $attempt++) {
                if (@\unlink($file)) {
                    break;
                }
                sleep(0.01);
            }
        }
    }

    public function __destruct()
    {
        $this->close();
    }
}
