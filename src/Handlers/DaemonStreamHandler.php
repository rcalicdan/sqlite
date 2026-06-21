<?php

declare(strict_types=1);

namespace Hibla\Sqlite\Handlers;

use Hibla\Sqlite\Utilities\BlobCodec;

/**
 * Handles row-by-row streaming queries and executions inside the worker daemon.
 *
 * @internal
 */
final class DaemonStreamHandler extends AbstractDaemonHandler
{
    /**
     * @param array<int|string, mixed> $request
     */
    public function handle(array $request): void
    {
        $id = isset($request['id']) && \is_string($request['id']) ? $request['id'] : '';
        $sql = isset($request['sql']) && \is_string($request['sql']) ? $request['sql'] : '';
        $params = isset($request['params']) && \is_array($request['params']) ? $request['params'] : [];

        $normalizedSql = strtoupper(ltrim($sql));
        $returnsRows = str_starts_with($normalizedSql, 'SELECT')
            || str_starts_with($normalizedSql, 'PRAGMA')
            || str_starts_with($normalizedSql, 'WITH');

        $stmt = $this->db->prepare($sql);
        if ($stmt === false) {
            throw new \RuntimeException('Failed to prepare SQLite stream statement.');
        }

        $this->bindParams($stmt, $params);
        $result = $stmt->execute();

        $completed = true;

        if ($returnsRows && $result !== false) {
            while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
                $success = $this->writeFrame([
                    'id' => $id,
                    'status' => 'ROW',
                    'row' => BlobCodec::encodeArray($row),
                ]);

                // If writing fails, the parent cancelled/disconnected. Stop fetching immediately!
                if (! $success) {
                    $completed = false;

                    break;
                }
            }
        }

        if ($completed) {
            $this->writeFrame([
                'id' => $id,
                'status' => 'COMPLETED',
                'result' => [
                    'affectedRows' => $this->db->changes(),
                    'lastInsertId' => $this->db->lastInsertRowID(),
                ],
            ]);
        }

        if ($result !== false) {
            $result->finalize();
        }
        $stmt->close();
    }
}
