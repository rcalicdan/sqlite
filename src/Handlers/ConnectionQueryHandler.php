<?php

declare(strict_types=1);

namespace Hibla\Sqlite\Handlers;

use Hibla\Sqlite\Internals\AsyncConnection;
use Hibla\Sqlite\Internals\Result;
use Hibla\Sqlite\Utilities\ExceptionHandler;
use Hibla\Sqlite\ValueObjects\CommandRequest;

/**
 * Handles standard, buffered queries and executions.
 *
 * @internal
 */
final class ConnectionQueryHandler
{
    public function __construct(private readonly AsyncConnection $connection)
    {
    }

    public function start(CommandRequest $request): void
    {
        $payload = \json_encode([
            'id' => $request->id,
            'cmd' => 'query',
            'sql' => $request->sql,
            'params' => $request->params,
        ], JSON_UNESCAPED_SLASHES);

        $this->connection->writeIpc($payload . "\n");
    }

    /**
     * Processes incoming JSON frames.
     *
     * @param array<string, mixed> $response
     * @param CommandRequest $cmd
     *
     * @return bool True if the command is completely finished.
     */
    public function handleResponse(array $response, CommandRequest $cmd): bool
    {
        if ($response['status'] === 'ERROR') {
            $cmd->promise->reject(ExceptionHandler::createFromWorkerError($response));

            return true;
        }

        if ($response['status'] === 'COMPLETED') {
            $resultData = isset($response['result']) && \is_array($response['result']) ? $response['result'] : [];

            $affectedRows = isset($resultData['affectedRows']) && \is_int($resultData['affectedRows']) ? $resultData['affectedRows'] : 0;
            $lastInsertId = isset($resultData['lastInsertId']) && \is_int($resultData['lastInsertId']) ? $resultData['lastInsertId'] : 0;

            /** @var array<int, array<string, mixed>> $rows */
            $rows = isset($resultData['rows']) && \is_array($resultData['rows']) ? $resultData['rows'] : [];

            $result = new Result(
                affectedRows: $affectedRows,
                lastInsertId: $lastInsertId,
                connectionId: spl_object_id($this->connection),
                rows: $rows
            );

            $cmd->promise->resolve($result);

            return true;
        }

        return false;
    }
}
