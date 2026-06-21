<?php

declare(strict_types=1);

namespace Hibla\Sqlite\Handlers;

use Hibla\Sqlite\Utilities\BlobCodec;

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
     *
     * @return bool True if writing succeeded, false if the pipe is broken.
     */
    protected function writeFrame(array $data): bool
    {
        try {
            $payload = json_encode($data, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n";
            $written = @fwrite($this->stdout, $payload);
            @fflush($this->stdout);

            return $written !== false;
        } catch (\JsonException $e) {
            $errorPayload = json_encode([
                'id' => $data['id'] ?? 'unknown',
                'status' => 'ERROR',
                'errorCode' => 0,
                'errorMessage' => 'JSON Encoding Error in worker: ' . $e->getMessage(),
            ]) . "\n";
            @fwrite($this->stdout, $errorPayload);
            @fflush($this->stdout);

            return false;
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
            $isBlob = \is_array($value)
                && isset($value[BlobCodec::BLOB_KEY])
                && \is_string($value[BlobCodec::BLOB_KEY]);

            $type = match (true) {
                \is_int($value) => SQLITE3_INTEGER,
                \is_float($value) => SQLITE3_FLOAT,
                \is_null($value) => SQLITE3_NULL,
                \is_bool($value) => SQLITE3_INTEGER,
                $isBlob => SQLITE3_BLOB,
                default => SQLITE3_TEXT,
            };

            if ($type === SQLITE3_BLOB && \is_array($value) && \is_string($value[BlobCodec::BLOB_KEY])) {
                $decoded = \base64_decode($value[BlobCodec::BLOB_KEY], true);
                $value = $decoded !== false ? $decoded : '';
            } elseif (\is_bool($value)) {
                $value = $value ? 1 : 0;
            }

            $bindKey = \is_int($key) ? $key + 1 : $key;
            $stmt->bindValue($bindKey, $value, $type);
        }
    }
}
