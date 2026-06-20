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
    private bool $isReading = false;

    private string $buffer = '';

    public function __construct(
        private readonly AsyncConnection $connection,
        private readonly PromiseReadableStream $stdout
    ) {
    }

    /**
     * Starts the read loop if it isn't already running.
     * The loop will automatically terminate when the connection has no active commands,
     * freeing the event loop and allowing PHP to shut down naturally.
     */
    public function readLoop(): void
    {
        if ($this->isReading) {
            return;
        }

        $this->isReading = true;

        async(function (): void {
            try {
                // Only read from the stream when there is an actual command pending
                while ($this->connection->hasActiveCommand()) {

                    $line = await($this->stdout->readLineAsync());

                    if ($line === null) {
                        throw new ConnectionException('SQLite process stream closed unexpectedly.');
                    }

                    $this->buffer .= $line;

                    if (trim($this->buffer) === '') {
                        $this->buffer = '';

                        continue;
                    }

                    $response = \json_decode($this->buffer, true);

                    if (\is_array($response)) {
                        // Successful decode: clear the buffer for the next frame
                        $this->buffer = '';

                        /** @var array<string, mixed> $response */
                        $this->connection->handleIpcFrame($response);
                    } else {
                        // If decoding fails, it might be a truncated chunk OR malformed garbage.
                        $isCompleteLine = str_ends_with($line, "\n") || str_ends_with($line, "\r");
                        $ltrimmed = ltrim($this->buffer);

                        if ($ltrimmed !== '' && $ltrimmed[0] !== '{') {
                            // Valid frames ALWAYS start with '{'. If not, it's non-JSON pollution
                            $this->buffer = '';
                        } elseif ($isCompleteLine) {
                            // Started with '{' but reached the end of the line and still failed to decode.
                            // It's a completely malformed JSON string -> Discard.
                            $this->buffer = '';
                        }

                        continue; // Skip the backpressure check for incomplete chunks
                    }

                    // Handles stream backpressure safely between valid frames
                    $this->connection->awaitPauseCheck();
                }
            } catch (\Throwable $e) {
                $this->connection->handleCrash(new ConnectionException('SQLite IPC pipe read loop failed.', 0, $e));
            } finally {
                $this->isReading = false;
            }
        })->catch(function (\Throwable $e): void {
            // Ignore unhandled fiber exceptions natively
        });
    }
}
