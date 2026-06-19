<?php

declare(strict_types=1);

namespace Hibla\Sqlite\Handlers;

use Hibla\Sqlite\Internals\AsyncConnection;
use Hibla\Sqlite\Utilities\ExceptionHandler;
use Hibla\Sqlite\ValueObjects\CommandRequest;

/**
 * Handles connection state reset (soft reset equivalent to DISCARD ALL).
 *
 * @internal
 */
final class ConnectionResetHandler
{
    public function __construct(private readonly AsyncConnection $connection)
    {
    }

    public function start(CommandRequest $request): void
    {
        $payload = \json_encode([
            'id' => $request->id,
            'cmd' => 'reset',
        ], JSON_UNESCAPED_SLASHES);

        $this->connection->writeIpc($payload . "\n");
    }

    /**
     * Processes incoming JSON frames for reset commands.
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
            $cmd->promise->resolve(true);

            return true;
        }

        return false;
    }
}
