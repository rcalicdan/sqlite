<?php

declare(strict_types=1);

namespace Hibla\Sqlite\Internals;

use Hibla\Sql\RowStream as RowStreamInterface;

/**
 * @internal
 */
final class SyncRowStream implements RowStreamInterface
{
    private bool $cancelled = false;

    /**
     * @var array<int, string>
     */
    private array $columnNames = [];

    public function __construct(
        private readonly \SQLite3Result $result
    ) {
        $cols = $this->result->numColumns();
        for ($i = 0; $i < $cols; $i++) {
            $this->columnNames[] = $this->result->columnName($i);
        }
    }

    public int $columnCount {
        get => \count($this->columnNames);
    }

    /**
     * @var array<int, string>
     */
    public array $columns {
        get => $this->columnNames;
    }

    /**
     * {@inheritDoc}
     * 
     * @return \Generator<int, array<string, mixed>>
     */
    public function getIterator(): \Generator
    {
        while (!$this->cancelled && ($row = $this->result->fetchArray(SQLITE3_ASSOC)) !== false) {
            yield $row;
        }

        if (!$this->cancelled) {
            $this->result->finalize();
        }
    }

    /**
     * {@inheritDoc}
     */
    public function cancel(): void
    {
        if ($this->cancelled) {
            return;
        }

        $this->cancelled = true;
        @$this->result->finalize(); 
    }

    /**
     * {@inheritDoc}
     */
    public function isCancelled(): bool
    {
        return $this->cancelled;
    }
}