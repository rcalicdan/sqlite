<?php

declare(strict_types=1);

namespace Hibla\Sqlite\Internals;

use Hibla\Promise\Interfaces\PromiseInterface;
use Hibla\Promise\Promise;
use Hibla\Sqlite\Interfaces\ConnectionInterface;
use Hibla\Sqlite\Utilities\ExceptionMapper;
use Hibla\Sqlite\ValueObjects\SqliteConfig;

/**
 * Fallback connection that executes SQLite commands synchronously in the main thread.
 * 
 * @internal
 */
final class SyncConnection implements ConnectionInterface
{
    private ?\SQLite3 $db = null;
    private bool $closed = true;

    public function __construct(
        private readonly SqliteConfig $config
    ) {
    }

    /**
     * {@inheritDoc}
     */
    public function connect(): PromiseInterface
    {
        try {
            $this->db = new \SQLite3($this->config->database);
            $this->db->enableExceptions(true);
            $this->db->busyTimeout($this->config->busyTimeout);
            $this->db->exec("PRAGMA journal_mode = {$this->config->journalMode}");
            
            $fkFlag = $this->config->foreignKeys ? 'ON' : 'OFF';
            $this->db->exec("PRAGMA foreign_keys = {$fkFlag}");
            
            $this->closed = false;
            return Promise::resolved($this);
        } catch (\Throwable $e) {
            return Promise::rejected(ExceptionMapper::map((int) $e->getCode(), $e->getMessage()));
        }
    }

    /**
     * {@inheritDoc}
     */
    public function query(string $sql): PromiseInterface
    {
        if ($this->closed || $this->db === null) {
            return Promise::rejected(ExceptionMapper::map(0, 'Connection closed.'));
        }

        try {
            $normalizedSql = strtoupper(ltrim($sql));
            $returnsRows = str_starts_with($normalizedSql, 'SELECT')
                || str_starts_with($normalizedSql, 'PRAGMA')
                || str_starts_with($normalizedSql, 'WITH');

            $rows = [];
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

            return Promise::resolved(new Result(
                affectedRows: $this->db->changes(),
                lastInsertId: $this->db->lastInsertRowID(),
                rows: $rows
            ));
        } catch (\Throwable $e) {
            return Promise::rejected(ExceptionMapper::map((int) $e->getCode(), $e->getMessage()));
        }
    }

    /**
     * {@inheritDoc}
     * 
     * @return PromiseInterface<SyncRowStream>
     */
    public function streamQuery(string $sql, int $bufferSize = 100): PromiseInterface
    {
        if ($this->closed || $this->db === null) {
            return Promise::rejected(ExceptionMapper::map(0, 'Connection closed.'));
        }

        try {
            $result = $this->db->query($sql);
            if ($result === false) {
                throw new \RuntimeException('Query failed.');
            }
            return Promise::resolved(new SyncRowStream($result));
        } catch (\Throwable $e) {
            return Promise::rejected(ExceptionMapper::map((int) $e->getCode(), $e->getMessage()));
        }
    }

    /**
     * {@inheritDoc}
     */
    public function prepare(string $sql): PromiseInterface
    {
        if ($this->closed) {
            return Promise::rejected(ExceptionMapper::map(0, 'Connection closed.'));
        }
        return Promise::resolved(new PreparedStatement($this, $sql));
    }

    /**
     * {@inheritDoc}
     */
    public function executeStatement(PreparedStatement $stmt, array $params): PromiseInterface
    {
        if ($this->closed || $this->db === null) {
            return Promise::rejected(ExceptionMapper::map(0, 'Connection closed.'));
        }

        try {
            $sqliteStmt = $this->db->prepare($stmt->parsedSql);
            if ($sqliteStmt === false) {
                throw new \RuntimeException('Failed to prepare statement.');
            }

            $this->bindParams($sqliteStmt, $params);
            $result = $sqliteStmt->execute();

            $rows = [];
            if ($result !== false && $result->numColumns() > 0) {
                while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
                    $rows[] = $row;
                }
                $result->finalize();
            }
            $sqliteStmt->close();

            return Promise::resolved(new Result(
                affectedRows: $this->db->changes(),
                lastInsertId: $this->db->lastInsertRowID(),
                rows: $rows
            ));
        } catch (\Throwable $e) {
            return Promise::rejected(ExceptionMapper::map((int) $e->getCode(), $e->getMessage()));
        }
    }

    /**
     * {@inheritDoc}
     * 
     * @return PromiseInterface<SyncRowStream>
     */
    public function executeStream(PreparedStatement $stmt, array $params, int $bufferSize = 100): PromiseInterface
    {
        if ($this->closed || $this->db === null) {
            return Promise::rejected(ExceptionMapper::map(0, 'Connection closed.'));
        }

        try {
            $sqliteStmt = $this->db->prepare($stmt->parsedSql);
            if ($sqliteStmt === false) {
                throw new \RuntimeException('Failed to prepare statement.');
            }

            $this->bindParams($sqliteStmt, $params);
            $result = $sqliteStmt->execute();

            if ($result === false) {
                throw new \RuntimeException('Execution failed.');
            }

            return Promise::resolved(new SyncRowStream($result));
        } catch (\Throwable $e) {
            return Promise::rejected(ExceptionMapper::map((int) $e->getCode(), $e->getMessage()));
        }
    }

    /**
     * {@inheritDoc}
     */
    public function ping(): PromiseInterface
    {
        return $this->closed ? Promise::rejected(ExceptionMapper::map(0, 'Closed')) : Promise::resolved(true);
    }

    /**
     * {@inheritDoc}
     */
    public function reset(): PromiseInterface
    {
        return $this->closed ? Promise::rejected(ExceptionMapper::map(0, 'Closed')) : Promise::resolved(true);
    }

    /**
     * {@inheritDoc}
     */
    public function close(bool $killProcess = true): void
    {
        if (!$this->closed && $this->db !== null) {
            $this->db->close();
            $this->db = null;
        }
        $this->closed = true;
    }

    /**
     * {@inheritDoc}
     */
    public function isClosed(): bool
    {
        return $this->closed;
    }

    /**
     * {@inheritDoc}
     */
    public function pause(): void {}

    /**
     * {@inheritDoc}
     */
    public function resume(): void {}

    /**
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

            if (\is_bool($value)) {
                $value = $value ? 1 : 0;
            }

            $bindKey = \is_int($key) ? $key + 1 : $key;
            $stmt->bindValue($bindKey, $value, $type);
        }
    }
}