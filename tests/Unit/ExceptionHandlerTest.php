<?php

declare(strict_types=1);

use Hibla\Sql\Exceptions\ConstraintViolationException;
use Hibla\Sql\Exceptions\QueryException;
use Hibla\Sqlite\Utilities\ExceptionHandler;

describe('ExceptionHandler', function (): void {

    it('creates a generic RuntimeException when given an empty array', function (): void {
        $exception = ExceptionHandler::createFromWorkerError([]);

        expect($exception)->toBeInstanceOf(RuntimeException::class)
            ->and($exception->getMessage())->toBe('Unknown worker error')
            ->and($exception->getCode())->toBe(0)
            ->and($exception->getFile())->toBe('unknown')
            ->and($exception->getLine())->toBe(0)
        ;
    });

    it('routes SQLite3Exception through the ExceptionMapper automatically', function (): void {
        $errorData = [
            'class' => 'SQLite3Exception',
            'message' => 'UNIQUE constraint failed',
            'code' => 19,
        ];

        $exception = ExceptionHandler::createFromWorkerError($errorData);

        expect($exception)->toBeInstanceOf(ConstraintViolationException::class)
            ->and($exception->getMessage())->toBe('UNIQUE constraint failed')
            ->and($exception->getCode())->toBe(19)
        ;
    });

    it('routes RuntimeException through the ExceptionMapper automatically', function (): void {
        $errorData = [
            'class' => RuntimeException::class,
            'message' => 'General SQL error',
            'code' => 1, // SQLITE_ERROR -> QueryException
        ];

        $exception = ExceptionHandler::createFromWorkerError($errorData);

        expect($exception)->toBeInstanceOf(QueryException::class)
            ->and($exception->getMessage())->toBe('General SQL error')
            ->and($exception->getCode())->toBe(1)
        ;
    });

    it('overrides the file and line properties using Reflection', function (): void {
        $errorData = [
            'class' => LogicException::class,
            'message' => 'Something went wrong',
            'code' => 400,
            'file' => '/app/src/Internals/Worker.php',
            'line' => 123,
        ];

        $exception = ExceptionHandler::createFromWorkerError($errorData);

        expect($exception)->toBeInstanceOf(LogicException::class)
            ->and($exception->getFile())->toBe('/app/src/Internals/Worker.php')
            ->and($exception->getLine())->toBe(123)
        ;
    });

    it('falls back to RuntimeException if the requested class does not exist', function (): void {
        $errorData = [
            'class' => 'Some\\Non\\Existent\\ExceptionClass',
            'message' => 'Failed to load',
            'code' => 500,
        ];

        $exception = ExceptionHandler::createFromWorkerError($errorData);

        expect($exception)->toBeInstanceOf(RuntimeException::class)
            ->and($exception->getMessage())->toBe('Failed to load')
            ->and($exception->getCode())->toBe(500)
        ;
    });

    it('falls back to RuntimeException if the requested class is not a Throwable', function (): void {
        $errorData = [
            'class' => stdClass::class,
            'message' => 'I am an object, not an exception',
            'code' => 500,
        ];

        $exception = ExceptionHandler::createFromWorkerError($errorData);

        expect($exception)->toBeInstanceOf(RuntimeException::class)
            ->and($exception->getMessage())->toBe('I am an object, not an exception')
        ;
    });

    describe('Worker Stack Trace Parsing', function (): void {
        it('appends a valid worker stack trace to the exception trace', function (): void {
            $rawWorkerTrace = <<<TRACE
#0 /app/src/Handlers/DaemonQueryHandler.php(45): SQLite3->prepare()
#1 /app/src/Internals/SqliteWorkerDaemon.php: Hibla\Sqlite\Handlers\DaemonQueryHandler->handle()
#2 {main}
TRACE;

            $errorData = [
                'class' => RuntimeException::class,
                'stack_trace' => $rawWorkerTrace,
            ];

            $exception = ExceptionHandler::createFromWorkerError($errorData);
            $trace = $exception->getTrace();

            // Locate the boundary marker
            $boundaryIndex = -1;
            foreach ($trace as $index => $frame) {
                if (($frame['file'] ?? '') === '--- WORKER STACK TRACE ---') {
                    $boundaryIndex = $index;

                    break;
                }
            }

            expect($boundaryIndex)->toBeGreaterThanOrEqual(0);

            // Assert the frames after the boundary
            $workerFrame1 = $trace[$boundaryIndex + 1];
            expect($workerFrame1['file'])->toBe('/app/src/Handlers/DaemonQueryHandler.php')
                ->and($workerFrame1['line'])->toBe(45)
                ->and($workerFrame1['function'])->toBe('SQLite3->prepare()')
            ;

            $workerFrame2 = $trace[$boundaryIndex + 2];
            expect($workerFrame2['file'])->toBe('/app/src/Internals/SqliteWorkerDaemon.php')
                ->and($workerFrame2['line'])->toBe(0) // No line number provided in string
                ->and($workerFrame2['function'])->toBe('Hibla\Sqlite\Handlers\DaemonQueryHandler->handle()')
            ;

            $workerFrame3 = $trace[$boundaryIndex + 3];
            expect($workerFrame3['file'])->toBe('[worker main]')
                ->and($workerFrame3['function'])->toBe('{main}')
            ;
        });

        it('ignores malformed or unrecognizable stack trace strings safely', function (): void {
            $errorData = [
                'class' => RuntimeException::class,
                'stack_trace' => "Just a random string\nNot a real trace format",
            ];

            $exception = ExceptionHandler::createFromWorkerError($errorData);
            $trace = $exception->getTrace();

            // Boundary should not exist if no valid frames were parsed
            $hasBoundary = false;
            foreach ($trace as $frame) {
                if (($frame['file'] ?? '') === '--- WORKER STACK TRACE ---') {
                    $hasBoundary = true;
                }
            }

            expect($hasBoundary)->toBeFalse();
        });

        it('safely handles an empty stack trace string', function (): void {
            $errorData = [
                'class' => RuntimeException::class,
                'stack_trace' => '',
            ];

            $exception = ExceptionHandler::createFromWorkerError($errorData);
            $trace = $exception->getTrace();

            $hasBoundary = false;
            foreach ($trace as $frame) {
                if (($frame['file'] ?? '') === '--- WORKER STACK TRACE ---') {
                    $hasBoundary = true;
                }
            }

            expect($hasBoundary)->toBeFalse();
        });
    });

    describe('Numeric Code Edge Cases', function (): void {
        it('safely handles string-based numeric codes', function (): void {
            $errorData = [
                'code' => '19',
                'class' => 'SQLite3Exception',
            ];

            $exception = ExceptionHandler::createFromWorkerError($errorData);
            expect($exception->getCode())->toBe(19)
                ->and($exception)->toBeInstanceOf(ConstraintViolationException::class)
            ;
        });

        it('defaults to 0 for non-numeric string codes', function (): void {
            $errorData = [
                'code' => 'ABC',
                'class' => RuntimeException::class,
            ];

            $exception = ExceptionHandler::createFromWorkerError($errorData);
            expect($exception->getCode())->toBe(0);
        });
    });

});
