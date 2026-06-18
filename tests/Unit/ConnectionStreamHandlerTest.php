<?php

declare(strict_types=1);

use Hibla\Promise\Promise;
use Hibla\Sql\Exceptions\QueryException;
use Hibla\Sqlite\Handlers\ConnectionStreamHandler;
use Hibla\Sqlite\Internals\AsyncConnection;
use Hibla\Sqlite\Internals\SqliteRowStream;
use Hibla\Sqlite\ValueObjects\CommandRequest;

use function Hibla\await;

afterEach(function () {
    Mockery::close();
});

describe('ConnectionStreamHandler', function (): void {

    it('formats and writes the correct JSON stream frame to the worker', function (): void {
        $promise = new Promise();
        $stream = new SqliteRowStream(10, $promise);
        $request = new CommandRequest(
            CommandRequest::TYPE_STREAM_QUERY,
            $promise,
            'SELECT * FROM items',
            []
        );
        $request->streamContext = $stream;

        $connection = Mockery::mock(AsyncConnection::class);
        $connection->shouldReceive('writeIpc')
            ->once()
            ->with(Mockery::on(function (string $payload) use ($request) {
                $frame = json_decode($payload, true);
                
                return is_array($frame)
                    && $frame['id'] === $request->id
                    && $frame['cmd'] === 'stream'
                    && $frame['sql'] === 'SELECT * FROM items';
            }));

        $handler = new ConnectionStreamHandler($connection);
        $handler->start($request);
    });

    it('pushes rows directly into the stream context on ROW response', function (): void {
        $connection = Mockery::mock(AsyncConnection::class);
        $handler = new ConnectionStreamHandler($connection);

        $promise = new Promise();
        $stream = new SqliteRowStream(10, $promise);
        $request = new CommandRequest(CommandRequest::TYPE_STREAM_QUERY, $promise, 'SELECT 1');
        $request->streamContext = $stream;

        $response = [
            'id' => $request->id,
            'status' => 'ROW',
            'row' => ['name' => 'Bob'],
        ];

        $isFinished = $handler->handleResponse($response, $request);

        expect($isFinished)->toBeFalse();

        // Read the row from the generator synchronously
        $generator = $stream->getIterator();
        $row = $generator->current();

        expect($row)->toBeArray()
            ->and($row['name'])->toBe('Bob')
        ;
    });

    it('completes the stream and resolves the promise on COMPLETED response', function (): void {
        $connection = Mockery::mock(AsyncConnection::class);
        $handler = new ConnectionStreamHandler($connection);

        $promise = new Promise();
        $stream = new SqliteRowStream(10, $promise);
        $request = new CommandRequest(CommandRequest::TYPE_STREAM_QUERY, $promise, 'SELECT 1');
        $request->streamContext = $stream;

        $response = [
            'id' => $request->id,
            'status' => 'COMPLETED',
        ];

        $isFinished = $handler->handleResponse($response, $request);

        expect($isFinished)->toBeTrue()
            ->and($promise->isSettled())->toBeTrue()
        ;

        $resolvedStream = await($promise);
        expect($resolvedStream)->toBe($stream);
    });

  it('forwards error to the stream and rejects the promise on ERROR response', function (): void {
        $connection = Mockery::mock(AsyncConnection::class);
        $handler = new ConnectionStreamHandler($connection);

        $promise = new Promise();
        $stream = new SqliteRowStream(10, $promise);

        $stream->onClose()->catch(function (\Throwable $e): void {
            // Suppress unhandled closePromise rejection in unit test
        });

        $request = new CommandRequest(CommandRequest::TYPE_STREAM_QUERY, $promise, 'SELECT 1');
        $request->streamContext = $stream;

        $response = [
            'id' => $request->id,
            'status' => 'ERROR',
            'class' => SQLite3Exception::class,
            'message' => 'database disk image is malformed',
            'code' => 11,
        ];

        $isFinished = $handler->handleResponse($response, $request);

        expect($isFinished)->toBeTrue()
            ->and($promise->isSettled())->toBeTrue()
        ;

        expect(fn () => await($promise))->toThrow(QueryException::class, 'database disk image is malformed');
        expect(fn () => iterator_to_array($stream))->toThrow(QueryException::class, 'database disk image is malformed');
    });
});