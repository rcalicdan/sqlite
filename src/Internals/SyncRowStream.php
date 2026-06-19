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
        try {
            while (true) {
                if ($this->cancelled) {
                    throw new CancelledException('Stream was cancelled.');
                }

                try {
                    $row = $this->result->fetchArray(SQLITE3_ASSOC);
                } catch (\Throwable $e) {
                    if ($this->cancelled) {
                        throw new CancelledException('Stream was cancelled.');
                    }
                    throw $e;
                }

                if ($row === false) {
                    break;
                }

                yield $row;

                // @phpstan-ignore-next-line (The $this->cancelled state can be mutated asynchronously by other Fibers during the yield suspension)
                if ($this->cancelled) {
                    throw new CancelledException('Stream was cancelled.');
                }
            }
        } finally {
            try {
                @$this->result->finalize();
            } catch (\Throwable $e) {
                // Ignore any errors that occur while finalizing the result.
            }
        }

        if ($this->closePromise->isPending()) {
            $this->closePromise->resolve(null);
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

        try {
            @$this->result->finalize();
        } catch (\Throwable $e) {
            // Ignore any errors that occur while finalizing the result.
        }

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