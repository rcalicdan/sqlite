<?php

declare(strict_types=1);

use Hibla\Sql\Exceptions\ConstraintViolationException;
use Hibla\Sql\Exceptions\DeadlockException;
use Hibla\Sql\Exceptions\LockWaitTimeoutException;
use Hibla\Sql\Exceptions\QueryException;
use Hibla\Sql\Exceptions\TransactionException;
use Hibla\Sql\Transaction as TransactionInterface;
use Hibla\Sql\TransactionOptions;
use Hibla\Sqlite\Internals\Transaction;
use Tests\Fixtures\MyCustomServiceException;

use function Hibla\await;
use function Hibla\delay;

describe('Transaction - Manual Basics', function (): void {

    it('commits transactional writes successfully', function (): void {
        $client = makeClient(['maxConnections' => 1]);

        try {
            await($client->query('CREATE TABLE txn_test (v TEXT)'));

            $tx = await($client->beginTransaction());
            expect($tx->isActive())->toBeTrue()
                ->and($tx->isClosed())->toBeFalse()
            ;

            await($tx->query("INSERT INTO txn_test VALUES ('committed_val')"));
            await($tx->commit());

            expect($tx->isActive())->toBeFalse();

            $val = await($client->fetchValue('SELECT v FROM txn_test'));
            expect($val)->toBe('committed_val');
        } finally {
            $client->close();
        }
    });

    it('rolls back transactional writes successfully', function (): void {
        $client = makeClient(['maxConnections' => 1]);

        try {
            await($client->query('CREATE TABLE txn_test (v TEXT)'));

            $tx = await($client->beginTransaction());
            await($tx->query("INSERT INTO txn_test VALUES ('rolled_back_val')"));
            await($tx->rollback());

            expect($tx->isActive())->toBeFalse();

            $count = await($client->fetchValue('SELECT count(*) FROM txn_test'));
            expect((int)$count)->toBe(0);
        } finally {
            $client->close();
        }
    });

    it('is idempotent on double rollback', function (): void {
        $client = makeClient(['maxConnections' => 1]);

        try {
            $tx = await($client->beginTransaction());
            await($tx->rollback());

            expect(fn () => await($tx->rollback()))->not->toThrow(Throwable::class);
        } finally {
            $client->close();
        }
    });

    it('throws a TransactionException when executing queries after commit', function (): void {
        $client = makeClient();

        try {
            $tx = await($client->beginTransaction());
            await($tx->commit());

            expect(fn () => await($tx->query('SELECT 1')))
                ->toThrow(TransactionException::class, 'Transaction is no longer active')
            ;
        } finally {
            $client->close();
        }
    });
});

