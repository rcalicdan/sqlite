<?php

declare(strict_types=1);

use Hibla\Sqlite\Handlers\DaemonQueryHandler;

describe('DaemonQueryHandler', function (): void {

    it('writes a COMPLETED frame after running an executive query', function (): void {
        $db = new \SQLite3(':memory:');
        $stdout = fopen('php://memory', 'r+');

        $handler = new DaemonQueryHandler($db, $stdout);

        $handler->handle([
            'id' => 'frame_1',
            'sql' => 'CREATE TABLE users (id INTEGER PRIMARY KEY, name TEXT)',
            'params' => [],
        ]);

        rewind($stdout);
        $output = fgets($stdout);
        $frame = json_decode($output, true);

        expect($frame)->toBeArray()
            ->and($frame['id'])->toBe('frame_1')
            ->and($frame['status'])->toBe('COMPLETED')
            ->and($frame['result']['affectedRows'])->toBe(0)
        ;

        fclose($stdout);
        $db->close();
    });

    it('writes correct affectedRows and lastInsertId upon insert', function (): void {
        $db = new \SQLite3(':memory:');
        $db->exec('CREATE TABLE users (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT)');
        
        $stdout = fopen('php://memory', 'r+');
        $handler = new DaemonQueryHandler($db, $stdout);

        $handler->handle([
            'id' => 'frame_2',
            'sql' => 'INSERT INTO users (name) VALUES (:name)',
            'params' => ['name' => 'Alice'],
        ]);

        rewind($stdout);
        $output = fgets($stdout);
        $frame = json_decode($output, true);

        expect($frame['id'])->toBe('frame_2')
            ->and($frame['status'])->toBe('COMPLETED')
            ->and($frame['result']['affectedRows'])->toBe(1)
            ->and($frame['result']['lastInsertId'])->toBe(1)
        ;

        fclose($stdout);
        $db->close();
    });

    it('returns rows and parses basic types correctly on select', function () {
        $db = new \SQLite3(':memory:');
        $db->exec('CREATE TABLE users (id INTEGER PRIMARY KEY, age INTEGER, salary REAL)');
        $db->exec('INSERT INTO users VALUES (1, 30, 1500.50)');

        $stdout = fopen('php://memory', 'r+');
        $handler = new DaemonQueryHandler($db, $stdout);

        $handler->handle([
            'id' => 'frame_3',
            'sql' => 'SELECT * FROM users WHERE id = :id',
            'params' => ['id' => 1],
        ]);

        rewind($stdout);
        $output = fgets($stdout);
        $frame = json_decode($output, true);

        expect($frame['status'])->toBe('COMPLETED');
        
        $rows = $frame['result']['rows'];
        expect($rows)->toHaveCount(1)
            ->and($rows[0]['id'])->toBe(1)
            ->and($rows[0]['age'])->toBe(30)
            ->and($rows[0]['salary'])->toBe(1500.50)
        ;

        fclose($stdout);
        $db->close();
    });

   it('throws an exception on invalid SQL statements (daemon loop catches this)', function (): void {
        $db = new \SQLite3(':memory:');
        $db->enableExceptions(true);

        $stdout = fopen('php://memory', 'r+');
        $handler = new DaemonQueryHandler($db, $stdout);

        expect(fn () => $handler->handle([
            'id' => 'frame_error',
            'sql' => 'NOT VALID SQL !!!',
            'params' => [],
        ]))->toThrow(Exception::class);

        fclose($stdout);
        $db->close();
    });
});