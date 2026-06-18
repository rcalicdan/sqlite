<?php

declare(strict_types=1);

namespace Hibla\Sqlite\Internals;

use Hibla\Cache\ArrayCache;
use Hibla\Promise\Interfaces\PromiseInterface;
use Hibla\Promise\Promise;
use Hibla\Sql\Exceptions\TransactionException;
use Hibla\Sql\Result as ResultInterface;
use Hibla\Sql\RowStream as RowStreamInterface;
use Hibla\Sql\Transaction as TransactionInterface;
use Hibla\Sqlite\Interfaces\ConnectionInterface;
use Hibla\Sqlite\Manager\PoolManager;

/**
 * Transaction implementation with automatic pool management and state protection.
 *
 * Enforces strict transaction tainting to prevent SQLite's default
 * partial-commit behavior on constraint violations.
 *
 * @internal Created by SqliteClient::beginTransaction() - do not instantiate directly.
 */
final class Transaction implements TransactionInterface
{
    /**
     * @var list<callable(): void>
     */
    private array $onCommitCallbacks = [];

    /**
     * @var list<callable(): void>
     */
    private array $onRollbackCallbacks = [];

    private bool $active = true;

    private bool $released = false;

    private bool $failed = false;

    public function __construct(
        private readonly ConnectionInterface $connection,
        private readonly PoolManager $pool,
        private readonly ?ArrayCache $statementCache = null
    ) {
    }

    /**
     * {@inheritDoc}
     *
     * @return PromiseInterface<ResultInterface>
     */
    public function query(string $sql, array $params = []): PromiseInterface
    {
        $this->ensureActiveAndNotFailed();

        if (\count($params) === 0) {
            $promise = $this->connection->query($sql);

            return Promise::propagateCancellation($this->trackErrorState($promise));
        }

        $innerPromise = null;
        $promise = $this->getCachedStatement($sql)
            ->then(function (array $result) use ($params, &$innerPromise) {
                /** @var PreparedStatement $stmt */
                [$stmt, $isCached] = $result;

                $innerPromise = $stmt->execute($params)->finally(function () use ($stmt, $isCached): void {
                    if (! $isCached) {
                        $stmt->close();
                    }
                });

                return $innerPromise;
            })
        ;

        Promise::forwardCancellation($promise, $innerPromise);

        return Promise::propagateCancellation($this->trackErrorState($promise));
    }

    /**
     * {@inheritDoc}
     *
     * @return PromiseInterface<RowStreamInterface>
     */
    public function stream(string $sql, array $params = [], int $bufferSize = 100): PromiseInterface
    {
        $this->ensureActiveAndNotFailed();

        if (\count($params) === 0) {
            $promise = $this->connection->streamQuery($sql, $bufferSize);
            $tracked = $this->trackErrorState($promise)->then(function (RowStreamInterface $stream): RowStreamInterface {
                if ($stream instanceof SqliteRowStream || $stream instanceof SyncRowStream) {
                    $closePromise = $stream->onClose();
                    $closePromise->catch(static fn () => null);
                    $closePromise->catch(function (): void {
                        $this->failed = true;
                    });
                }

                return $stream;
            });

            return Promise::propagateCancellation($tracked);
        }

        $innerPromise = null;
        $promise = $this->getCachedStatement($sql)
            ->then(function (array $result) use ($params, $bufferSize, &$innerPromise) {
                /** @var PreparedStatement $stmt */
                [$stmt, $isCached] = $result;

                $innerPromise = $stmt->executeStream($params, $bufferSize)->then(function (RowStreamInterface $stream) use ($stmt, $isCached): RowStreamInterface {
                    if ($stream instanceof SqliteRowStream || $stream instanceof SyncRowStream) {
                        $closePromise = $stream->onClose();
                        $closePromise->catch(function (\Throwable $e): void {
                            $this->failed = true;
                        });

                        if (! $isCached) {
                            $closePromise->finally($stmt->close(...));
                        }
                    }

                    return $stream;
                });

                return $innerPromise;
            })
        ;

        Promise::forwardCancellation($promise, $innerPromise);

        return Promise::propagateCancellation($this->trackErrorState($promise));
    }

