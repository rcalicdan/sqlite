<?php

declare(strict_types=1);

namespace Hibla\Sqlite\Internals;

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
                $this->handleCommand($request, $stdout);
            } catch (\Throwable $e) {
                $this->writeError($id, $e, $stdout);
            }
        }
    }

    /**
     * @param array<int|string, mixed> $request
     * @param resource $stdout
     */
    private function handleCommand(array $request, $stdout): void
    {
        $cmd = isset($request['cmd']) && \is_string($request['cmd']) ? $request['cmd'] : '';
        $id = isset($request['id']) && \is_string($request['id']) ? $request['id'] : '';
        $sql = isset($request['sql']) && \is_string($request['sql']) ? $request['sql'] : '';
        $params = isset($request['params']) && \is_array($request['params']) ? $request['params'] : [];

        $normalizedSql = strtoupper(ltrim($sql));
        $returnsRows = str_starts_with($normalizedSql, 'SELECT')
            || str_starts_with($normalizedSql, 'PRAGMA')
            || str_starts_with($normalizedSql, 'WITH');

        switch ($cmd) {
            case 'query':
            case 'execute':
                $rows = [];

                if ($params === []) {
                    if ($returnsRows) {
                        $result = $this->db->query($sql);
                        if ($result !== false) {
                            while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
                                $rows[] = $row;
                            }
                            $result->finalize();
                        }
                    } else {
                        $this->db->exec($sql);
                    }
                } else {
                    $stmt = $this->db->prepare($sql);
                    if ($stmt === false) {
                        throw new \RuntimeException('Failed to prepare SQLite query statement.');
                    }

                    $this->bindParams($stmt, $params);
                    $result = $stmt->execute();

                    if ($returnsRows && $result !== false) {
                        while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
                            $rows[] = $row;
                        }
                    }

                    if ($result !== false) {
                        $result->finalize();
                    }
                    $stmt->close();
                }

                $this->writeFrame($stdout, [
                    'id' => $id,
                    'status' => 'COMPLETED',
                    'result' => [
                        'rows' => $rows,
                        'affectedRows' => $this->db->changes(),
                        'lastInsertId' => $this->db->lastInsertRowID(),
                    ],
                ]);

                break;

            case 'stream':
                $stmt = $this->db->prepare($sql);
                if ($stmt === false) {
                    throw new \RuntimeException('Failed to prepare SQLite stream statement.');
                }

                $this->bindParams($stmt, $params);
                $result = $stmt->execute();

                if ($returnsRows && $result !== false) {
                    while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
                        $this->writeFrame($stdout, [
                            'id' => $id,
                            'status' => 'ROW',
                            'row' => $row,
                        ]);
                    }
                }

                $this->writeFrame($stdout, [
                    'id' => $id,
                    'status' => 'COMPLETED',
                    'result' => [
                        'affectedRows' => $this->db->changes(),
                        'lastInsertId' => $this->db->lastInsertRowID(),
                    ],
                ]);

                if ($result !== false) {
                    $result->finalize();
                }
                $stmt->close();

                break;

            default:
                throw new \RuntimeException('Unknown command: ' . $cmd);
        }
    }

    /**
     * @param \SQLite3Stmt $stmt
     * @param array<int|string, mixed> $params
     */
    private function bindParams(\SQLite3Stmt $stmt, array $params): void
    {
        foreach ($params as $key => $value) {
            $type = match (true) {
                \is_int($value) => SQLITE3_INTEGER,
                \is_float($value) => SQLITE3_FLOAT,
                \is_null($value) => SQLITE3_NULL,
                \is_bool($value) => SQLITE3_INTEGER,
                default => SQLITE3_TEXT,
            };

            if (is_bool($value)) {
                $value = $value ? 1 : 0;
            }

            $bindKey = is_int($key) ? $key + 1 : $key;
            $stmt->bindValue($bindKey, $value, $type);
        }
    }

    /**
     * @param resource $stdout
     * @param array<string, mixed> $data
     */
    private function writeFrame($stdout, array $data): void
    {
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
            'errorCode' => $e->getCode(),
            'errorMessage' => $e->getMessage(),
        ]);
    }
}
