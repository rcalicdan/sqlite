<?php

declare(strict_types=1);

namespace Hibla\Sqlite\Internals;

use Hibla\Promise\Interfaces\PromiseInterface;
use Hibla\Promise\Promise;
use Hibla\Sql\Exceptions\PreparedException;
use Hibla\Sql\PreparedStatement as PreparedStatementInterface;
use Hibla\Sqlite\Interfaces\ConnectionInterface;

/**
 * Client-Side Prepared Statement that satisfies the SQL contracts.
 *
 * @internal
 */
final class PreparedStatement implements PreparedStatementInterface
{
    private bool $isClosed = false;

    public readonly string $parsedSql;

    /**
     * @var array<int, string>
     */
    private array $paramMap = [];

    public function __construct(
        private readonly ConnectionInterface $connection,
        private readonly string $sql
    ) {
        [$this->parsedSql, $this->paramMap] = NameParamParser::parse($this->sql);
    }

    /**
     * {@inheritDoc}
     *
     * @param array<int|string, mixed> $params
     *
     * @return PromiseInterface<Result>
     */
    public function execute(array $params = []): PromiseInterface
    {
        if ($this->isClosed) {
            throw new PreparedException('Cannot execute a closed prepared statement.');
        }

        try {
            $normalizedParams = $this->mapAndNormalizeParams($params);
        } catch (\Throwable $e) {
            return Promise::rejected($e);
        }

        return $this->connection->executeStatement($this, $normalizedParams);
    }

    /**
     * {@inheritDoc}
     *
     * @param array<int|string, mixed> $params
     *
     * @return PromiseInterface<\Hibla\Sql\RowStream>
     */
    public function executeStream(array $params = [], int $bufferSize = 100): PromiseInterface
    {
        if ($this->isClosed) {
            throw new PreparedException('Cannot execute a closed prepared statement.');
        }

        try {
            $normalizedParams = $this->mapAndNormalizeParams($params);
        } catch (\Throwable $e) {
            return Promise::rejected($e);
        }

        return $this->connection->executeStream($this, $normalizedParams, $bufferSize);
    }

    /**
     * {@inheritDoc}
     *
     * @return PromiseInterface<void>
     */
    public function close(): PromiseInterface
    {
        $this->isClosed = true;

        return Promise::resolved();
    }

    /**
     * @param array<int|string, mixed> $params
     *
     * @return array<int|string, mixed>
     */
    private function mapAndNormalizeParams(array $params): array
    {
        if ($this->paramMap !== []) {
            $mapped = [];
            foreach ($this->paramMap as $index => $name) {
                $key = isset($params[$name]) ? $name : (isset($params[':' . $name]) ? ':' . $name : null);

                if ($key === null) {
                    throw new PreparedException("Missing value for named parameter: :{$name}");
                }

                $mapped[$index] = $params[$key];
            }

            return $mapped;
        }

        return array_values($params);
    }
}
