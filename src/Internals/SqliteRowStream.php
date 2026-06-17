<?php

declare(strict_types=1);

namespace Hibla\Sqlite\Internals;

use Hibla\Promise\Exceptions\CancelledException;
use Hibla\Promise\Interfaces\PromiseInterface;
use Hibla\Promise\Promise;
use Hibla\Sql\RowStream as RowStreamInterface;
use SplQueue;

use function Hibla\await;

/**
 * Concrete implementation of unbuffered row streaming for SQLite.
 *
 * @internal
 */
final class SqliteRowStream implements RowStreamInterface
{
    /**
     * @var SplQueue<array<string, mixed>>
     */
    private SplQueue $buffer;

    /**
     * @var array<int, string>
     */
    private array $columnNames = [];

    /**
     * @var Promise<array<string, mixed>|null>|null
     */
    private ?Promise $waiter = null;

    /**
     * @var Promise<void>
     */
    private readonly Promise $closePromise;

    private bool $completed = false;

    private bool $cancelled = false;

    private ?\Throwable $error = null;

    /**
     * @var (\Closure(bool): void)|null Controls backpressure
     */
    public ?\Closure $backpressureHandler = null;

    private ?\Closure $onCancel = null;

    /**
     * @param PromiseInterface<mixed>|null $commandPromise
     */
    public function __construct(
        private readonly int $maxBufferSize = 100,
        private readonly ?PromiseInterface $commandPromise = null
    ) {
        $this->buffer = new SplQueue();
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
     * @return PromiseInterface<void>
     */
    public function onClose(): PromiseInterface
    {
        return $this->closePromise;
    }

    /**
     * {@inheritDoc}
     */
    public function getIterator(): \Generator
    {
        while (true) {
            if ($this->error !== null) {
                throw $this->error;
            }

            if (! $this->buffer->isEmpty()) {
                $row = $this->buffer->dequeue();

                if ($this->backpressureHandler !== null && $this->buffer->count() < ($this->maxBufferSize / 2)) {
                    ($this->backpressureHandler)(false);
                }

                yield $row;

                continue;
            }

            if ($this->completed) {
                break;
            }

            /** @var Promise<array<string, mixed>|null> $waiter */
            $waiter = new Promise();
            $this->waiter = $waiter;

            /** @var array<string, mixed>|null $row */
            $row = await($waiter);
            $this->waiter = null;

            if ($row === null) {
                break;
            }

            yield $row;
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
        $this->completed = true;
        $this->error = new CancelledException('Stream was cancelled.');

        if ($this->onCancel !== null) {
            ($this->onCancel)();
        }

        if ($this->commandPromise !== null && ! $this->commandPromise->isSettled()) {
            $this->commandPromise->cancel();
        }

        if ($this->waiter !== null) {
            $waiter = $this->waiter;
            $this->waiter = null;
            $waiter->reject($this->error);
        }

        if ($this->closePromise->isPending()) {
            $this->closePromise->reject($this->error);
        }

        /** @var SplQueue<array<string, mixed>> $buffer */
        $buffer = new SplQueue();
        $this->buffer = $buffer;
    }

    /**
     * {@inheritDoc}
     */
    public function isCancelled(): bool
    {
        return $this->cancelled;
    }

    /**
     * @param array<string, mixed> $row
     */
    public function push(array $row): void
    {
        if ($this->cancelled) {
            return;
        }

        if ($this->columnNames === []) {
            $this->columnNames = array_keys($row);
        }

        if ($this->waiter !== null) {
            $waiter = $this->waiter;
            $this->waiter = null;
            $waiter->resolve($row);
        } else {
            $this->buffer->enqueue($row);

            if ($this->backpressureHandler !== null && $this->buffer->count() >= $this->maxBufferSize) {
                ($this->backpressureHandler)(true);
            }
        }
    }

    public function complete(): void
    {
        if ($this->cancelled) {
            return;
        }

        $this->completed = true;
        $this->backpressureHandler = null;

        if ($this->waiter !== null) {
            $waiter = $this->waiter;
            $this->waiter = null;
            $waiter->resolve(null);
        }

        if ($this->closePromise->isPending()) {
            $this->closePromise->resolve(null);
        }
    }

    public function error(\Throwable $e): void
    {
        if ($this->cancelled) {
            return;
        }

        $this->error = $e;
        $this->completed = true;

        if ($this->waiter !== null) {
            $waiter = $this->waiter;
            $this->waiter = null;
            $waiter->reject($e);
        }

        if ($this->closePromise->isPending()) {
            $this->closePromise->reject($e);
        }
    }

    public function setOnCancel(\Closure $onCancel): void
    {
        $this->onCancel = $onCancel;
    }
}