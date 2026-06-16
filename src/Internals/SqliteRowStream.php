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
    private SplQueue $buffer;
    private array $columnNames = [];
    private ?Promise $waiter = null;
    private bool $completed = false;
    private bool $cancelled = false;
    private ?\Throwable $error = null;

    /**
     * @var (\Closure(bool): void)|null Controls backpressure
     */
    public ?\Closure $backpressureHandler = null;

    private ?\Closure $onCancel = null;

    public function __construct(
        private readonly int $maxBufferSize = 100,
        private readonly ?PromiseInterface $commandPromise = null
    ) {
        $this->buffer = new SplQueue();
    }

    public int $columnCount {
        get => \count($this->columnNames);
    }

    public array $columns {
        get => $this->columnNames;
    }

    public function getIterator(): \Generator
    {
        while (true) {
            if ($this->error !== null) {
                throw $this->error;
            }

            if (!$this->buffer->isEmpty()) {
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

            $this->waiter = new Promise();
            $row = await($this->waiter);
            $this->waiter = null;

            if ($row === null) {
                break;
            }

            yield $row;
        }
    }

    public function cancel(): void
    {
        if ($this->cancelled) return;

        $this->cancelled = true;
        $this->completed = true;
        $this->error = new CancelledException('Stream was cancelled.');

        if ($this->onCancel !== null) {
            ($this->onCancel)();
        }

        if ($this->commandPromise !== null && !$this->commandPromise->isSettled()) {
            $this->commandPromise->cancel();
        }

        if ($this->waiter !== null) {
            $waiter = $this->waiter;
            $this->waiter = null;
            $waiter->reject($this->error);
        }

        $this->buffer = new SplQueue();
    }

    public function isCancelled(): bool
    {
        return $this->cancelled;
    }

    public function push(array $row): void
    {
        if ($this->cancelled) return;

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
        if ($this->cancelled) return;

        $this->completed = true;
        $this->backpressureHandler = null;

        if ($this->waiter !== null) {
            $waiter = $this->waiter;
            $this->waiter = null;
            $waiter->resolve(null);
        }
    }

    public function error(\Throwable $e): void
    {
        if ($this->cancelled) return;

        $this->error = $e;
        $this->completed = true;

        if ($this->waiter !== null) {
            $waiter = $this->waiter;
            $this->waiter = null;
            $waiter->reject($e);
        }
    }

    public function setOnCancel(\Closure $onCancel): void
    {
        $this->onCancel = $onCancel;
    }
}