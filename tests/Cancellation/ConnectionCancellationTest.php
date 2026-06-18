<?php

declare(strict_types=1);

use Hibla\Promise\Exceptions\CancelledException;
use Hibla\Sql\Exceptions\ConnectionException;
use Hibla\Sqlite\Internals\AsyncConnection;
use Hibla\Sqlite\Internals\ConnectionFactory;

use function Hibla\await;
use function Hibla\delay;

function slowCteQuery(): string
{
    return "
        WITH RECURSIVE cnt(x) AS (
            SELECT 1 UNION ALL SELECT x+1 FROM cnt WHERE x < 150000
        ) SELECT count(x) FROM cnt;
    ";
}

describe('AsyncConnection - Cancellation', function () {

    it('cancels a queued query before it starts and leaves the connection healthy', function () {
        $conn = sqliteConn(['force_sync' => false]);

        try {
            $slowPromise = $conn->query(slowCteQuery());

            $queuedPromise = $conn->query('SELECT 42 AS val');

            $queuedPromise->cancel();

            await($slowPromise);

            expect($queuedPromise->isCancelled())->toBeTrue();
            expect(fn () => await($queuedPromise))->toThrow(CancelledException::class);

            $result = await($conn->query('SELECT 99 AS ok'));
            expect($result->fetchOne()['ok'])->toBe(99);
        } finally {
            $conn->close();
        }
    });

    it('tears down the connection and kills the worker process when an active query is cancelled with kill_worker_on_cancel enabled', function () {
        $config = dbConfig([
            'force_sync' => false,
            'kill_worker_on_cancel' => true,
        ]);

        /** @var AsyncConnection $conn */
        $conn = await(ConnectionFactory::create($config));

        $slowPromise = $conn->query(slowCteQuery());

        await(delay(0.05));
        $slowPromise->cancel();

        $thrown = false;
        try {
            await($slowPromise);
        } catch (ConnectionException|CancelledException $e) {
            $thrown = true;
        }

        expect($thrown)->toBeTrue()
            ->and($conn->isClosed())->toBeTrue()
        ;

        $conn->close();
    });

    it('does NOT kill the worker process when an active query is cancelled with kill_worker_on_cancel disabled (default)', function () {
        $config = dbConfig([
            'force_sync' => false,
            'kill_worker_on_cancel' => false,
        ]);

        /** @var AsyncConnection $conn */
        $conn = await(ConnectionFactory::create($config));

        $slowPromise = $conn->query(slowCteQuery());

        await(delay(0.05));
        $slowPromise->cancel();

        expect($slowPromise->isCancelled())->toBeTrue();

        expect($conn->isClosed())->toBeFalse();

        await(delay(0.5));

        $conn->close();
    });
});

describe('SyncConnection - Cancellation', function (): void {

    it('confirms SyncConnection is unaffected by cancellation tests due to its blocking nature', function (): void {
        $conn = sqliteConn(['force_sync' => true]);

        try {
            $promise = $conn->query('SELECT 1 AS ok');

            $promise->cancel();

            expect($promise->isCancelled())->toBeFalse();

            $result = await($promise);
            expect($result->fetchOne()['ok'])->toBe(1);
        } finally {
            $conn->close();
        }
    });
});