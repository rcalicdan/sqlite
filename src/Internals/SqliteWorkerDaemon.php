<?php

declare(strict_types=1);

namespace Hibla\Sqlite\Internals;

use Hibla\Sqlite\Handlers\DaemonQueryHandler;
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
    ) {
    }

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
            $this->writeError('init', $e);
            exit(1);
        }

        $stdin = fopen('php://stdin', 'r');
        $stdout = fopen('php://stdout', 'w');

        if (! \is_resource($stdin) || ! \is_resource($stdout)) {
            exit(1);
        }

        stream_set_blocking($stdin, true);
        stream_set_blocking($stdout, true);

        $queryHandler = new DaemonQueryHandler($this->db, $stdout);
        $streamHandler = new DaemonStreamHandler($this->db, $stdout);

        while (($line = fgets($stdin)) !== false) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }

            $request = json_decode($line, true);
            if (! \is_array($request)) {
                continue;
            }

            $id = isset($request['id']) && \is_string($request['id']) ? $request['id'] : 'unknown';
            $cmd = isset($request['cmd']) && \is_string($request['cmd']) ? $request['cmd'] : '';

            if ($id === 'unknown' || $cmd === '') {
                continue;
            }

            try {
                switch ($cmd) {
                    case 'query':
                    case 'execute':
                        $queryHandler->handle($request);

                        break;

                    case 'stream':
                        $streamHandler->handle($request);

                        break;

                    default:
                        throw new \RuntimeException('Unknown command: ' . $cmd);
                }
            } catch (\Throwable $e) {
                $this->writeError($id, $e, $stdout);
            }
        }
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
