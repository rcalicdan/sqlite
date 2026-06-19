<?php

declare(strict_types=1);

use Hibla\EventLoop\Loop;
use Hibla\Promise\Exceptions\CancelledException;
use Hibla\Promise\Promise;

use function Hibla\await;
use function Hibla\delay;

describe('PoolManager - Query Cancellation', function () {

    it('discards the connection and spawns a fresh replacement on next get when kill_worker_on_cancel is enabled', function () {
        $pool = makePool([
            'maxSize' => 1,
            'kill_worker_on_cancel' => true,
        ]);

        try {
            $conn1 = await($pool->get());
            $id1 = spl_object_id($conn1);

            $slowPromise = $conn1->query(slowCteQuery());

            Loop::addTimer(0.1, function () use ($slowPromise) {
                $slowPromise->cancel();
            });

            try {
                await($slowPromise);
            } catch (CancelledException $e) {
            }

            $pool->release($conn1);
            expect($pool->stats['total_connections'])->toBe(0);

            $conn2 = await($pool->get());
            $id2 = spl_object_id($conn2);

            expect($id1)->not->toBe($id2)
                ->and($conn2->isClosed())->toBeFalse()
            ;

            $pool->release($conn2);
        } finally {
            $pool->close();
        }
    });

    it('recycles the connection and safely queues subsequent queries when kill_worker_on_cancel is disabled (default)', function () {
        $pool = makePool([
            'maxSize' => 1,
            'kill_worker_on_cancel' => false,
        ]);

        try {
            $conn = await($pool->get());
            $id1 = spl_object_id($conn);

            $slowPromise = $conn->query(slowCteQuery());

            Loop::addTimer(0.1, function () use ($slowPromise) {
                $slowPromise->cancel();
            });

            try {
                await($slowPromise);
            } catch (CancelledException $e) {
            }

            $pool->release($conn);
            expect($pool->stats['total_connections'])->toBe(1);

            $conn2 = await($pool->get());
            $id2 = spl_object_id($conn2);

            expect($id1)->toBe($id2);

            $result = await($conn2->query('SELECT 123 AS val'));
            expect($result->fetchOne()['val'])->toBe(123);

            $pool->release($conn2);
        } finally {
            $pool->close();
        }
    });

    it('bypasses cancelled waiters and satisfies the next active waiter in the queue', function () {
        $pool = makePool(['maxSize' => 1]);

        try {
            $conn = await($pool->get());

            $waiter1 = $pool->get();
            $waiter2 = $pool->get();

            $waiter1->cancel();

            $pool->release($conn);

            expect(fn () => await($waiter1))->toThrow(CancelledException::class);

            $conn2 = await($waiter2);
            expect($conn2->isClosed())->toBeFalse();

            $pool->release($conn2);
        } finally {
            $pool->close();
        }
    });

    it('gracefully closeAsync() waits for active connections with cancelled queries to be released before resolving', function () {
        $pool = makePool([
            'maxSize' => 1,
            'kill_worker_on_cancel' => false,
        ]);

        try {
            $conn = await($pool->get());

            $slowPromise = $conn->query(slowCteQuery());
            Loop::addTimer(0.1, fn () => $slowPromise->cancel());

            try {
                await($slowPromise);
            } catch (CancelledException $e) {
            }

            $resolved = false;
            $shutdown = $pool->closeAsync()->then(function () use (&$resolved): void {
                $resolved = true;
            });

            await(delay(0.2));
            expect($resolved)->toBeFalse();

            $pool->release($conn);

            await($shutdown);
            expect($resolved)->toBeTrue()
                ->and($pool->stats['total_connections'])->toBe(0)
            ;
        } finally {
            $pool->close();
        }
    });

    it('clears and parks the connection cleanly when all queued waiters are cancelled', function () {
        $pool = makePool(['maxSize' => 1]);

        try {
            $conn = await($pool->get());

            $w1 = $pool->get();
            $w2 = $pool->get();

            $w1->cancel();
            $w2->cancel();

            $pool->release($conn);

            expect($w1->isCancelled())->toBeTrue()
                ->and($w2->isCancelled())->toBeTrue()
            ;

            expect($pool->stats['pooled_connections'])->toBe(1)
                ->and($pool->stats['active_connections'])->toBe(0)
            ;
        } finally {
            $pool->close();
        }
    });

    it('parks the fresh connection cleanly when a waiter cancels while its setup hook is running', function () {
        $hookRunning = false;

        $pool = makePool([
            'maxSize' => 1,
            'onConnect' => function () use (&$hookRunning): void {
                $hookRunning = true;
                await(delay(0.2));
            },
        ]);

        try {
            $conn = await($pool->get());
            expect($pool->stats['total_connections'])->toBe(1);

            $waiter = $pool->get();
            expect($waiter->isPending())->toBeTrue();

            $conn->close(true);
            $pool->release($conn);

            await(delay(0.05));
            expect($hookRunning)->toBeTrue();

            $waiter->cancel();

            await(delay(0.25));

            expect(fn () => await($waiter))->toThrow(CancelledException::class);

            expect($pool->stats['pooled_connections'])->toBe(1)
                ->and($pool->stats['active_connections'])->toBe(0)
            ;
        } finally {
            $pool->close();
        }
    });

    it('handles partial waiter cancellation inside a multi-waiter queue safely', function () {
        $pool = makePool(['maxSize' => 1]);

        try {
            $conn = await($pool->get());

            $w1 = $pool->get();
            $w2 = $pool->get();
            $w3 = $pool->get();

            $w2->cancel();

            $pool->release($conn);

            $conn1 = await($w1);
            expect($conn1->isClosed())->toBeFalse();

            expect($w2->isCancelled())->toBeTrue();
            expect(fn () => await($w2))->toThrow(CancelledException::class);

            expect($w3->isPending())->toBeTrue();

            $pool->release($conn1);

            $conn3 = await($w3);
            expect($conn3->isClosed())->toBeFalse();

            $pool->release($conn3);
        } finally {
            $pool->close();
        }
    });

    it('clears the acquire timeout timer when a waiter is cancelled manually', function () {
        $pool = makePool(['maxSize' => 1, 'acquireTimeout' => 1.0]);

        try {
            $conn = await($pool->get());

            $waiter = $pool->get();

            await(delay(0.05));
            $waiter->cancel();

            expect(fn () => await($waiter))->toThrow(CancelledException::class);

            await(delay(1.1));

            $pool->release($conn);
        } finally {
            $pool->close();
        }
    });

    it('gracefully closeAsync() waits for multiple active connections with cancelled queries to settle before resolving', function () {
        $pool = makePool([
            'maxSize' => 2,
            'kill_worker_on_cancel' => false,
        ]);

        try {
            $conn1 = await($pool->get());
            $conn2 = await($pool->get());

            $p1 = $conn1->query(slowCteQuery());
            $p2 = $conn2->query(slowCteQuery());

            Loop::addTimer(0.1, function () use ($p1, $p2) {
                $p1->cancel();
                $p2->cancel();
            });

            try {
                await(Promise::all([$p1, $p2]));
            } catch (CancelledException $e) {
            }

            $resolved = false;
            $shutdown = $pool->closeAsync()->then(function () use (&$resolved): void {
                $resolved = true;
            });

            await(delay(0.2));
            expect($resolved)->toBeFalse();

            $pool->release($conn1);
            $pool->release($conn2);

            await($shutdown);
            expect($resolved)->toBeTrue()
                ->and($pool->stats['total_connections'])->toBe(0)
            ;
        } finally {
            $pool->close();
        }
    });

    it('is idempotent on double waiter cancellation', function () {
        $pool = makePool(['maxSize' => 1]);

        try {
            $conn = await($pool->get());

            $waiter = $pool->get();

            $waiter->cancel();
            $waiter->cancel();

            expect(fn () => await($waiter))->toThrow(CancelledException::class);

            $pool->release($conn);
        } finally {
            $pool->close();
        }
    });
});
