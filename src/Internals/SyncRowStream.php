<?php

declare(strict_types=1);

namespace Hibla\Sqlite\Internals;

use Hibla\Promise\Exceptions\CancelledException;
use Hibla\Promise\Interfaces\PromiseInterface;
use Hibla\Promise\Promise;
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

    /**
     * @var Promise<void>
     */
    private readonly Promise $closePromise;

    public function __construct(
        private readonly \SQLite3Result $result
    ) {
        $cols = $this->result->numColumns();
        for ($i = 0; $i < $cols; $i++) {
            $this->columnNames[] = $this->result->columnName($i);
        }

        /** @var Promise<void> $closePromise */
        $closePromise = new Promise();
        $this->closePromise = $closePromise;
    }

    public int $columnCount {
        get => \count($this->columnNames);
    }

    /**
     * {@inheritDoc}
     */
    public array $columns {
        get => $this->columnNames;
    }

    /**
     * Returns a promise that resolves when the stream is fully consumed or cancelled.
     * Used by the client to know when it is safe to release the connection.
     *
     * @internal
     *
     * @return PromiseInterface<void>
     */
    public function onClose(): PromiseInterface
    {
        return $this->closePromise;
    }

    /**
     * {@inheritDoc}
     *
     * @return \Generator<int, array<string, mixed>>
     */
    public function getIterator(): \Generator
    {
        while (! $this->cancelled && ($row = $this->result->fetchArray(SQLITE3_ASSOC)) !== false) {
            yield $row;
        }

        if (! $this->cancelled) {
            $this->result->finalize();

            if ($this->closePromise->isPending()) {
                $this->closePromise->resolve(null);
            }
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

        if ($this->closePromise->isPending()) {
            $this->closePromise->reject(new CancelledException('Stream was cancelled.'));
        }
    }

    /**
     * {@inheritDoc}
     */
    public function isCancelled(): bool
    {
        return $this->cancelled;
    }
}