describe('Transaction - Auto-Managed', function (): void {

    it('automatically commits when the callback returns successfully', function (): void {
        $client = makeClient(['maxConnections' => 1]);

        try {
            await($client->query('CREATE TABLE txn_test (v TEXT)'));

            $payload = await($client->transaction(function (TransactionInterface $tx) {
                await($tx->execute("INSERT INTO txn_test VALUES ('auto_val')"));
                return 'return_payload';
            }));

            expect($payload)->toBe('return_payload');

            $val = await($client->fetchValue('SELECT v FROM txn_test'));
            expect($val)->toBe('auto_val');
        } finally {
            $client->close();
        }
    });

    it('automatically rolls back and rethrows when the callback throws an exception', function (): void {
        $client = makeClient(['maxConnections' => 1]);

        try {
            await($client->query('CREATE TABLE txn_test (v TEXT)'));

            $thrown = false;
            try {
                await($client->transaction(function (TransactionInterface $tx) {
                    await($tx->execute("INSERT INTO txn_test VALUES ('discard_val')"));
                    throw new RuntimeException('Intentional rollback');
                }));
            } catch (RuntimeException $e) {
                $thrown = true;
                expect($e->getMessage())->toBe('Intentional rollback');
            }

            expect($thrown)->toBeTrue();
            $count = await($client->fetchValue('SELECT count(*) FROM txn_test'));
            expect((int)$count)->toBe(0);
        } finally {
            $client->close();
        }
    });

    it('automatically retries when the callback throws a LockWaitTimeoutException (Tier 1)', function (): void {
        $client = makeClient();
        $attempts = 0;

        $options = TransactionOptions::default()->withAttempts(3);

        $result = await($client->transaction(function (TransactionInterface $tx) use (&$attempts) {
            $attempts++;
            if ($attempts < 3) {
                throw new LockWaitTimeoutException('Database is locked (busy)', 5);
            }
            return 'recovered_from_lock_timeout';
        }, $options));

        expect($result)->toBe('recovered_from_lock_timeout')
            ->and($attempts)->toBe(3)
        ;

        $client->close();
    });

    it('automatically retries when the callback throws a DeadlockException (Tier 1)', function (): void {
        $client = makeClient();
        $attempts = 0;

        $options = TransactionOptions::default()->withAttempts(3);

        $result = await($client->transaction(function (TransactionInterface $tx) use (&$attempts) {
            $attempts++;
            if ($attempts < 3) {
                throw new DeadlockException('Deadlock detected', 6);
            }
            return 'recovered_from_deadlock';
        }, $options));

        expect($result)->toBe('recovered_from_deadlock')
            ->and($attempts)->toBe(3)
        ;

        $client->close();
    });

    it('blocks retry on ConstraintViolationException even if explicitly configured in user predicate (Tier 2 Safeguard)', function (): void {
        $client = makeClient();
        $attempts = 0;

        $options = TransactionOptions::default()
            ->withAttempts(3)
            ->withRetryableExceptions([ConstraintViolationException::class]);

        $thrown = false;
        try {
            await($client->transaction(function (TransactionInterface $tx) use (&$attempts) {
                $attempts++;
                throw new ConstraintViolationException('UNIQUE constraint failed', 19);
            }, $options));
        } catch (ConstraintViolationException $e) {
            $thrown = true;
        }

        expect($thrown)->toBeTrue()
            ->and($attempts)->toBe(1) 
        ;

        $client->close();
    });

    it('supports retrying custom third-party exceptions via user predicate (Tier 3)', function (): void {
        $client = makeClient();
        $attempts = 0;

        $options = TransactionOptions::default()
            ->withAttempts(3)
            ->withRetryableExceptions([MyCustomServiceException::class]);

        $result = await($client->transaction(function (TransactionInterface $tx) use (&$attempts) {
            $attempts++;
            if ($attempts < 3) {
                throw new MyCustomServiceException('Third party API failed');
            }
            return 'recovered_from_third_party';
        }, $options));

        expect($result)->toBe('recovered_from_third_party')
            ->and($attempts)->toBe(3)
        ;

        $client->close();
    });

    it('does not retry and stops immediately on non-retryable exceptions', function (): void {
        $client = makeClient();
        $attempts = 0;

        $options = TransactionOptions::default()->withAttempts(5); // Non-retryable won't hit this

        try {
            await($client->transaction(function (TransactionInterface $tx) use (&$attempts) {
                $attempts++;
                throw new QueryException('Bad SQL syntax');
            }, $options));
        } catch (QueryException $e) {
            // expected
        }

        expect($attempts)->toBe(1);

        $client->close();
    });
});

describe('Transaction - Strict Tainting', function (): void {

    it('marks transaction as failed and rejects subsequent queries after any query fails', function (): void {
        $client = makeClient();

        try {
            $tx = await($client->beginTransaction());

            try {
                await($tx->query('NOT VALID SQL !!!'));
            } catch (QueryException $e) {
            }

            expect(fn () => await($tx->query('SELECT 1')))
                ->toThrow(TransactionException::class, 'Transaction aborted due to a previous query error')
            ;

            await($tx->rollback());
        } finally {
            $client->close();
        }
    });

    it('rejects commit() on a tainted transaction and forces rollback()', function (): void {
        $client = makeClient();

        try {
            $tx = await($client->beginTransaction());

            try {
                await($tx->query('INVALID SQL'));
            } catch (Throwable $e) {
            }

            expect(fn () => await($tx->commit()))
                ->toThrow(TransactionException::class, 'Transaction aborted due to a previous error') 
            ;

            expect(fn () => await($tx->rollback()))->not->toThrow(Throwable::class);
        } finally {
            $client->close();
        }
    });
});

