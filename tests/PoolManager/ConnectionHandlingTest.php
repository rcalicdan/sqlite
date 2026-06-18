<?php

declare(strict_types=1);

use Hibla\EventLoop\Loop;
use Hibla\Promise\Exceptions\TimeoutException;
use Hibla\Promise\Promise;
use Hibla\Sql\Exceptions\PoolException;
use Hibla\Sqlite\Interfaces\ConnectionInterface;

use function Hibla\await;
use function Hibla\delay;

describe('PoolManager - Basic Acquisition and Release', function (): void {

    it('acquires a ready connection from the pool', function (): void {
        $pool = makePool();

        try {
            $conn = await($pool->get());

            expect($conn)->toBeInstanceOf(ConnectionInterface::class)
                ->and($conn->isClosed())->toBeFalse()
            ;

            $pool->release($conn);
        } finally {
            $pool->close();
        }
    });

    it('reuses the same connection after releasing it back to the pool', function (): void {
        $pool = makePool(['maxSize' => 1]);

        try {
            $conn1 = await($pool->get());
            $connId1 = spl_object_id($conn1);
            $pool->release($conn1);

            $conn2 = await($pool->get());
            $connId2 = spl_object_id($conn2);

            expect($connId1)->toBe($connId2);

            $pool->release($conn2);
        } finally {
            $pool->close();
        }
    });

    it('creates different connections when multiple are acquired concurrently', function (): void {
        $pool = makePool(['maxSize' => 2]);

        try {
            $conn1 = await($pool->get());
            $conn2 = await($pool->get());

            expect(spl_object_id($conn1))->not->toBe(spl_object_id($conn2));

            $pool->release($conn1);
            $pool->release($conn2);
        } finally {
            $pool->close();
        }
    });

    it('discards a closed connection on release and spawns a fresh replacement on next get', function (): void {
        $pool = makePool(['maxSize' => 1]);

        $conn1 = await($pool->get());
        $pid1 = spl_object_id($conn1);
        $conn1->close(true);

        $pool->release($conn1);

        $conn2 = await($pool->get());
        $pid2 = spl_object_id($conn2);

        expect($pid1)->not->toBe($pid2)
            ->and($conn2->isClosed())->toBeFalse()
        ;

        $pool->release($conn2);
        $pool->close();
    });

    it('is idempotent on double release and does not double-park a connection', function (): void {
        $pool = makePool(['maxSize' => 1]);

        try {
            $conn = await($pool->get());

            $pool->release($conn);
            $pool->release($conn);

            expect($pool->stats['pooled_connections'])->toBe(1)
                ->and($pool->stats['active_connections'])->toBe(0)
            ;
        } finally {
            $pool->close();
        }
    });

    it('is a safe no-op when releasing a connection after the pool is closed', function (): void {
        $pool = makePool(['maxSize' => 1]);

        $conn = await($pool->get());
        $pool->close();

        expect(fn() => $pool->release($conn))->not->toThrow(Throwable::class);
        expect($conn->isClosed())->toBeTrue();
    });
});

