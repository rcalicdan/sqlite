<?php

declare(strict_types=1);

namespace Hibla\Sqlite\Internals;

use Hibla\Promise\Interfaces\PromiseInterface;
use Hibla\Promise\Promise;
use Hibla\Sql\PreparedStatement as PreparedStatementInterface;
use Hibla\Sql\Result as ResultInterface;
use Hibla\Sql\RowStream as RowStreamInterface;
use Hibla\Sqlite\Interfaces\ConnectionInterface;

/**
 * A wrapper around a Prepared Statement used strictly inside Transactions.
 *
 * This class automatically closes the server-side statement when it goes out
 * of scope, preventing memory leaks. Unlike ManagedPreparedStatement, this
 * DOES NOT release the underlying connection back to the pool, as the
 * Transaction still claims exclusive ownership over it.
 *
 * @internal
 */
final class TransactionPreparedStatement implements PreparedStatementInterface
{
    private bool $isClosed = false;

    public function __construct(
        private readonly PreparedStatementInterface $statement,
        private readonly ConnectionInterface $connection,
        private readonly ?\Closure $onStreamError = null,
    ) {
    }

    /**
     * {@inheritDoc}
     *
     * @return PromiseInterface<ResultInterface>
     */
    public function execute(array $params = []): PromiseInterface
    {
        return Promise::propagateCancellation($this->statement->execute($params));
    }

    /**
     * {@inheritDoc}
     *
     * @return PromiseInterface<RowStreamInterface>
     */
    public function executeStream(array $params = [], int $bufferSize = 100): PromiseInterface
    {
        $promise = $this->statement->executeStream($params, $bufferSize);

        if ($this->onStreamError !== null) {
            $onStreamError = $this->onStreamError;

            $promise = $promise->then(
                function (RowStreamInterface $stream) use ($onStreamError): RowStreamInterface {
                    if ($stream instanceof SqliteRowStream || $stream instanceof SyncRowStream) {
                        $closePromise = $stream->onClose();
                        $closePromise->catch(static function () use ($onStreamError): void {
                            $onStreamError();
                        });
                    }

                    return $stream;
                }
            );
        }

        return Promise::propagateCancellation($promise);
    }

    /**
     * {@inheritDoc}
     */
    public function close(): PromiseInterface
    {
        if ($this->isClosed) {
            return Promise::resolved();
        }

        $this->isClosed = true;

        return $this->statement->close();
    }

    public function __destruct()
    {
        if (! $this->isClosed && ! $this->connection->isClosed()) {
            $this->close();
        }
    }
}