    /**
     * {@inheritDoc}
     */
    public function prepare(string $sql): PromiseInterface
    {
        $this->ensureActiveAndNotFailed();

        $innerPromise = $this->connection->prepare($sql);
        $onStreamError = function (): void {
            $this->failed = true;
        };

        $promise = $innerPromise->then(
            fn ($stmt) => new TransactionPreparedStatement($stmt, $this->connection, $onStreamError)
        );

        Promise::forwardCancellation($promise, $innerPromise);

        return $this->trackErrorState($promise);
    }

    /**
     * {@inheritDoc}
     */
    public function execute(string $sql, array $params = []): PromiseInterface
    {
        return Promise::propagateCancellation(
            $this->query($sql, $params)->then(fn (ResultInterface $r) => $r->affectedRows)
        );
    }

    /**
     * {@inheritDoc}
     */
    public function executeGetId(string $sql, array $params = []): PromiseInterface
    {
        return Promise::propagateCancellation(
            $this->query($sql, $params)->then(fn (ResultInterface $r) => $r->lastInsertId)
        );
    }

    /**
     * {@inheritDoc}
     */
    public function fetchOne(string $sql, array $params = []): PromiseInterface
    {
        return Promise::propagateCancellation(
            $this->query($sql, $params)->then(fn (ResultInterface $r) => $r->fetchOne())
        );
    }

    /**
     * {@inheritDoc}
     */
    public function fetchValue(string $sql, string|int|null $column = null, array $params = []): PromiseInterface
    {
        return Promise::propagateCancellation(
            $this->query($sql, $params)->then(function (ResultInterface $r) use ($column) {
                $row = $r->fetchOne();
                if ($row === null) {
                    return null;
                }
                if ($column === null) {
                    $val = \reset($row);

                    return $val !== false ? $val : null;
                }
                if (\is_int($column)) {
                    return \array_values($row)[$column] ?? null;
                }

                return $row[$column] ?? null;
            })
        );
    }

    /**
     * {@inheritDoc}
     */
    public function onCommit(callable $callback): void
    {
        $this->ensureActive();
        $this->onCommitCallbacks[] = $callback;
    }

    /**
     * {@inheritDoc}
     */
    public function onRollback(callable $callback): void
    {
        $this->ensureActive();
        $this->onRollbackCallbacks[] = $callback;
    }

    /**
     * {@inheritDoc}
     */
    public function commit(): PromiseInterface
    {
        $this->ensureActive();

        if ($this->failed) {
            return Promise::rejected(new TransactionException('Transaction aborted due to a previous error. Call rollback() to abort.'));
        }

        $promise = $this->connection->query('COMMIT')->then(
            function (): void {
                $this->active = false;
                foreach ($this->onCommitCallbacks as $cb) {
                    $cb();
                }
                $this->onRollbackCallbacks = [];
            },
            function (\Throwable $e): never {
                $this->active = false;
                $this->failed = true;

                throw new TransactionException('Failed to commit transaction: ' . $e->getMessage(), (int)$e->getCode(), $e);
            }
        )->finally($this->releaseConnection(...));

        return Promise::uninterruptible($promise);
    }

    /**
     * {@inheritDoc}
     */
    public function rollback(): PromiseInterface
    {
        if (! $this->active) {
            return Promise::resolved();
        }

        if ($this->connection->isClosed()) {
            $this->active = false;
            $this->failed = false;
            $this->releaseConnection();

            return Promise::resolved();
        }

        $this->active = false;
        $this->failed = false;

        $promise = $this->connection->query('ROLLBACK')->then(
            function (): void {
                foreach ($this->onRollbackCallbacks as $cb) {
                    $cb();
                }
                $this->onCommitCallbacks = [];
            },
            function (\Throwable $e): void {
                throw new TransactionException('Failed to rollback transaction: ' . $e->getMessage(), (int)$e->getCode(), $e);
            }
        );

        return Promise::uninterruptible($promise->finally($this->releaseConnection(...)));
    }

