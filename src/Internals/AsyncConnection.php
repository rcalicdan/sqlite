<?php

declare(strict_types=1);

namespace Hibla\Sqlite\Internals;

use Hibla\Promise\Interfaces\PromiseInterface;
use Hibla\Promise\Promise;
use Hibla\Sql\Exceptions\ConnectionException;
use Hibla\Sqlite\Handlers\ConnectionQueryHandler;
use Hibla\Sqlite\Handlers\ConnectionResetHandler;
use Hibla\Sqlite\Handlers\ConnectionStreamHandler;
use Hibla\Sqlite\Handlers\JsonIpcFrameHandler;
use Hibla\Sqlite\Interfaces\ConnectionInterface;
use Hibla\Sqlite\Utilities\SystemHelper;
use Hibla\Sqlite\ValueObjects\CommandRequest;
use Hibla\Sqlite\ValueObjects\SqliteConfig;
use Hibla\Stream\PromiseReadableStream;
use Hibla\Stream\PromiseWritableStream;
use Rcalicdan\ProcessKiller\ProcessKiller;
use SplQueue;

use function Hibla\async;
use function Hibla\await;

/**
 * @internal
 */
class AsyncConnection implements ConnectionInterface
{
    /**
     * @var resource|null
     */
    private $processResource = null;

    private ?PromiseWritableStream $stdin = null;

    private ?PromiseReadableStream $stdout = null;

    /**
     * @var SplQueue<CommandRequest>
     */
    private SplQueue $commandQueue;

    private ?CommandRequest $currentCommand = null;

    private bool $closed = false;

    private int $pid = 0;

    private bool $paused = false;

    /**
     * @var Promise<mixed>|null
     */
    private ?Promise $pausePromise = null;

    private readonly SqliteConfig $config;

    private readonly ConnectionQueryHandler $queryHandler;

    private readonly ConnectionStreamHandler $streamHandler;

    private readonly ConnectionResetHandler $resetHandler;

    /**
     * @param SqliteConfig|array<string, mixed>|string $config
     */
    public function __construct(SqliteConfig|array|string $config)
    {
        $this->config = match (true) {
            $config instanceof SqliteConfig => $config,
            \is_array($config) => SqliteConfig::fromArray($config),
            \is_string($config) => SqliteConfig::fromUri($config),
        };

        $this->commandQueue = new SplQueue();
        $this->queryHandler = new ConnectionQueryHandler($this);
        $this->streamHandler = new ConnectionStreamHandler($this);
        $this->resetHandler = new ConnectionResetHandler($this);
    }

    /**
     * {@inheritDoc}
     */
    public function connect(): PromiseInterface
    {
        /** @var Promise<self> $promise */
        $promise = new Promise();

        async(function () use ($promise) {
            try {
                $phpBinary = SystemHelper::getPhpBinary();
                $autoload = SystemHelper::getAutoloadPath();
                $configSerialized = base64_encode(serialize($this->config));
                $workerScript = __DIR__ . '/worker.php';

                $command = \sprintf(
                    '%s %s %s %s',
                    \escapeshellarg($phpBinary),
                    \escapeshellarg($workerScript),
                    \escapeshellarg($autoload),
                    \escapeshellarg($configSerialized)
                );

                $descriptorSpec = PHP_OS_FAMILY === 'Windows'
                    ? [0 => ['socket'], 1 => ['socket'], 2 => ['socket']]
                    : [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];

                $options = PHP_OS_FAMILY === 'Windows' ? ['bypass_shell' => true] : [];

                $pipes = [];
                $process = @\proc_open($command, $descriptorSpec, $pipes, null, null, $options);

                if (! \is_resource($process)) {
                    throw new ConnectionException('Failed to spawn raw SQLite process.');
                }

                $this->processResource = $process;

                \stream_set_blocking($pipes[0], false);
                \stream_set_blocking($pipes[1], false);
                \stream_set_blocking($pipes[2], false);

                $this->stdin = new PromiseWritableStream($pipes[0]);
                $this->stdout = new PromiseReadableStream($pipes[1]);

                $status = \proc_get_status($this->processResource);
                $this->pid = $status['pid'];

                $promise->resolve($this);

                $ipcHandler = new JsonIpcFrameHandler($this, $this->stdout);
                $ipcHandler->start();
            } catch (\Throwable $e) {
                $promise->reject(new ConnectionException('Failed to establish raw SQLite process connection.', 0, $e));
            }
        });

        return $promise;
    }

    /**
     * Safely writes a payload to the worker daemon. Handles crashes natively.
     */
    public function writeIpc(string $payload): void
    {
        $stdin = $this->stdin;
        if ($this->isClosed() || $stdin === null) {
            return;
        }

        async(function () use ($payload, $stdin): void {
            try {
                await($stdin->writeAsync($payload));
            } catch (\Throwable $e) {
                $this->handleCrash(new ConnectionException('Failed to write command to SQLite IPC pipe.', 0, $e));
            }
        });
    }

