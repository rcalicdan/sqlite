<?php

declare(strict_types=1);

namespace Hibla\Sqlite\Handlers;

use Hibla\Sql\Exceptions\ConnectionException;
use Hibla\Sqlite\Internals\AsyncConnection;
use Hibla\Stream\PromiseReadableStream;

use function Hibla\async;
use function Hibla\await;

/**
 * @internal
 *
 * Dedicated handler to read from the daemon's STDOUT pipe, reconstruct chunked
 * NDJSON strings into complete objects, discard malformed garbage, and route
 * valid IPC frames back to the connection.
 */
final class JsonIpcFrameHandler
{
    public function __construct(
        private readonly AsyncConnection $connection,
        private readonly PromiseReadableStream $stdout
    ) {
    }

    public function start(): void
    {
        async(function (): void {
            /** @var string $buffer */
            $buffer = '';

            try {
                while (null !== ($line = await($this->stdout->readLineAsync()))) {
                    $buffer .= $line;

                    if (trim($buffer) === '') {
                        $buffer = '';

                        continue;
                    }

                    $response = \json_decode($buffer, true);

                    if (\is_array($response)) {
                        // Successful decode: clear the buffer for the next frame
                        $buffer = '';
                        $this->connection->handleIpcFrame($response);
                    } else {
                        // If decoding fails, it might be a truncated chunk OR malformed garbage.
                        $isCompleteLine = str_ends_with($line, "\n") || str_ends_with($line, "\r");
                        $ltrimmed = ltrim($buffer);

                        if ($ltrimmed !== '' && $ltrimmed[0] !== '{') {
                            // Valid frames ALWAYS start with '{'. If not, it's non-JSON pollution
                            $buffer = '';
                        } elseif ($isCompleteLine) {
                            // Started with '{' but reached the end of the line and still failed to decode.
                            // It's a completely malformed JSON string -> Discard.
                            $buffer = '';
                        }

                        // Otherwise, it starts with '{' but no newline yet -> Truncated chunk. Keep buffering.
                        continue;
                    }

                    // Handles stream backpressure safely between valid frames
                    $this->connection->awaitPauseCheck();
                }
            } catch (\Throwable $e) {
                $this->connection->handleCrash(new ConnectionException('SQLite IPC pipe read loop failed.', 0, $e));
            } finally {
                $this->connection->handleCrash(new ConnectionException('SQLite process stream closed.'));
            }
        });
    }
}
