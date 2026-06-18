<?php

declare(strict_types=1);

use Hibla\Sql\Exceptions\ConstraintViolationException;
use Hibla\Sql\Exceptions\DeadlockException;
use Hibla\Sql\Exceptions\LockWaitTimeoutException;
use Hibla\Sql\Exceptions\QueryException;
use Hibla\Sqlite\Utilities\ExceptionMapper;

describe('ExceptionMapper', function (): void {

    it('maps SQLITE_CONSTRAINT (19) to ConstraintViolationException', function (): void {
        $exception = ExceptionMapper::map(19, 'UNIQUE constraint failed: users.email');

        expect($exception)->toBeInstanceOf(ConstraintViolationException::class)
            ->and($exception->getCode())->toBe(19)
            ->and($exception->getMessage())->toBe('UNIQUE constraint failed: users.email')
        ;
    });

    it('maps SQLITE_LOCKED (6) to DeadlockException', function (): void {
        $exception = ExceptionMapper::map(6, 'database table is locked');

        expect($exception)->toBeInstanceOf(DeadlockException::class)
            ->and($exception->getCode())->toBe(6)
            ->and($exception->getMessage())->toBe('database table is locked')
        ;
    });

    it('maps SQLITE_BUSY_SNAPSHOT (517) to DeadlockException', function (): void {
        $exception = ExceptionMapper::map(517, 'database is locked (snapshot)');

        expect($exception)->toBeInstanceOf(DeadlockException::class)
            ->and($exception->getCode())->toBe(517)
            ->and($exception->getMessage())->toBe('database is locked (snapshot)')
        ;
    });

    it('maps SQLITE_LOCKED_SHAREDCACHE (1542) to DeadlockException', function (): void {
        $exception = ExceptionMapper::map(1542, 'database is locked (shared cache)');

        expect($exception)->toBeInstanceOf(DeadlockException::class)
            ->and($exception->getCode())->toBe(1542)
            ->and($exception->getMessage())->toBe('database is locked (shared cache)')
        ;
    });

    it('maps SQLITE_BUSY (5) to LockWaitTimeoutException', function (): void {
        $exception = ExceptionMapper::map(5, 'database is locked');

        expect($exception)->toBeInstanceOf(LockWaitTimeoutException::class)
            ->and($exception->getCode())->toBe(5)
            ->and($exception->getMessage())->toBe('database is locked')
        ;
    });

    it('maps unknown or general SQLite codes to QueryException', function (): void {
        $exception1 = ExceptionMapper::map(1, 'SQL logic error');
        expect($exception1)->toBeInstanceOf(QueryException::class)
            ->and($exception1->getCode())->toBe(1)
            ->and($exception1->getMessage())->toBe('SQL logic error')
        ;

        $exception14 = ExceptionMapper::map(14, 'unable to open database file');
        expect($exception14)->toBeInstanceOf(QueryException::class)
            ->and($exception14->getCode())->toBe(14)
            ->and($exception14->getMessage())->toBe('unable to open database file')
        ;

        $exception999 = ExceptionMapper::map(999, 'Some future error');
        expect($exception999)->toBeInstanceOf(QueryException::class)
            ->and($exception999->getCode())->toBe(999)
        ;
    });

});
