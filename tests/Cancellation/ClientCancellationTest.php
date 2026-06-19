<?php

declare(strict_types=1);

use Hibla\EventLoop\Loop;
use Hibla\Promise\Exceptions\CancelledException;
use Hibla\Sql\RowStream as RowStreamInterface;

use function Hibla\await;
use function Hibla\delay;

describe('SqliteClient - Query Cancellation', function () {

    it('cancels a running query() and spawns a fresh connection next when kill_worker_on_cancel is enabled', function () {
        $client = makeClient([
            'maxConnections' => 1,
            'kill_worker_on_cancel' => true,
        ]);

        try {
            $promise = $client->query(slowCteQuery());

            Loop::addTimer(0.1, function () use ($promise) {
                $promise->cancel();
            });

            expect(fn () => await($promise))->toThrow(CancelledException::class);

            expect($client->stats['active_connections'])->toBe(0)
                ->and($client->stats['pooled_connections'])->toBe(0)
                ->and($client->stats['total_connections'])->toBe(0)
            ;

            $result = await($client->query('SELECT 42 AS ok'));
            expect($result->fetchOne()['ok'])->toBe(42);
        } finally {
            $client->close();
        }
    });

    it('cancels a running query() and recycles the connection safely when kill_worker_on_cancel is disabled (default)', function () {
        $client = makeClient([
            'maxConnections' => 1,
            'kill_worker_on_cancel' => false,
        ]);

        try {
            $promise = $client->query(slowCteQuery());

            Loop::addTimer(0.1, function () use ($promise) {
                $promise->cancel();
            });

            expect(fn () => await($promise))->toThrow(CancelledException::class);

            expect($client->stats['active_connections'])->toBe(0)
                ->and($client->stats['pooled_connections'])->toBe(1)
                ->and($client->stats['total_connections'])->toBe(1)
            ;

            $result = await($client->query('SELECT 123 AS ok'));
            expect($result->fetchOne()['ok'])->toBe(123);
        } finally {
            $client->close();
        }
    });

    it('cancels a running execute() call safely', function () {
        $client = makeClient([
            'maxConnections' => 1,
            'kill_worker_on_cancel' => true,
        ]);

        try {
            $promise = $client->execute(slowCteQuery());

            Loop::addTimer(0.1, function () use ($promise) {
                $promise->cancel();
            });

            expect(fn () => await($promise))->toThrow(CancelledException::class);
            expect($client->stats['active_connections'])->toBe(0);
        } finally {
            $client->close();
        }
    });

    it('cancels a running fetchOne() call safely', function () {
        $client = makeClient([
            'maxConnections' => 1,
            'kill_worker_on_cancel' => true,
        ]);

        try {
            $promise = $client->fetchOne(slowCteQuery());

            Loop::addTimer(0.1, function () use ($promise) {
                $promise->cancel();
            });

            expect(fn () => await($promise))->toThrow(CancelledException::class);
            expect($client->stats['active_connections'])->toBe(0);
        } finally {
            $client->close();
        }
    });

    it('cancels a running fetchValue() call safely', function () {
        $client = makeClient([
            'maxConnections' => 1,
            'kill_worker_on_cancel' => true,
        ]);

        try {
            $promise = $client->fetchValue(slowCteQuery());

            Loop::addTimer(0.1, function () use ($promise) {
                $promise->cancel();
            });

            expect(fn () => await($promise))->toThrow(CancelledException::class);
            expect($client->stats['active_connections'])->toBe(0);
        } finally {
            $client->close();
        }
    });
});

describe('SqliteClient - Streaming & Prepared Statement Cancellation', function () {

    it('cancels an active stream() mid-iteration safely', function () {
        $client = makeClient([
            'maxConnections' => 1,
            'kill_worker_on_cancel' => false,
        ]);

        try {
            $stream = await($client->stream(streamCancelQuery(), [], 10));
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
                ->and($stream->isCancelled())->toBeTrue()
            ;

            await(delay(0.2));
            expect($client->stats['active_connections'])->toBe(0);

            $result = await($client->query('SELECT 42 AS ok'));
            expect($result->fetchOne()['ok'])->toBe(42);
        } finally {
            $client->close();
        }
    });

    it('cancels an active prepared statement execute() safely', function () {
        $client = makeClient([
            'maxConnections' => 1,
            'kill_worker_on_cancel' => true,
        ]);

        try {
            $stmt = await($client->prepare(slowCteQuery()));
            $promise = $stmt->execute();

            Loop::addTimer(0.1, function () use ($promise) {
                $promise->cancel();
            });

            expect(fn () => await($promise))->toThrow(CancelledException::class);

            await($stmt->close());

            expect($client->stats['active_connections'])->toBe(0);
        } finally {
            $client->close();
        }
    });

    it('cancels a parameterized query() safely', function () {
        $client = makeClient([
            'maxConnections' => 1,
            'kill_worker_on_cancel' => true,
        ]);

        try {
            $sql = '
                WITH RECURSIVE cnt(x) AS (
                    SELECT 1 UNION ALL SELECT x+1 FROM cnt WHERE x < :limit
                )
                SELECT sum(a.x + b.x + c.x) FROM cnt a CROSS JOIN cnt b CROSS JOIN cnt c;
            ';

            $promise = $client->query($sql, ['limit' => 200]);

            Loop::addTimer(0.1, function () use ($promise) {
                $promise->cancel();
            });

            expect(fn () => await($promise))->toThrow(CancelledException::class);
            expect($client->stats['active_connections'])->toBe(0);
        } finally {
            $client->close();
        }
    });
});
