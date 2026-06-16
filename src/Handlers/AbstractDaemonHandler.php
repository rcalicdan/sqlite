<?php

declare(strict_types=1);

namespace Hibla\Sqlite\Handlers;

/**
 * @internal
 */
abstract class AbstractDaemonHandler
{
    /**
     * @param resource $stdout
     */
    public function __construct(
        protected readonly \SQLite3 $db,
        protected readonly mixed $stdout
    ) {
    }

    /**
     * Writes a JSON-encoded status frame back to the parent process.
     *
     * @param array<string, mixed> $data
     */
    protected function writeFrame(array $data): void
    {
        try {
            $payload = json_encode($data, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n";
            fwrite($this->stdout, $payload);
            fflush($this->stdout);
        } catch (\JsonException $e) {
            $errorPayload = json_encode([
                'id' => $data['id'] ?? 'unknown',
                'status' => 'ERROR',
                'errorCode' => 0,
                'errorMessage' => 'JSON Encoding Error in worker: ' . $e->getMessage(),
            ]) . "\n";
            fwrite($this->stdout, $errorPayload);
            fflush($this->stdout);
        }
    }

    /**
     * Binds parameters with their correct SQLite datatypes.
     *
     * @param array<int|string, mixed> $params
     */
    protected function bindParams(\SQLite3Stmt $stmt, array $params): void
    {
        foreach ($params as $key => $value) {
            $type = match (true) {
                \is_int($value) => SQLITE3_INTEGER,
                \is_float($value) => SQLITE3_FLOAT,
                \is_null($value) => SQLITE3_NULL,
                \is_bool($value) => SQLITE3_INTEGER,
                default => SQLITE3_TEXT,
            };

            if (\is_bool($value)) {
                $value = $value ? 1 : 0;
            }

            $bindKey = \is_int($key) ? $key + 1 : $key;
            $stmt->bindValue($bindKey, $value, $type);
        }
    }
}
