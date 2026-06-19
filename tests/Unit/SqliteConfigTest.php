<?php

declare(strict_types=1);

use Hibla\Sqlite\ValueObjects\SqliteConfig;

describe('SqliteConfig', function (): void {

    describe('Direct Constructor', function (): void {
        it('instantiates with correct values and default properties', function (): void {
            $config = new SqliteConfig('/app/database.sqlite');

            expect($config->database)->toBe('/app/database.sqlite')
                ->and($config->busyTimeout)->toBe(5000)
                ->and($config->journalMode)->toBe('WAL')
                ->and($config->foreignKeys)->toBeTrue()
                ->and($config->killWorkerOnCancel)->toBeFalse()
                ->and($config->connectTimeout)->toBe(10)
                ->and($config->forceSync)->toBeFalse()
                ->and($config->resetConnection)->toBeFalse()
                ->and($config->memoryLimitMB)->toBe(128)
            ;
        });

        it('throws an InvalidArgumentException if busyTimeout is negative', function (): void {
            expect(fn () => new SqliteConfig('/app/db.sqlite', -1))
                ->toThrow(InvalidArgumentException::class, 'busyTimeout must be greater than or equal to zero.')
            ;
        });
    });

    describe('fromArray() Parsing', function (): void {
        it('parses a complete array and coeces types correctly', function (): void {
            $config = SqliteConfig::fromArray([
                'database' => '/app/prod.db',
                'busy_timeout' => '3000',
                'journal_mode' => 'DELETE',
                'foreign_keys' => '0',
                'kill_worker_on_cancel' => 1,
                'connect_timeout' => '15',
                'force_sync' => true,
                'reset_connection' => '1',
                'memory_limit_mb' => '64',
            ]);

            expect($config->database)->toBe('/app/prod.db')
                ->and($config->busyTimeout)->toBe(3000)
                ->and($config->journalMode)->toBe('DELETE')
                ->and($config->foreignKeys)->toBeFalse()
                ->and($config->killWorkerOnCancel)->toBeTrue()
                ->and($config->connectTimeout)->toBe(15)
                ->and($config->forceSync)->toBeTrue()
                ->and($config->resetConnection)->toBeTrue()
                ->and($config->memoryLimitMB)->toBe(64)
            ;
        });

        it('throws an InvalidArgumentException if database key is missing from the array', function (): void {
            expect(fn () => SqliteConfig::fromArray([]))
                ->toThrow(InvalidArgumentException::class, 'Database path is required.')
            ;
        });

        it('throws an InvalidArgumentException if database key is not a string', function (): void {
            expect(fn () => SqliteConfig::fromArray(['database' => 12345]))
                ->toThrow(InvalidArgumentException::class, 'Database path must be a string.')
            ;
        });
    });

    describe('fromUri() Parsing (DSN String)', function (): void {
        it('parses a standard SQLite URI correctly', function (): void {
            $uri = 'sqlite:///:memory:?busy_timeout=1000&journal_mode=MEMORY&foreign_keys=false&kill_worker_on_cancel=true&force_sync=true&reset_connection=true&memory_limit_mb=32';
            $config = SqliteConfig::fromUri($uri);

            expect($config->database)->toBe(':memory:')
                ->and($config->busyTimeout)->toBe(1000)
                ->and($config->journalMode)->toBe('MEMORY')
                ->and($config->foreignKeys)->toBeFalse()
                ->and($config->killWorkerOnCancel)->toBeTrue()
                ->and($config->forceSync)->toBeTrue()
                ->and($config->resetConnection)->toBeTrue()
                ->and($config->memoryLimitMB)->toBe(32)
            ;
        });

        it('decodes URL-encoded file paths in the URI correctly', function (): void {
            $uri = 'sqlite:///C%3A%5Cpath%20with%20spaces%5Ctest.db';
            $config = SqliteConfig::fromUri($uri);

            expect($config->database)->toBe('C:\\path with spaces\\test.db');
        });

        it('throws an InvalidArgumentException for completely malformed URIs', function (): void {
            expect(fn () => SqliteConfig::fromUri('sqlite://'))
                ->toThrow(InvalidArgumentException::class, 'Invalid SQLite URI')
            ;
        });
    });
});
