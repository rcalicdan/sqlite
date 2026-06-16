<?php

declare(strict_types=1);

namespace Hibla\Sqlite\Internals;

use Hibla\Sql\Result as ResultInterface;

/**
 * Unified result object for SQLite query executions.
 *
 * @internal
 */
final class Result implements ResultInterface
{
    public readonly int $rowCount;

    public readonly int $columnCount;

    /**
     * @var array<int, string>
     */
    public readonly array $columns;

    private int $position = 0;

    /**
     * @param int $affectedRows The number of rows affected by an INSERT/UPDATE/DELETE statement.
     * @param int $lastInsertId The last inserted row ID.
     * @param array<int, array<string, mixed>> $rows The fetched rows.
     */
    public function __construct(
        public readonly int $affectedRows = 0,
        public readonly int $lastInsertId = 0,
        private readonly array $rows = []
    ) {
        $this->rowCount = \count($this->rows);

        if ($this->rowCount > 0) {
            $this->columns = array_keys($this->rows[0]);
            $this->columnCount = \count($this->columns);
        } else {
            $this->columns = [];
            $this->columnCount = 0;
        }
    }

    /**
     * {@inheritDoc}
     */
    public function hasAffectedRows(): bool
    {
        return $this->affectedRows > 0;
    }

    /**
     * {@inheritDoc}
     */
    public function hasLastInsertId(): bool
    {
        return $this->lastInsertId > 0;
    }

    /**
     * {@inheritDoc}
     */
    public function isEmpty(): bool
    {
        return $this->rowCount === 0;
    }

    /**
     * {@inheritDoc}
     */
    public function fetchAssoc(): ?array
    {
        if ($this->position >= $this->rowCount) {
            return null;
        }

        return $this->rows[$this->position++];
    }

    /**
     * {@inheritDoc}
     */
    public function fetchAll(): array
    {
        return $this->rows;
    }

    /**
     * {@inheritDoc}
     */
    public function fetchColumn(string|int $column = 0): array
    {
        if (\is_int($column)) {
            return array_map(fn (array $row) => array_values($row)[$column] ?? null, $this->rows);
        }

        return array_map(fn (array $row) => $row[$column] ?? null, $this->rows);
    }

    /**
     * {@inheritDoc}
     */
    public function fetchOne(): ?array
    {
        return $this->rows[0] ?? null;
    }

    /**
     * {@inheritDoc}
     */
    public function getIterator(): \Traversable
    {
        return new \ArrayIterator($this->rows);
    }
}
