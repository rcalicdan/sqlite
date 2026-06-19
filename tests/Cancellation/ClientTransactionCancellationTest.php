<?php

declare(strict_types=1);

use Hibla\EventLoop\Loop;
use Hibla\Promise\Exceptions\CancelledException;
use Hibla\Sql\Exceptions\TransactionException;
use Hibla\Sql\RowStream as RowStreamInterface;
use Hibla\Sql\Transaction as TransactionInterface;

use function Hibla\await;
use function Hibla\delay;

describe('Transaction Cancellation - Manual Transactions', function () {

    it('cancels query() inside a transaction, taints the transaction, and forces rollback', function () {
        $client = makeClient(['maxConnections' => 1]);

        try {
            await($client->query('CREATE TABLE txn_test (v TEXT)'));

            $tx = await($client->beginTransaction());
            await($tx->execute("INSERT INTO txn_test VALUES ('before_cancel')"));

            $promise = $tx->query(slowCteQuery());

            Loop::addTimer(0.1, function () use ($promise) {
                $promise->cancel();
            });

            expect(fn () => await($promise))->toThrow(CancelledException::class);

            expect(fn () => await($tx->query('SELECT 1')))
                ->toThrow(TransactionException::class, 'Transaction aborted due to a previous query error')
            ;

            expect(fn () => await($tx->commit()))
                ->toThrow(TransactionException::class, 'Transaction aborted due to a previous error')
            ;

            await($tx->rollback());

            $count = await($client->fetchValue('SELECT count(*) FROM txn_test'));
            expect((int)$count)->toBe(0);
        } finally {
            $client->close();
        }
    });

    it('cancels a prepared statement execute() inside a transaction and taints the transaction', function () {
        $client = makeClient(['maxConnections' => 1]);

        try {
            $tx = await($client->beginTransaction());

            $stmt = await($tx->prepare(slowCteQuery()));
            $promise = $stmt->execute();

            Loop::addTimer(0.1, function () use ($promise) {
                $promise->cancel();
            });

            expect(fn () => await($promise))->toThrow(CancelledException::class);

            expect(fn () => await($tx->query('SELECT 1')))
                ->toThrow(TransactionException::class, 'Transaction aborted due to a previous query error')
            ;

            await($stmt->close());
            await($tx->rollback());
        } finally {
            $client->close();
        }
    });

    it('cancels a transaction stream() mid-iteration and taints the transaction', function () {
        $client = makeClient(['maxConnections' => 1]);

        try {
            $tx = await($client->beginTransaction());

            $stream = await($tx->stream(streamCancelQuery(), [], 10));
            expect($stream)->toBeInstanceOf(RowStreamInterface::class);

            $count = 0;
            $threw = false;

            try {
                foreach ($stream as $row) {
                    $count++;
                    if ($count === 3) {
                        $stream->cancel();
                    }
                }
            } catch (CancelledException $e) {
                $threw = true;
            }

            expect($count)->toBe(3)
                ->and($threw)->toBeTrue()
            ;

            expect(fn () => await($tx->query('SELECT 1')))
                ->toThrow(TransactionException::class, 'Transaction aborted due to a previous query error')
            ;

            await($tx->rollback());
        } finally {
            $client->close();
        }
    });
});

describe('Transaction Cancellation - Auto-Managed wrapper', function () {

    it('automatically rolls back and releases connection when the outer transaction() wrapper promise is cancelled', function () {
        $client = makeClient([
            'maxConnections' => 1,
            'kill_worker_on_cancel' => true,
        ]);

        try {
            await($client->query('CREATE TABLE txn_test (v TEXT)'));

            $promise = $client->transaction(function (TransactionInterface $tx) {
                await($tx->execute("INSERT INTO txn_test VALUES ('will_be_rolled_back')"));
                await($tx->query(slowCteQuery()));
            });

            Loop::addTimer(0.1, function () use ($promise) {
                $promise->cancel();
            });

            expect(fn () => await($promise))->toThrow(CancelledException::class);

            await(delay(0.1));

            expect($client->stats['total_connections'])->toBe(0);

            $count = await($client->fetchValue('SELECT count(*) FROM txn_test'));
            expect((int)$count)->toBe(0);
        } finally {
            $client->close();
        }
    });
});

