<?php

declare(strict_types=1);

use Hibla\Promise\Exceptions\CancelledException;
use Hibla\Sql\RowStream as RowStreamInterface;
use Hibla\Sqlite\Internals\AsyncConnection;
use Hibla\Sqlite\Internals\ConnectionFactory;
use Hibla\Sqlite\Internals\SqliteRowStream;
use Hibla\Sqlite\Internals\SyncRowStream;

use function Hibla\await;
use function Hibla\delay;

describe('AsyncConnection - Streaming Cancellation', function () {

    it('cancels a queued stream promise before it starts executing (Tier 1)', function () {
        $conn = sqliteConn(['force_sync' => false]);

        try {
            $slowPromise = $conn->query(slowCteQuery());
            $stream = await($conn->streamQuery(streamCancelQuery(), 10));

            if ($stream instanceof SqliteRowStream || $stream instanceof SyncRowStream) {
                $stream->onClose()->catch(function (Throwable $e): void {
                });
            }

            $stream->cancel();
            await($slowPromise);

            expect($stream->isCancelled())->toBeTrue();
            expect(fn () => iterator_to_array($stream))->toThrow(CancelledException::class);

            $result = await($conn->query('SELECT 42 AS val'));
            expect($result->fetchOne()['val'])->toBe(42);
        } finally {
            $conn->close();
        }
    });

    it('cancels a queued prepared statement stream before it starts executing (Tier 1)', function () {
        $conn = sqliteConn(['force_sync' => false]);

        try {
            $slowPromise = $conn->query(slowCteQuery());

            $stmt = await($conn->prepare(streamCancelQuery()));
            $stream = await($conn->executeStream($stmt, [], 10));

            if ($stream instanceof SqliteRowStream || $stream instanceof SyncRowStream) {
                $stream->onClose()->catch(function (Throwable $e): void {
                });
            }

            $stream->cancel();
            await($slowPromise);

            expect($stream->isCancelled())->toBeTrue();
            expect(fn () => iterator_to_array($stream))->toThrow(CancelledException::class);

            await($stmt->close());

            $result = await($conn->query('SELECT 42 AS val'));
            expect($result->fetchOne()['val'])->toBe(42);
        } finally {
            $conn->close();
        }
    });

    it('cancels the stream pre-iteration after the stream object is obtained (Tier 2)', function () {
        $conn = sqliteConn(['force_sync' => false]);

        try {
            $stream = await($conn->streamQuery(streamCancelQuery(), 10));
            expect($stream)->toBeInstanceOf(RowStreamInterface::class);

            if ($stream instanceof SqliteRowStream || $stream instanceof SyncRowStream) {
                $stream->onClose()->catch(function (Throwable $e): void {
                });
            }

            $stream->cancel();

            expect($stream->isCancelled())->toBeTrue();
            expect(fn () => iterator_to_array($stream))->toThrow(CancelledException::class, 'Stream was cancelled.');
        } finally {
            $conn->close();
        }
    });

    it('cancels a stream mid-iteration and leaves the connection healthy (kill_worker_on_cancel = false, default)', function () {
        $config = dbConfig([
            'force_sync' => false,
            'kill_worker_on_cancel' => false,
        ]);

        /** @var AsyncConnection $conn */
        $conn = await(ConnectionFactory::create($config));

        try {
            $stream = await($conn->streamQuery(streamCancelQuery(), 10));
            expect($stream)->toBeInstanceOf(RowStreamInterface::class);

            if ($stream instanceof SqliteRowStream || $stream instanceof SyncRowStream) {
                $stream->onClose()->catch(function (Throwable $e): void {
                });
            }

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
                ->and($conn->isClosed())->toBeFalse()
            ;

            await(delay(0.2));

            $result = await($conn->query('SELECT 99 AS ok'));
            expect($result->fetchOne()['ok'])->toBe(99);
        } finally {
            $conn->close();
        }
    });

    it('tears down the connection and kills the worker process when a stream is cancelled with kill_worker_on_cancel enabled', function () {
        $config = dbConfig([
            'force_sync' => false,
            'kill_worker_on_cancel' => true,
        ]);

        /** @var AsyncConnection $conn */
        $conn = await(ConnectionFactory::create($config));

        try {
            $stream = await($conn->streamQuery(streamCancelQuery(), 10));

            if ($stream instanceof SqliteRowStream || $stream instanceof SyncRowStream) {
                $stream->onClose()->catch(function (Throwable $e): void {
                });
            }

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
                ->and($conn->isClosed())->toBeTrue()
            ;
        } finally {
            $conn->close();
            await(delay(0.1));
        }
    });

    it('cancels a prepared statement stream mid-iteration and leaves the connection healthy', function () {
        $conn = sqliteConn(['force_sync' => false]);

        try {
            $stmt = await($conn->prepare('SELECT ? AS val'));

            $stream = await($conn->executeStream($stmt, [100], 10));

            if ($stream instanceof SqliteRowStream || $stream instanceof SyncRowStream) {
                $stream->onClose()->catch(function (Throwable $e): void {
                });
            }

            $count = 0;
            $threw = false;

            try {
                foreach ($stream as $row) {
                    $count++;
                    $stream->cancel();
                }
            } catch (CancelledException $e) {
                $threw = true;
            }

            expect($count)->toBe(1)
                ->and($threw)->toBeTrue()
                ->and($stream->isCancelled())->toBeTrue()
            ;

            await($stmt->close());

            $result = await($conn->query('SELECT 42 AS ok'));
            expect($result->fetchOne()['ok'])->toBe(42);
        } finally {
            $conn->close();
        }
    });
});

describe('SyncConnection - Streaming Cancellation', function (): void {

    it('cancels a sync stream mid-iteration instantly', function (): void {
        $conn = sqliteConn(['force_sync' => true]);

        try {
            $stream = await($conn->streamQuery('SELECT 1 UNION ALL SELECT 2 UNION ALL SELECT 3'));

            if ($stream instanceof SqliteRowStream || $stream instanceof SyncRowStream) {
                $stream->onClose()->catch(function (Throwable $e): void {
                });
            }

            $count = 0;
            $threw = false;

            try {
                foreach ($stream as $row) {
                    $count++;
                    if ($count === 2) {
                        $stream->cancel();
                    }
                }
            } catch (CancelledException $e) {
                $threw = true;
            }

            expect($count)->toBe(2)
                ->and($threw)->toBeTrue()
                ->and($stream->isCancelled())->toBeTrue()
            ;
        } finally {
            $conn->close();
        }
    });

    it('cancels a prepared statement stream mid-iteration on SyncConnection', function (): void {
        $conn = sqliteConn(['force_sync' => true]);

        try {
            $stmt = await($conn->prepare('SELECT ? AS val UNION ALL SELECT ? AS val'));
            $stream = await($conn->executeStream($stmt, [100, 200]));

            if ($stream instanceof SqliteRowStream || $stream instanceof SyncRowStream) {
                $stream->onClose()->catch(function (Throwable $e): void {
                });
            }

            $count = 0;
            $threw = false;

            try {
                foreach ($stream as $row) {
                    $count++;
                    $stream->cancel();
                }
            } catch (CancelledException $e) {
                $threw = true;
            }

            expect($count)->toBe(1)
                ->and($threw)->toBeTrue()
                ->and($stream->isCancelled())->toBeTrue()
            ;

            await($stmt->close());
        } finally {
            $conn->close();
        }
    });
});
