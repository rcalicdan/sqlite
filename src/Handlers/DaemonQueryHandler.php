<?php

declare(strict_types=1);

namespace Hibla\Sqlite\Handlers;

/**
 * Handles standard, buffered query and execution commands inside the worker daemon.
 *
 * @internal
 */
final class DaemonQueryHandler extends AbstractDaemonHandler
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

        $this->writeFrame([
            'id' => $id,
            'status' => 'COMPLETED',
            'result' => [
                'rows' => $rows,
                'affectedRows' => $this->db->changes(),
                'lastInsertId' => $this->db->lastInsertRowID(),
            ],
        ]);
    }
}
