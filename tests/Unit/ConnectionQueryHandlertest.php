<?php

declare(strict_types=1);

use Hibla\Promise\Promise;
use Hibla\Sql\Exceptions\QueryException;
use Hibla\Sqlite\Handlers\ConnectionQueryHandler;
use Hibla\Sqlite\Internals\AsyncConnection;
use Hibla\Sqlite\Internals\Result;
use Hibla\Sqlite\ValueObjects\CommandRequest;

use function Hibla\await;

afterEach(function () {
    Mockery::close();
});

describe('ConnectionQueryHandler', function (): void {

    it('formats and writes the correct JSON query frame to the worker', function (): void {
        $promise = new Promise();
        $request = new CommandRequest(
            CommandRequest::TYPE_QUERY,
            $promise,
            'SELECT * FROM users WHERE id = :id',
            ['id' => 42]
        );

        $connection = Mockery::mock(AsyncConnection::class);
        $connection->shouldReceive('writeIpc')
            ->once()
            ->with(Mockery::on(function (string $payload) use ($request) {
                $frame = json_decode($payload, true);
                
                return is_array($frame)
                    && $frame['id'] === $request->id
                    && $frame['cmd'] === 'query'
                    && $frame['sql'] === 'SELECT * FROM users WHERE id = :id'
                    && $frame['params'] === ['id' => 42];
            }));

        $handler = new ConnectionQueryHandler($connection);
        $handler->start($request);
    });

    it('resolves the promise with a Result object on COMPLETED response', function (): void {
        $connection = Mockery::mock(AsyncConnection::class);
        $handler = new ConnectionQueryHandler($connection);

        $promise = new Promise();
        $request = new CommandRequest(CommandRequest::TYPE_QUERY, $promise, 'SELECT 1');

        $response = [
            'id' => $request->id,
            'status' => 'COMPLETED',
            'result' => [
                'rows' => [['val' => 100]],
                'affectedRows' => 1,
                'lastInsertId' => 5,
            ],
        ];

        $isFinished = $handler->handleResponse($response, $request);

        expect($isFinished)->toBeTrue()
            ->and($promise->isSettled())->toBeTrue()
        ;

        /** @var Result $result */
        $result = await($promise);

        expect($result)->toBeInstanceOf(Result::class)
            ->and($result->rowCount)->toBe(1)
            ->and($result->fetchOne()['val'])->toBe(100)
            ->and($result->affectedRows)->toBe(1)
            ->and($result->lastInsertId)->toBe(5)
        ;
    });

    it('rejects the promise with a mapped exception on ERROR response', function (): void {
        $connection = Mockery::mock(AsyncConnection::class);
        $handler = new ConnectionQueryHandler($connection);

        $promise = new Promise();
        $request = new CommandRequest(CommandRequest::TYPE_QUERY, $promise, 'SELECT 1');

        $response = [
            'id' => $request->id,
            'status' => 'ERROR',
            'class' => SQLite3Exception::class,
            'message' => 'syntax error',
            'code' => 1, 
        ];

        $isFinished = $handler->handleResponse($response, $request);

        expect($isFinished)->toBeTrue()
            ->and($promise->isSettled())->toBeTrue()
        ;

        expect(fn () => await($promise))->toThrow(QueryException::class, 'syntax error');
    });
});