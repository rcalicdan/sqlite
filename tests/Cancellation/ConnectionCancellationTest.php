<?php

declare(strict_types=1);

use Hibla\EventLoop\Loop;
use Hibla\Promise\Exceptions\CancelledException;
use Hibla\Sql\Exceptions\ConnectionException;
use Hibla\Sql\Exceptions\QueryException;
use Hibla\Sqlite\Internals\AsyncConnection;
use Hibla\Sqlite\Internals\ConnectionFactory;

use function Hibla\await;
use function Hibla\delay;

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

        try {
            $slowPromise = $conn->query(slowCteQuery());

            Loop::addTimer(0.1, function () use ($slowPromise) {
                $slowPromise->cancel();
            });

            expect(fn () => await($slowPromise))->toThrow(CancelledException::class);
            expect($conn->isClosed())->toBeTrue();
        } finally {
            $conn->close();
            await(delay(0.1));
        }
    });

    it('does NOT kill the worker process when an active query is cancelled with kill_worker_on_cancel disabled (default)', function () {
        $config = dbConfig([
            'force_sync' => false,
            'kill_worker_on_cancel' => false,
        ]);

        /** @var AsyncConnection $conn */
        $conn = await(ConnectionFactory::create($config));

        try {
            $slowPromise = $conn->query(slowCteQuery());

            Loop::addTimer(0.1, function () use ($slowPromise) {
                $slowPromise->cancel();
            });

            expect(fn () => await($slowPromise))->toThrow(CancelledException::class);
            expect($conn->isClosed())->toBeFalse();

            await(delay(1.5));
        } finally {
            $conn->close();
        }
    });

    it('handles multiple sequential cancellations on the same connection cleanly', function () {
        $config = dbConfig([
            'force_sync' => false,
            'kill_worker_on_cancel' => false, 
        ]);

        /** @var AsyncConnection $conn */
        $conn = await(ConnectionFactory::create($config));

        try {
            $p1 = $conn->query(slowCteQuery());
            Loop::addTimer(0.1, fn () => $p1->cancel());
            expect(fn () => await($p1))->toThrow(CancelledException::class);
            await(delay(1.5));

            $p2 = $conn->query(slowCteQuery());
            Loop::addTimer(0.1, fn () => $p2->cancel());
            expect(fn () => await($p2))->toThrow(CancelledException::class);
            await(delay(1.5)); 

            $result = await($conn->query('SELECT 123 AS val'));
            expect($result->fetchOne()['val'])->toBe(123);
        } finally {
            $conn->close();
        }
    });

    it('rejects all queued queries with ConnectionException when a running query is cancelled with kill_worker_on_cancel enabled', function () {
        $config = dbConfig([
            'force_sync' => false,
            'kill_worker_on_cancel' => true, 
        ]);

        /** @var AsyncConnection $conn */
        $conn = await(ConnectionFactory::create($config));

        try {
            $slowPromise = $conn->query(slowCteQuery());
            $queuedPromise1 = $conn->query('SELECT 1');
            $queuedPromise2 = $conn->query('SELECT 2');

            Loop::addTimer(0.1, fn () => $slowPromise->cancel());

            expect(fn () => await($slowPromise))->toThrow(CancelledException::class);

            expect(fn () => await($queuedPromise1))->toThrow(ConnectionException::class);
            expect(fn () => await($queuedPromise2))->toThrow(ConnectionException::class);
            
            expect($conn->isClosed())->toBeTrue();
        } finally {
            $conn->close();
            await(delay(0.1));
        }
    });

    it('ignores cancel() on already-resolved or already-failed queries', function () {
        $conn = sqliteConn(['force_sync' => false]);

        try {
            $p1 = $conn->query('SELECT 100 AS val');
            $result1 = await($p1);
            expect($result1->fetchOne()['val'])->toBe(100);

            $p1->cancel();
            expect($p1->isCancelled())->toBeFalse();

            $p2 = $conn->query('SELECT INVALID SQL');
            try {
                await($p2);
            } catch (QueryException $e) {
                // caught
            }

            $p2->cancel();
            expect($p2->isCancelled())->toBeFalse();
        } finally {
            $conn->close();
        }
    });

    it('cancels a queued ping() command safely', function () {
        $conn = sqliteConn(['force_sync' => false]);

        try {
            $slowPromise = $conn->query(slowCteQuery());
        
            $pingPromise = $conn->ping();
            $pingPromise->cancel();

            await($slowPromise);

            expect($pingPromise->isCancelled())->toBeTrue();
            expect(fn () => await($pingPromise))->toThrow(CancelledException::class);

            $ok = await($conn->ping());
            expect($ok)->toBeTrue();
        } finally {
            $conn->close();
        }
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