describe('Transaction Cancellation - Edge Cases', function () {

    it('taints a manual transaction if a queued query is cancelled before it even executes', function () {
        $client = makeClient(['maxConnections' => 1]);

        try {
            $tx = await($client->beginTransaction());

            // Send a slow query to occupy the connection
            $slow = $tx->query(slowCteQuery());

            // Queue a fast query directly behind it
            $queued = $tx->query('SELECT 1');

            // Cancel the queued one before it ever reaches the worker
            $queued->cancel();

            try {
                await($slow);
            } catch (Throwable $e) {
            }

            expect($queued->isCancelled())->toBeTrue();

            // The transaction MUST still be tainted, because a query belonging to it failed/cancelled
            expect(fn () => await($tx->commit()))
                ->toThrow(TransactionException::class, 'Transaction aborted due to a previous error')
            ;

            await($tx->rollback());
        } finally {
            $client->close();
        }
    });

    it('auto-rolls back when an INNER query is cancelled directly inside the transaction() wrapper', function () {
        $client = makeClient(['maxConnections' => 1, 'kill_worker_on_cancel' => true]);

        try {
            await($client->query('CREATE TABLE txn_inner_test (v TEXT)'));

            $innerQueryPromise = null;

            $wrapperPromise = $client->transaction(function (TransactionInterface $tx) use (&$innerQueryPromise) {
                await($tx->execute("INSERT INTO txn_inner_test VALUES ('will_be_rolled_back')"));

                $innerQueryPromise = $tx->query(slowCteQuery());

                return await($innerQueryPromise);
            });

            Loop::addTimer(0.1, function () use (&$innerQueryPromise) {
                $innerQueryPromise?->cancel();
            });

            expect(fn () => await($wrapperPromise))->toThrow(CancelledException::class);

            $count = await($client->fetchValue('SELECT count(*) FROM txn_inner_test'));
            expect((int)$count)->toBe(0);
        } finally {
            $client->close();
        }
    });

    it('throws TransactionException if the callback swallows an inner CancelledException and tries to return normally', function () {
        // MUST be false here. If true, the connection is instantly destroyed on cancel,
        // and commit() will throw "Connection is closed" instead of "Transaction aborted".
        $client = makeClient(['maxConnections' => 1, 'kill_worker_on_cancel' => false]);

        try {
            $wrapperPromise = $client->transaction(function (TransactionInterface $tx) {
                $p = $tx->query(slowCteQuery());

                Loop::addTimer(0.1, fn () => $p->cancel());

                try {
                    await($p);
                } catch (CancelledException $e) {
                    // BAD PRACTICE: Swallowing the error and returning normally
                }

                return 'Should not reach here successfully';
            });

            expect(fn () => await($wrapperPromise))
                ->toThrow(TransactionException::class, 'Transaction aborted due to a previous error')
            ;
        } finally {
            $client->close();
        }
    });

    it('maintains safe connection state when kill_worker_on_cancel is disabled (opt-out) inside a transaction', function () {
        $client = makeClient(['maxConnections' => 1, 'kill_worker_on_cancel' => false]);

        try {
            $tx = await($client->beginTransaction());

            $promise = $tx->query(slowCteQuery());
            Loop::addTimer(0.1, fn () => $promise->cancel());

            expect(fn () => await($promise))->toThrow(CancelledException::class);

            expect(fn () => await($tx->query('SELECT 1')))
                ->toThrow(TransactionException::class)
            ;

            await($tx->rollback());

            $val = await($client->fetchValue('SELECT 42 AS ok'));
            expect((int)$val)->toBe(42);
        } finally {
            $client->close();
        }
    });
});

describe('Transaction Cancellation - Prepared Statement Streaming & Wrappers', function () {

    it('cancels executeStream() mid-iteration, taints the manual transaction, and forces rollback', function () {
        // Test with opt-out so it can verify the connection recovers cleanly
        $client = makeClient(['maxConnections' => 1, 'kill_worker_on_cancel' => false]);

        try {
            $tx = await($client->beginTransaction());
            $stmt = await($tx->prepare(streamCancelQuery()));

            $stream = await($stmt->executeStream([], 10));

            $count = 0;
            $threw = false;

            try {
                foreach ($stream as $row) {
                    $count++;
                    if ($count === 3) {
                        $stream->cancel();
                    }
                }
            } catch (CancelledException $e) {
                $threw = true;
            }

            expect($count)->toBe(3)
                ->and($threw)->toBeTrue()
                ->and($stream->isCancelled())->toBeTrue()
            ;

            expect(fn () => await($tx->query('SELECT 1')))
                ->toThrow(TransactionException::class, 'Transaction aborted due to a previous query error')
            ;

            await($stmt->close());
            await($tx->rollback());

            $result = await($client->query('SELECT 42 AS ok'));
            expect((int)$result->fetchOne()['ok'])->toBe(42);
        } finally {
            $client->close();
        }
    });

    it('auto-rolls back when a stream() is cancelled mid-iteration inside the transaction() wrapper', function () {
        $client = makeClient(['maxConnections' => 1, 'kill_worker_on_cancel' => true]);

        try {
            await($client->query('CREATE TABLE txn_stream_wrap (v TEXT)'));

            $wrapperPromise = $client->transaction(function (TransactionInterface $tx) {
                await($tx->execute("INSERT INTO txn_stream_wrap VALUES ('will_be_rolled_back')"));

                $stream = await($tx->stream(streamCancelQuery(), [], 10));

                $count = 0;
                foreach ($stream as $row) {
                    $count++;
                    if ($count === 3) {
                        $stream->cancel();
                    }
                }
            });

            expect(fn () => await($wrapperPromise))->toThrow(CancelledException::class);

            $count = await($client->fetchValue('SELECT count(*) FROM txn_stream_wrap'));
            expect((int)$count)->toBe(0);
        } finally {
            $client->close();
        }
    });

    it('auto-rolls back when executeStream() is cancelled mid-iteration inside the transaction() wrapper', function () {
        $client = makeClient(['maxConnections' => 1, 'kill_worker_on_cancel' => false]);

        try {
            await($client->query('CREATE TABLE txn_stmt_stream_wrap (v TEXT)'));

            $wrapperPromise = $client->transaction(function (TransactionInterface $tx) {
                await($tx->execute("INSERT INTO txn_stmt_stream_wrap VALUES ('will_be_rolled_back')"));

                $stmt = await($tx->prepare(streamCancelQuery()));
                $stream = await($stmt->executeStream([], 10));

                $count = 0;
                foreach ($stream as $row) {
                    $count++;
                    if ($count === 2) {
                        $stream->cancel();
                    }
                }

                await($stmt->close());
            });

            expect(fn () => await($wrapperPromise))->toThrow(CancelledException::class);

            $count = await($client->fetchValue('SELECT count(*) FROM txn_stmt_stream_wrap'));
            expect((int)$count)->toBe(0);
        } finally {
            $client->close();
        }
    });

});
