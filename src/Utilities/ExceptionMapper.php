<?php

declare(strict_types=1);

namespace Hibla\Sqlite\Utilities;

use Hibla\Sql\Exceptions\ConstraintViolationException;
use Hibla\Sql\Exceptions\LockWaitTimeoutException;
use Hibla\Sql\Exceptions\QueryException;

/**
 * @internal
 */
final class ExceptionMapper
{
    /**
     * Maps SQLite error codes to standard Hibla SQL Exceptions.
     */
    public static function map(int $code, string $message): \Throwable
    {
        return match ($code) {
            19 => new ConstraintViolationException($message, $code),
            5 => new LockWaitTimeoutException($message, $code),
            default => new QueryException($message, $code),
        };
    }
}
