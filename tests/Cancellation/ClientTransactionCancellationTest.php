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