describe('Transaction - Savepoints', function (): void {

    it('can create a savepoint, rollback to it, and commit the rest', function (): void {
        $client = makeClient();

        try {
            await($client->query('CREATE TABLE txn_test (v TEXT)'));

            $tx = await($client->beginTransaction());
            await($tx->execute("INSERT INTO txn_test VALUES ('A')"));

            await($tx->savepoint('sp1'));
            await($tx->execute("INSERT INTO txn_test VALUES ('B')"));

            await($tx->rollbackTo('sp1'));
            await($tx->execute("INSERT INTO txn_test VALUES ('C')"));

            await($tx->commit());

            $rows = await($client->query('SELECT v FROM txn_test ORDER BY rowid ASC'));
            $all = $rows->fetchAll();

            expect($all)->toHaveCount(2)
                ->and($all[0]['v'])->toBe('A')
                ->and($all[1]['v'])->toBe('C')
            ;
        } finally {
            $client->close();
        }
    });

    it('clears the tainted (failed) state after rolling back to a valid savepoint', function (): void {
        $client = makeClient();

        try {
            $tx = await($client->beginTransaction());
            await($tx->savepoint('safe_point'));

            try {
                await($tx->query('NOT A VALID QUERY'));
            } catch (QueryException $e) {
            }

            expect(fn () => await($tx->query('SELECT 1')))->toThrow(TransactionException::class);

            await($tx->rollbackTo('safe_point'));

            $val = await($tx->fetchValue('SELECT 99'));
            expect($val)->toBe(99);

            await($tx->commit());
        } finally {
            $client->close();
        }
    });

    it('can release a savepoint successfully', function (): void {
        $client = makeClient();

        try {
            $tx = await($client->beginTransaction());
            await($tx->savepoint('sp_release'));
            await($tx->releaseSavepoint('sp_release'));
            await($tx->commit());

            expect(true)->toBeTrue();
        } finally {
            $client->close();
        }
    });
});

describe('Transaction - Event Hooks', function (): void {

    it('fires onCommit callbacks only after a successful commit', function (): void {
        $client = makeClient();

        try {
            $tx = await($client->beginTransaction());

            $committed = false;
            $tx->onCommit(function () use (&$committed) {
                $committed = true;
            });

            await($tx->query('SELECT 1'));
            expect($committed)->toBeFalse();

            await($tx->commit());
            expect($committed)->toBeTrue();
        } finally {
            $client->close();
        }
    });

    it('fires onRollback callbacks only after a successful rollback', function (): void {
        $client = makeClient();

        try {
            $tx = await($client->beginTransaction());

            $rolledBack = false;
            $tx->onRollback(function () use (&$rolledBack) {
                $rolledBack = true;
            });

            await($tx->query('SELECT 1'));
            expect($rolledBack)->toBeFalse();

            await($tx->rollback());
            expect($rolledBack)->toBeTrue();
        } finally {
            $client->close();
        }
    });
});

describe('Transaction - Lifecycle & GC', function (): void {

   
    }); it('automatically rolls back and releases connection when the Transaction is garbage collected', function (): void {
        $client = makeClient(['maxConnections' => 1]);

        try {
            await($client->query('CREATE TABLE txn_test (v TEXT)'));

            $runGcTest = function () use ($client): void {
                $tx = await($client->beginTransaction());
                await($tx->execute("INSERT INTO txn_test VALUES ('uncommitted_gc_data')"));
            };

            $runGcTest();

            gc_collect_cycles();
            await(delay(0.1));

            expect($client->stats['active_connections'])->toBe(0);

            $count = await($client->fetchValue('SELECT count(*) FROM txn_test'));
            expect((int)$count)->toBe(0);
        } finally {
            $client->close();
        }
});