    /**
     * {@inheritDoc}
     */
    public function query(string $sql): PromiseInterface
    {
        /** @var PromiseInterface<Result> */
        return $this->enqueueCommand(CommandRequest::TYPE_QUERY, $sql);
    }

    /**
     * {@inheritDoc}
     *
     * @return PromiseInterface<SqliteRowStream>
     */
    public function streamQuery(string $sql, int $bufferSize = 100): PromiseInterface
    {
        /** @var PromiseInterface<SqliteRowStream> */
        return $this->enqueueCommand(CommandRequest::TYPE_STREAM_QUERY, $sql, [], $bufferSize);
    }

    /**
     * {@inheritDoc}
     */
    public function prepare(string $sql): PromiseInterface
    {
        if ($this->isClosed()) {
            return Promise::rejected(new ConnectionException('Cannot prepare statement on a closed connection.'));
        }

        $stmt = new PreparedStatement($this, $sql);

        return Promise::resolved($stmt);
    }

    /**
     * {@inheritDoc}
     */
    public function executeStatement(PreparedStatement $stmt, array $params): PromiseInterface
    {
        /** @var PromiseInterface<Result> */
        return $this->enqueueCommand(CommandRequest::TYPE_EXECUTE, $stmt->parsedSql, $params);
    }

    /**
     * {@inheritDoc}
     *
     * @return PromiseInterface<SqliteRowStream>
     */
    public function executeStream(PreparedStatement $stmt, array $params, int $bufferSize = 100): PromiseInterface
    {
        /** @var PromiseInterface<SqliteRowStream> */
        return $this->enqueueCommand(CommandRequest::TYPE_EXECUTE_STREAM, $stmt->parsedSql, $params, $bufferSize);
    }

    /**
     * {@inheritDoc}
     */
    public function pause(): void
    {
        if ($this->paused) {
            return;
        }
        $this->paused = true;
        $this->pausePromise = new Promise();
    }

    /**
     * {@inheritDoc}
     */
    public function resume(): void
    {
        if (! $this->paused) {
            return;
        }
        $this->paused = false;
        if ($this->pausePromise !== null) {
            $this->pausePromise->resolve(null);
            $this->pausePromise = null;
        }
    }

    /**
     * {@inheritDoc}
     */
    public function ping(): PromiseInterface
    {
        if ($this->isClosed()) {
            return Promise::rejected(new ConnectionException('Connection is closed.'));
        }

        /** @var PromiseInterface<bool> */
        return $this->query('SELECT 1')->then(static fn () => true);
    }

    /**
     * {@inheritDoc}
     */
    public function reset(): PromiseInterface
    {
        if ($this->isClosed()) {
            return Promise::rejected(new ConnectionException('Connection is closed.'));
        }

        return $this->enqueueCommand(CommandRequest::TYPE_RESET, '');
    }

    /**
     * {@inheritDoc}
     */
    public function close(bool $killProcess = true): void
    {
        if ($this->closed) {
            return;
        }

        $this->closed = true;

        if ($this->stdin !== null) {
            $this->stdin->close();
            $this->stdin = null;
        }
        if ($this->stdout !== null) {
            $this->stdout->close();
            $this->stdout = null;
        }

        if ($this->pausePromise !== null) {
            $this->pausePromise->resolve(null);
            $this->pausePromise = null;
        }

        if ($killProcess && $this->pid > 0) {
            ProcessKiller::killTreesAsync([$this->pid]);
        }

        if (\is_resource($this->processResource)) {
            @\proc_close($this->processResource);
            $this->processResource = null;
        }

        $exception = new ConnectionException('Connection has been closed.');

        if ($this->currentCommand !== null) {
            if ($this->currentCommand->streamContext !== null) {
                $this->currentCommand->streamContext->error($exception);
            }
            if (! $this->currentCommand->promise->isSettled()) {
                $this->currentCommand->promise->reject($exception);
            }
            $this->currentCommand = null;
        }

        $this->rejectQueue($exception);
    }

    /**
     * {@inheritDoc}
     */
    public function isClosed(): bool
    {
        return $this->closed;
    }