describe('PoolManager - Pool Size Enforcement', function (): void {

    it('enforces the maxSize limit and queues subsequent requests as waiters', function (): void {
        $pool = makePool(['maxSize' => 2]);

        try {
            $conn1 = await($pool->get());
            $conn2 = await($pool->get());

            $waiter = $pool->get();
            expect($waiter->isPending())->toBeTrue()
                ->and($pool->stats['waiting_requests'])->toBe(1)
            ;

            $pool->release($conn1);

            $conn3 = await($waiter);
            expect($conn3->isClosed())->toBeFalse();

            $pool->release($conn2);
            $pool->release($conn3);
        } finally {
            $pool->close();
        }
    });

    it('throws PoolException immediately when maxWaiters queue limit is reached', function (): void {
        $pool = makePool(['maxSize' => 1, 'maxWaiters' => 1]);

        try {
            $conn = await($pool->get());
            $waiter1 = $pool->get();

            expect(fn() => await($pool->get()))
                ->toThrow(PoolException::class, 'Connection pool exhausted. Max waiters limit (1) reached.');

            $pool->release($conn);
            $conn2 = await($waiter1);
            $pool->release($conn2);
        } finally {
            $pool->close();
        }
    });

    it('pre-warms the pool to minSize on construction', function (): void {
        $pool = makePool(['maxSize' => 5, 'minSize' => 2]);

        try {
            await(delay(0.1));

            $stats = $pool->stats;
            expect($stats['total_connections'])->toBe(2)
                ->and($stats['pooled_connections'])->toBe(2)
            ;
        } finally {
            $pool->close();
        }
    });

    it('serves queued waiters in strict FIFO (First-In, First-Out) order', function (): void {
        $pool = makePool(['maxSize' => 1]);

        try {
            $conn = await($pool->get());

            $resolvedOrder = [];
            $w1 = $pool->get()->then(function ($c) use (&$resolvedOrder, $pool) {
                $resolvedOrder[] = 1;
                $pool->release($c);
            });
            $w2 = $pool->get()->then(function ($c) use (&$resolvedOrder, $pool) {
                $resolvedOrder[] = 2;
                $pool->release($c);
            });
            $w3 = $pool->get()->then(function ($c) use (&$resolvedOrder, $pool) {
                $resolvedOrder[] = 3;
                $pool->release($c);
            });

            $pool->release($conn);

            await(Promise::all([$w1, $w2, $w3]));

            expect($resolvedOrder)->toBe([1, 2, 3]);
        } finally {
            $pool->close();
        }
    });
});

describe('PoolManager - Waiter Timeouts', function (): void {

    it('rejects a waiter with TimeoutException when acquireTimeout is exceeded', function (): void {
        $pool = makePool(['maxSize' => 1, 'acquireTimeout' => 0.1]);

        try {
            $conn = await($pool->get());

            expect(fn() => await($pool->get()))
                ->toThrow(TimeoutException::class);

            $pool->release($conn);
        } finally {
            $pool->close();
        }
    });

    it('cancels the timeout timer when a waiter is successfully satisfied in time', function (): void {
        $pool = makePool(['maxSize' => 1, 'acquireTimeout' => 1.0]);

        try {
            $conn = await($pool->get());
            $waiter = $pool->get();

            Loop::addTimer(0.05, fn() => $pool->release($conn));

            $conn2 = await($waiter);
            expect($conn2->isClosed())->toBeFalse();

            $pool->release($conn2);
        } finally {
            $pool->close();
        }
    });
});

describe('PoolManager - Lifetimes & Eviction', function (): void {

    it('evicts idle connections that exceed idleTimeout on next borrow', function (): void {
        $pool = makePool(['maxSize' => 1, 'idleTimeout' => 1]);

        try {
            $conn = await($pool->get());
            $id1 = spl_object_id($conn);
            $pool->release($conn);

            await(delay(1.1));

            $conn2 = await($pool->get());
            $id2 = spl_object_id($conn2);

            expect($id1)->not->toBe($id2);

            $pool->release($conn2);
        } finally {
            $pool->close();
        }
    });

    it('evicts connections that exceed maxLifetime on next borrow', function (): void {
        $pool = makePool(['maxSize' => 1, 'maxLifetime' => 1]);

        try {
            $conn = await($pool->get());
            $id1 = spl_object_id($conn);
            $pool->release($conn);

            await(delay(1.1));

            $conn2 = await($pool->get());
            $id2 = spl_object_id($conn2);

            expect($id1)->not->toBe($id2);

            $pool->release($conn2);
        } finally {
            $pool->close();
        }
    });

    it('replenishes pool to minSize when an idle connection is evicted', function (): void {
        $pool = makePool(['maxSize' => 2, 'minSize' => 1, 'idleTimeout' => 1]);

        try {
            await(delay(0.1));
            expect($pool->stats['pooled_connections'])->toBe(1);

            $conn = await($pool->get());
            $pool->release($conn);

            await(delay(1.1));

            $conn2 = await($pool->get());
            expect($conn2->isClosed())->toBeFalse();

            await(delay(0.1));

            expect($pool->stats['total_connections'])->toBe(2);

            $pool->release($conn2);
        } finally {
            $pool->close();
        }
    });
});

