<?php

declare(strict_types=1);

namespace Hibla\Sqlite\Internals;

use Hibla\Sqlite\Handlers\DaemonQueryHandler;
use Hibla\Sqlite\Handlers\DaemonResetHandler;
use Hibla\Sqlite\Handlers\DaemonStreamHandler;
use Hibla\Sqlite\ValueObjects\SqliteConfig;

/**
 * The invokable RPC Daemon that runs inside the isolated parallel worker.
 *
 * @internal
 */
final class SqliteWorkerDaemon
{
    private \SQLite3 $db;

    public function __construct(
        private readonly SqliteConfig $config
    ) {}

    public function __invoke(): void
    {
        try {
            $this->db = new \SQLite3($this->config->database);
            $this->db->enableExceptions(true);

            $this->db->busyTimeout($this->config->busyTimeout);
            $this->db->exec("PRAGMA journal_mode = {$this->config->journalMode}");

            $fkFlag = $this->config->foreignKeys ? 'ON' : 'OFF';
            $this->db->exec("PRAGMA foreign_keys = {$fkFlag}");
        } catch (\Throwable $e) {
            fwrite(STDERR, "[WORKER FATAL] Init failed: " . $e->getMessage() . "\n");
            $this->writeError('init', $e);
            exit(1);
        }

        $stdin = fopen('php://stdin', 'r');
        $stdout = fopen('php://stdout', 'w');

        if (! \is_resource($stdin) || ! \is_resource($stdout)) {
            fwrite(STDERR, "[WORKER FATAL] Could not open STDIN/STDOUT\n");
            exit(1);
        }

        stream_set_blocking($stdin, true);
        stream_set_blocking($stdout, true);

        $queryHandler = new DaemonQueryHandler($this->db, $stdout);
        $streamHandler = new DaemonStreamHandler($this->db, $stdout);
        $resetHandler = new DaemonResetHandler($this->db, $stdout, $this->config);

        $requestCount = 0;
        $memoryLimitBytes = $this->config->memoryLimitMB * 1024 * 1024;

        fwrite(STDERR, "[WORKER] Booted successfully. Entering select loop...\n");

        while (true) {
            $read = [$stdin];
            $write = null;
            $except = null;

            $changed = @stream_select($read, $write, $except, null);

            if ($changed === false) {
                fwrite(STDERR, "[WORKER] stream_select returned false (signal interrupt?).\n");
                continue;
            }

            if (feof($stdin)) {
                fwrite(STDERR, "[WORKER] feof(stdin) is true immediately after select.\n");
                break;
            }

            $line = fgets($stdin);

            if ($line === false) {
                if (feof($stdin)) {
                    fwrite(STDERR, "[WORKER] fgets returned false and feof is true. Exiting loop.\n");
                    break;
                }
                fwrite(STDERR, "[WORKER] fgets returned false but feof is false. Looping.\n");
                continue;
            }

            $line = trim($line);
            if ($line === '') {
                continue;
            }

            fwrite(STDERR, "[WORKER] Received payload: " . substr($line, 0, 80) . "...\n");

            $request = json_decode($line, true);
            if (! \is_array($request)) {
                fwrite(STDERR, "[WORKER] Failed to decode JSON!\n");
                continue;
            }

            $id = isset($request['id']) && \is_string($request['id']) ? $request['id'] : 'unknown';
            $cmd = isset($request['cmd']) && \is_string($request['cmd']) ? $request['cmd'] : '';

            if ($id === 'unknown' || $cmd === '') {
                continue;
            }

            try {
                fwrite(STDERR, "[WORKER] Executing command: {$cmd}\n");
                switch ($cmd) {
                    case 'query':
                    case 'execute':
                        $queryHandler->handle($request);
                        break;
                    case 'stream':
                        $streamHandler->handle($request);
                        break;
                    case 'reset':
                        $resetHandler->handle($request);
                        break;
                    default:
                        throw new \RuntimeException('Unknown command: ' . $cmd);
                }
                fwrite(STDERR, "[WORKER] Command {$cmd} completed successfully.\n");
            } catch (\Throwable $e) {
                fwrite(STDERR, "[WORKER ERROR] Command failed: " . $e->getMessage() . "\n");
                $this->writeError($id, $e, $stdout);
            }

            $requestCount++;
            if ($requestCount % 1000 === 0) {
                gc_collect_cycles();
                if (memory_get_usage() > $memoryLimitBytes) {
                    fwrite(STDERR, "[WORKER] Memory limit exceeded, exiting naturally.\n");
                    $this->db->close();
                    exit(0);
                }
            }
        }

        fwrite(STDERR, "[WORKER] Process terminating.\n");
    }
    /**
     * @param resource $stdout
     * @param array<string, mixed> $data
     */
    private function writeFrame($stdout, array $data): void
    {
        if (! \is_resource($stdout)) {
            return;
        }

        try {
            $payload = json_encode($data, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n";
            fwrite($stdout, $payload);
            fflush($stdout);
        } catch (\JsonException $e) {
            $errorPayload = json_encode([
                'id' => $data['id'] ?? 'unknown',
                'status' => 'ERROR',
                'errorCode' => 0,
                'errorMessage' => 'JSON Encoding Error in worker: ' . $e->getMessage(),
            ]) . "\n";
            fwrite($stdout, $errorPayload);
            fflush($stdout);
        }
    }

    /**
     * @param resource|null $stdout
     */
    private function writeError(string $id, \Throwable $e, $stdout = null): void
    {
        $target = $stdout ?? STDOUT;
        if (! \is_resource($target)) {
            return;
        }

        $this->writeFrame($target, [
            'id' => $id,
            'status' => 'ERROR',
            'class' => \get_class($e),
            'message' => $e->getMessage(),
            'code' => $e->getCode(),
            'file' => $e->getFile(),
            'line' => $e->getLine(),
            'stack_trace' => $e->getTraceAsString(),
        ]);
    }
}
