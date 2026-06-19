<?php

declare(strict_types=1);

use Hibla\Promise\Promise;
use Hibla\Sql\Exceptions\ConnectionException;
use Hibla\Sqlite\Handlers\JsonIpcFrameHandler;
use Hibla\Sqlite\Internals\AsyncConnection;
use Hibla\Stream\PromiseReadableStream;

use function Hibla\await;
use function Hibla\delay;

afterEach(function () {
    Mockery::close();
});

describe('JsonIpcFrameHandler', function () {

    it('successfully parses a complete JSON line and forwards it to the connection', function () {
        $connection = Mockery::mock(AsyncConnection::class);
        $stream = Mockery::mock(PromiseReadableStream::class);

        $connection->shouldReceive('handleIpcFrame')
            ->once()
            ->with(['id' => '123', 'status' => 'COMPLETED'])
        ;

        $stream->shouldReceive('readLineAsync')
            ->andReturn(
                Promise::resolved('{"id":"123","status":"COMPLETED"}' . "\n"),
                Promise::resolved(null)
            )
        ;

        $connection->shouldReceive('awaitPauseCheck')->once();

        $connection->shouldReceive('handleCrash')
            ->once()
            ->with(Mockery::type(ConnectionException::class))
        ;

        $handler = new JsonIpcFrameHandler($connection, $stream);
        $handler->start();

        await(delay(0.05));
    });

    it('assembles a JSON frame split across multiple read chunks', function () {
        $connection = Mockery::mock(AsyncConnection::class);
        $stream = Mockery::mock(PromiseReadableStream::class);

        $connection->shouldReceive('handleIpcFrame')
            ->once()
            ->with(['id' => 'abc', 'data' => 'chunked_message'])
        ;

        $stream->shouldReceive('readLineAsync')
            ->andReturn(
                Promise::resolved('{"id":"a'),
                Promise::resolved('bc","da'),
                Promise::resolved('ta":"chunked_message"}' . "\n"),
                Promise::resolved(null)
            )
        ;

        $connection->shouldReceive('awaitPauseCheck')->once();

        $connection->shouldReceive('handleCrash')
            ->once()
            ->with(Mockery::type(ConnectionException::class))
        ;

        $handler = new JsonIpcFrameHandler($connection, $stream);
        $handler->start();

        await(delay(0.05));
    });

    it('ignores empty lines and pure whitespace', function () {
        $connection = Mockery::mock(AsyncConnection::class);
        $stream = Mockery::mock(PromiseReadableStream::class);

        $connection->shouldReceive('handleIpcFrame')
            ->once()
            ->with(['id' => 'valid'])
        ;

        $stream->shouldReceive('readLineAsync')
            ->andReturn(
                Promise::resolved("   \n"), // Whitespace
                Promise::resolved("\n"),   // Empty
                Promise::resolved('{"id":"valid"}' . "\n"), // Valid
                Promise::resolved(null)
            )
        ;

        $connection->shouldReceive('awaitPauseCheck')->once();

        $connection->shouldReceive('handleCrash')
            ->once()
            ->with(Mockery::type(ConnectionException::class))
        ;

        $handler = new JsonIpcFrameHandler($connection, $stream);
        $handler->start();

        \Hibla\await(delay(0.05));
    });

    it('discards malformed non-JSON garbage strings but continues parsing subsequent valid frames', function () {
        $connection = Mockery::mock(AsyncConnection::class);
        $stream = Mockery::mock(PromiseReadableStream::class);

        $connection->shouldReceive('handleIpcFrame')
            ->once()
            ->with(['id' => 'good_frame'])
        ;

        $stream->shouldReceive('readLineAsync')
            ->andReturn(
                Promise::resolved("Fatal PHP Error: Out of memory\n"), // Non-JSON garbage
                Promise::resolved('{"id":"good_frame"}' . "\n"),       // Recovers cleanly
                Promise::resolved(null)
            )
        ;

        $connection->shouldReceive('awaitPauseCheck')->once();

        $connection->shouldReceive('handleCrash')
            ->once()
            ->with(Mockery::type(ConnectionException::class))
        ;

        $handler = new JsonIpcFrameHandler($connection, $stream);
        $handler->start();

        \Hibla\await(delay(0.05));
    });

    it('discards a JSON string that starts correctly but is structurally malformed at the newline boundary', function () {
        $connection = Mockery::mock(AsyncConnection::class);
        $stream = Mockery::mock(PromiseReadableStream::class);

        $connection->shouldReceive('handleIpcFrame')
            ->once()
            ->with(['id' => 'next_is_fine'])
        ;

        $stream->shouldReceive('readLineAsync')
            ->andReturn(
                Promise::resolved('{"id":"broken", "data": "missing_quote}' . "\n"), // Bad JSON + Newline = Discard
                Promise::resolved('{"id":"next_is_fine"}' . "\n"),
                Promise::resolved(null)
            )
        ;

        $connection->shouldReceive('awaitPauseCheck')->once();

        $connection->shouldReceive('handleCrash')
            ->once()
            ->with(Mockery::type(ConnectionException::class))
        ;

        $handler = new JsonIpcFrameHandler($connection, $stream);
        $handler->start();

        \Hibla\await(delay(0.05));
    });

    it('triggers handleCrash on the connection when readLineAsync throws an exception', function () {
        $connection = Mockery::mock(AsyncConnection::class);
        $stream = Mockery::mock(PromiseReadableStream::class);

        $connection->shouldNotReceive('handleIpcFrame');
        $connection->shouldNotReceive('awaitPauseCheck');

        $stream->shouldReceive('readLineAsync')
            ->andReturnUsing(function () {
                return Promise::rejected(new RuntimeException('Socket disconnected violently'));
            })
        ;

        $connection->shouldReceive('handleCrash')
            ->once()
            ->with(Mockery::on(function ($exception) {
                return $exception instanceof ConnectionException
                    && str_contains($exception->getMessage(), 'SQLite IPC pipe read loop failed');
            }))
        ;

        $connection->shouldReceive('handleCrash')
            ->once()
            ->with(Mockery::on(function ($exception) {
                return $exception instanceof ConnectionException
                    && str_contains($exception->getMessage(), 'SQLite process stream closed');
            }))
        ;

        $handler = new JsonIpcFrameHandler($connection, $stream);
        $handler->start();

        await(delay(0.05));
    });
});