    /**
     * {@inheritDoc}
     */
    public function savepoint(string $identifier): PromiseInterface
    {
        $this->ensureActiveAndNotFailed();

        return Promise::propagateCancellation(
            $this->trackErrorState($this->connection->query("SAVEPOINT `{$identifier}`"))
                ->then(function (): void {
                })
        );
    }

    /**
     * {@inheritDoc}
     */
    public function rollbackTo(string $identifier): PromiseInterface
    {
        $this->ensureActive();

        $this->failed = false;

        $promise = $this->connection->query("ROLLBACK TO SAVEPOINT `{$identifier}`")
            ->then(function (): void {
            })
            ->catch(function (\Throwable $e) {
                $this->failed = true;

                throw $e;
            })
        ;

        return Promise::propagateCancellation($promise);
    }

    /**
     * {@inheritDoc}
     */
    public function releaseSavepoint(string $identifier): PromiseInterface
    {
        $this->ensureActiveAndNotFailed();

        return Promise::propagateCancellation(
            $this->trackErrorState($this->connection->query("RELEASE SAVEPOINT `{$identifier}`"))
                ->then(function (): void {
                })
        );
    }

    /**
     * {@inheritDoc}
     */
    public function isActive(): bool
    {
        return $this->active && ! $this->connection->isClosed();
    }

    /**
     * {@inheritDoc}
     */
    public function isClosed(): bool
    {
        return $this->connection->isClosed();
    }

    /**
     * Used by the SqliteClient to cancel queries in-flight if the user cancels the transaction generator.
     */
    public function forceCancelCurrentQuery(): void
    {
        if ($this->connection instanceof AsyncConnection && ! $this->connection->isClosed()) {
            $this->connection->close(true);
        }
    }

    /**
     * @template T
     *
     * @param PromiseInterface<T> $promise
     *
     * @return PromiseInterface<T>
     */
    private function trackErrorState(PromiseInterface $promise): PromiseInterface
    {
        $promise->onCancel(function (): void {
            $this->failed = true;
        });

        return $promise->catch(function (\Throwable $e) {
            $this->failed = true;

            throw $e;
        });
    }

    /**
     * @return PromiseInterface<array{0: PreparedStatement, 1: bool}>
     */
    private function getCachedStatement(string $sql): PromiseInterface
    {
        if ($this->statementCache === null) {
            return $this->connection->prepare($sql)->then(fn ($stmt) => [$stmt, false]);
        }

        return $this->statementCache->get($sql)->then(function (mixed $stmt) use ($sql) {
            if ($stmt instanceof PreparedStatement) {
                return [$stmt, true];
            }

            return $this->connection->prepare($sql)->then(function (PreparedStatement $newStmt) use ($sql) {
                $this->statementCache->set($sql, $newStmt);

                return [$newStmt, true];
            });
        });
    }

    private function releaseConnection(): void
    {
        if ($this->released) {
            return;
        }
        $this->released = true;
        $this->onCommitCallbacks = [];
        $this->onRollbackCallbacks = [];
        $this->pool->release($this->connection);
    }

    private function ensureActive(): void
    {
        if ($this->connection->isClosed()) {
            throw new TransactionException('Connection is closed');
        }
        if (! $this->active) {
            throw new TransactionException('Transaction is no longer active');
        }
    }

    private function ensureActiveAndNotFailed(): void
    {
        $this->ensureActive();
        if ($this->failed) {
            throw new TransactionException('Transaction aborted due to a previous query error. Call rollback() to abort.');
        }
    }

    public function __destruct()
    {
        if ($this->active && ! $this->connection->isClosed() && ! $this->released) {
            $this->active = false;
            $this->connection->query('ROLLBACK')->finally($this->releaseConnection(...));
        } elseif (! $this->released) {
            $this->releaseConnection();
        }
    }
}
