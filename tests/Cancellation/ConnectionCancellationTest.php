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
        WITH RECURSIVE
          t1(x) AS (SELECT 1 UNION ALL SELECT x+1 FROM t1 LIMIT 100),
          t2(x) AS (SELECT 1 UNION ALL SELECT x+1 FROM t2 LIMIT 100),
          t3(x) AS (SELECT 1 UNION ALL SELECT x+1 FROM t3 LIMIT 100)
        SELECT count(*) FROM t1 a CROSS JOIN t2 b CROSS JOIN t3 c;
    ";
}

describe('AsyncConnection - Cancellation', function () {

    it('cancels a queued query before it starts and leaves the connection healthy', function () {
        fwrite(STDERR, "[TRACE] Starting Test 1 (Queued Cancellation)\n");
        $conn = sqliteConn(['force_sync' => false]);

        try {
            fwrite(STDERR, "[TRACE] Test 1: Sending slow query...\n");
            $slowPromise = $conn->query(slowCteQuery());

            fwrite(STDERR, "[TRACE] Test 1: Enqueuing queued query...\n");
            $queuedPromise = $conn->query('SELECT 42 AS val');

            fwrite(STDERR, "[TRACE] Test 1: Cancelling queued query...\n");
            $queuedPromise->cancel();

            fwrite(STDERR, "[TRACE] Test 1: Awaiting slow query...\n");
            await($slowPromise);
            fwrite(STDERR, "[TRACE] Test 1: Slow query finished.\n");

            expect($queuedPromise->isCancelled())->toBeTrue();
            expect(fn () => await($queuedPromise))->toThrow(CancelledException::class);

            fwrite(STDERR, "[TRACE] Test 1: Sending cleanup verification query...\n");
            $result = await($conn->query('SELECT 99 AS ok'));
            expect($result->fetchOne()['ok'])->toBe(99);
            fwrite(STDERR, "[TRACE] Test 1: Cleanup query finished.\n");
        } finally {
            fwrite(STDERR, "[TRACE] Test 1: Closing connection...\n");
            $conn->close();
            fwrite(STDERR, "[TRACE] Test 1: Finished.\n");
        }
    });

    it('tears down the connection and kills the worker process when an active query is cancelled with kill_worker_on_cancel enabled', function () {
        fwrite(STDERR, "[TRACE] Starting Test 2 (Active Cancel, killWorker=true)\n");
        $config = dbConfig([
            'force_sync' => false,
            'kill_worker_on_cancel' => true,
        ]);

        /** @var AsyncConnection $conn */
        $conn = await(ConnectionFactory::create($config));

        fwrite(STDERR, "[TRACE] Test 2: Sending slow query...\n");
        $slowPromise = $conn->query(slowCteQuery());

        fwrite(STDERR, "[TRACE] Test 2: Waiting 50ms before cancel...\n");
        await(delay(0.05));
        
        fwrite(STDERR, "[TRACE] Test 2: Cancelling active query...\n");
        $slowPromise->cancel();

        fwrite(STDERR, "[TRACE] Test 2: Awaiting cancelled query promise...\n");
        $thrown = false;
        try {
            await($slowPromise);
        } catch (ConnectionException|CancelledException $e) {
            $thrown = true;
            fwrite(STDERR, "[TRACE] Test 2: Caught expected rejection: " . get_class($e) . " - " . $e->getMessage() . "\n");
        }

        expect($thrown)->toBeTrue()
            ->and($conn->isClosed())->toBeTrue()
        ;

        fwrite(STDERR, "[TRACE] Test 2: Closing connection...\n");
        $conn->close();
        fwrite(STDERR, "[TRACE] Test 2: Finished.\n");
    });

    it('does NOT kill the worker process when an active query is cancelled with kill_worker_on_cancel disabled (default)', function () {
        fwrite(STDERR, "[TRACE] Starting Test 3 (Active Cancel, killWorker=false)\n");
        $config = dbConfig([
            'force_sync' => false,
            'kill_worker_on_cancel' => false,
        ]);

        /** @var AsyncConnection $conn */
        $conn = await(ConnectionFactory::create($config));

        fwrite(STDERR, "[TRACE] Test 3: Sending slow query...\n");
        $slowPromise = $conn->query(slowCteQuery());

        fwrite(STDERR, "[TRACE] Test 3: Waiting 50ms before cancel...\n");
        await(delay(0.05));
        
        fwrite(STDERR, "[TRACE] Test 3: Cancelling active query...\n");
        $slowPromise->cancel();

        expect($slowPromise->isCancelled())->toBeTrue();
        expect($conn->isClosed())->toBeFalse();

        fwrite(STDERR, "[TRACE] Test 3: Waiting 0.5s for background query to settle...\n");
        await(delay(0.5));
        fwrite(STDERR, "[TRACE] Test 3: Delay finished.\n");

        fwrite(STDERR, "[TRACE] Test 3: Closing connection...\n");
        $conn->close();
        fwrite(STDERR, "[TRACE] Test 3: Finished.\n");
    });
});

describe('SyncConnection - Cancellation', function (): void {

    it('confirms SyncConnection is unaffected by cancellation tests due to its blocking nature', function (): void {
        fwrite(STDERR, "[TRACE] Starting Test 4 (Sync Connection Cancellation)\n");
        $conn = sqliteConn(['force_sync' => true]);

        try {
            $promise = $conn->query('SELECT 1 AS ok');

            $promise->cancel();

            expect($promise->isCancelled())->toBeFalse();

            $result = await($promise);
            expect($result->fetchOne()['ok'])->toBe(1);
        } finally {
            $conn->close();
            fwrite(STDERR, "[TRACE] Test 4: Finished.\n");
        }
    });
});