describe('PoolManager - Health Check', function (): void {

    it('pings only idle connections and ignores active, checked-out connections', function (): void {
        $pool = makePool(['maxSize' => 2]);

        try {
            $activeConn = await($pool->get());

            $idleConn = await($pool->get());
            $pool->release($idleConn);

            expect($pool->stats['pooled_connections'])->toBe(1)
                ->and($pool->stats['active_connections'])->toBe(1)
            ;

            $check = await($pool->healthCheck());

            expect($check['total_checked'])->toBe(1)
                ->and($check['healthy'])->toBe(1)
                ->and($check['unhealthy'])->toBe(0)
            ;

            $pool->release($activeConn);
        } finally {
            $pool->close();
        }
    });
});

describe('PoolManager - Connection Reset Hooks', function (): void {

    it('drops the connection and satisfies next waiter with a fresh connection if hook fails on reset', function (): void {
        $attempts = 0;
        $pool = makePool([
            'maxSize' => 1,
            'resetConnection' => true,
            'onConnect' => function () use (&$attempts) {
                $attempts++;
                if ($attempts === 2) {
                    throw new RuntimeException('Simulated setup failure during reset');
                }
            }
        ]);

        try {
            $conn = await($pool->get());
            expect($attempts)->toBe(1);

            $pool->release($conn);

            await(delay(0.5));
            expect($pool->stats['total_connections'])->toBe(0);

            $conn2 = await($pool->get());
            expect($attempts)->toBe(3);

            $pool->release($conn2);
        } finally {
            $pool->close();
        }
    });
});

describe('PoolManager - Graceful and Force Shutdowns', function (): void {

    it('force close immediately releases and terminates all idle connections', function (): void {
        $pool = makePool(['maxSize' => 2]);

        $conn1 = await($pool->get());
        $conn2 = await($pool->get());

        $pool->release($conn1);
        $pool->release($conn2);

        expect($pool->stats['pooled_connections'])->toBe(2);

        $pool->close();

        expect($pool->stats['pooled_connections'])->toBe(0)
            ->and($pool->stats['total_connections'])->toBe(0)
        ;
    });

    it('closeAsync() gracefully waits for active connections to finish before resolving', function (): void {
        $pool = makePool(['maxSize' => 1]);

        $conn = await($pool->get());
        $resolved = false;

        $shutdown = $pool->closeAsync()->then(function () use (&$resolved): void {
            $resolved = true;
        });

        await(delay(0.01));
        expect($resolved)->toBeFalse();

        $pool->release($conn);

        await($shutdown);
        expect($resolved)->toBeTrue()
            ->and($pool->stats['total_connections'])->toBe(0)
        ;
    });

    it('closeAsync() immediately falls back to force close if optional timeout expires', function (): void {
        $pool = makePool(['maxSize' => 1]);

        $conn = await($pool->get());
        $shutdown = $pool->closeAsync(0.1);

        await($shutdown);

        expect($conn->isClosed())->toBeTrue()
            ->and($pool->stats['total_connections'])->toBe(0)
        ;
    });
});

describe('PoolManager - Schema Cleanups', function (): void {

    it('physically unlinks the SQLite database files on shutdown if deleteDatabaseOnShutdown is enabled', function (): void {
        $dbFile = tempDbFile();

        $pool = makePool([
            'database' => $dbFile,
            'deleteDatabaseOnShutdown' => true,
        ]);

        $conn = await($pool->get());
        await($conn->query('CREATE TABLE t (v INT)'));
        $pool->release($conn);

        expect(file_exists($dbFile))->toBeTrue();
        $pool->close();

        expect(file_exists($dbFile))->toBeFalse()
            ->and(file_exists($dbFile . '-wal'))->toBeFalse()
            ->and(file_exists($dbFile . '-shm'))->toBeFalse()
        ;
    });
});