    /**
     * @param array<int|string, mixed> $params
     *
     * @return PromiseInterface<mixed>
     */
    private function enqueueCommand(string $type, string $sql, array $params = [], ?int $bufferSize = null): PromiseInterface
    {
        if ($this->isClosed()) {
            return Promise::rejected(new ConnectionException('Cannot execute command on a closed connection.'));
        }

        $promise = new Promise();

        $request = new CommandRequest($type, $promise, $sql, $params);

        $isStream = ($type === CommandRequest::TYPE_STREAM_QUERY || $type === CommandRequest::TYPE_EXECUTE_STREAM);
        if ($isStream && $bufferSize !== null) {
            $stream = new SqliteRowStream($bufferSize, $promise);

            $stream->backpressureHandler = function (bool $shouldPause): void {
                $shouldPause ? $this->pause() : $this->resume();
            };

            $stream->setOnCancel(function () use ($request): void {
                $this->handleCommandCancellation($request);
            });

            $request->streamContext = $stream;
            $promise->resolve($stream);
        }

        $promise->onCancel(function () use ($request): void {
            $this->handleCommandCancellation($request);
        });

        $this->commandQueue->enqueue($request);
        $this->processNextCommand();

        return $promise;
    }

    private function handleCommandCancellation(CommandRequest $request): void
    {
        if ($this->removeFromQueue($request)) {
            $request->promise->reject(new \Hibla\Promise\Exceptions\CancelledException('Command cancelled in queue.'));

            return;
        }

        if ($this->currentCommand === $request && $this->config->killWorkerOnCancel) {
            $this->close(true);
        }
    }

    private function processNextCommand(): void
    {
        if ($this->currentCommand !== null) {
            return;
        }

        if ($this->commandQueue->isEmpty()) {
            return;
        }

        $command = $this->commandQueue->dequeue();
        if (! $command instanceof CommandRequest) {
            return;
        }

        $this->currentCommand = $command;

        $cmdType = $command->type;
        $isStream = ($cmdType === CommandRequest::TYPE_STREAM_QUERY || $cmdType === CommandRequest::TYPE_EXECUTE_STREAM);

        if ($isStream) {
            $this->streamHandler->start($command);
        } elseif ($cmdType === CommandRequest::TYPE_RESET) {
            $this->resetHandler->start($command);
        } else {
            $this->queryHandler->start($command);
        }
    }

    /**
     * @internal
     *
     * @param array<string, mixed> $response
     */
    public function handleIpcFrame(array $response): void
    {
        if ($this->currentCommand !== null && isset($response['id']) && $response['id'] === $this->currentCommand->id) {
            $cmdType = $this->currentCommand->type;

            if ($cmdType === CommandRequest::TYPE_RESET) {
                $isFinished = $this->resetHandler->handleResponse($response, $this->currentCommand);
            } else {
                $isStream = ($cmdType === CommandRequest::TYPE_STREAM_QUERY || $cmdType === CommandRequest::TYPE_EXECUTE_STREAM);
                $isFinished = $isStream
                    ? $this->streamHandler->handleResponse($response, $this->currentCommand)
                    : $this->queryHandler->handleResponse($response, $this->currentCommand);
            }

            if ($isFinished) {
                $this->currentCommand = null;
                $this->processNextCommand();
            }
        }
    }

    /**
     * @internal
     */
    public function awaitPauseCheck(): void
    {
        if ($this->paused && $this->pausePromise !== null) {
            await($this->pausePromise);
        }
    }

    public function handleCrash(\Throwable $e): void
    {
        if ($this->closed) {
            return;
        }

        $this->closed = true;

        if ($this->stdin !== null) {
            $this->stdin->close();
            $this->stdin = null;
        }
        if ($this->stdout !== null) {
            $this->stdout->close();
            $this->stdout = null;
        }

        if ($this->pausePromise !== null) {
            $this->pausePromise->resolve(null);
            $this->pausePromise = null;
        }

        if ($this->pid > 0) {
            ProcessKiller::killTreesAsync([$this->pid]);
        }

        if (\is_resource($this->processResource)) {
            @\proc_close($this->processResource);
            $this->processResource = null;
        }

        if ($this->currentCommand !== null) {
            if ($this->currentCommand->streamContext !== null) {
                $this->currentCommand->streamContext->error($e);
            }
            $this->currentCommand->promise->reject($e);
            $this->currentCommand = null;
        }

        $this->rejectQueue($e);
    }

    private function rejectQueue(\Throwable $e): void
    {
        while (! $this->commandQueue->isEmpty()) {
            $cmd = $this->commandQueue->dequeue();
            if ($cmd instanceof CommandRequest) {
                $cmd->promise->reject($e);
            }
        }
    }

    private function removeFromQueue(CommandRequest $request): bool
    {
        $found = false;

        /** @var SplQueue<CommandRequest> $temp */
        $temp = new SplQueue();

        while (! $this->commandQueue->isEmpty()) {
            $cmd = $this->commandQueue->dequeue();
            if ($cmd === $request) {
                $found = true;
            } else {
                $temp->enqueue($cmd);
            }
        }

        while (! $temp->isEmpty()) {
            $cmd = $temp->dequeue();
            if ($cmd instanceof CommandRequest) {
                $this->commandQueue->enqueue($cmd);
            }
        }

        return $found;
    }

    public function __destruct()
    {
        $this->close(false);
    }
}
