<?php

declare(strict_types=1);

namespace Hibla\Sqlite\Internals;

use Hibla\Promise\Interfaces\PromiseInterface;
use Hibla\Promise\Promise;
use Hibla\Sql\PreparedStatement as PreparedStatementInterface;
use Hibla\Sql\Result as ResultInterface;
use Hibla\Sql\RowStream as RowStreamInterface;
use Hibla\Sqlite\Interfaces\ConnectionInterface;
use Hibla\Sqlite\Manager\PoolManager;

/**
 * A wrapper around PreparedStatement that manages connection lifecycle.
 *
 * @internal
 */
final class ManagedPreparedStatement implements PreparedStatementInterface
{
    private bool $isReleased = false;

    public function __construct(
        private readonly PreparedStatementInterface $statement,
        private readonly ConnectionInterface $connection,
        private readonly PoolManager $pool
    ) {
    }

    /**
     * {@inheritDoc}
     * 
     * @return PromiseInterface<ResultInterface>
     */
    public function execute(array $params = []): PromiseInterface
    {
        $promise = $this->statement->execute($params);

        return Promise::propagateCancellation($promise);
    }

    /**
     * {@inheritDoc}
     * 
     * @return PromiseInterface<RowStreamInterface>
     */
    public function executeStream(array $params = []): PromiseInterface
    {
        $promise = $this->statement->executeStream($params);

        return Promise::propagateCancellation($promise);
    }

    /**
     * {@inheritDoc}
     */
    public function close(): PromiseInterface
    {
        return $this->statement->close()->finally($this->releaseConnection(...));
    }

    private function releaseConnection(): void
    {
        if ($this->isReleased) {
            return;
        }

        $this->isReleased = true;
        $this->pool->release($this->connection);
    }

    public function __destruct()
    {
        if (! $this->isReleased && ! $this->connection->isClosed()) {
            $this->connection->close(true);
        }

        $this->releaseConnection();
    }
}