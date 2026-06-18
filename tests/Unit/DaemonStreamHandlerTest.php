<?php

declare(strict_types=1);

use Hibla\Sqlite\Handlers\DaemonStreamHandler;

describe('DaemonStreamHandler', function (): void {

    it('writes separate ROW frames for each selected row and ends with a COMPLETED frame', function (): void {
        $db = new SQLite3(':memory:');
        $db->exec('CREATE TABLE items (val TEXT)');
        $db->exec("INSERT INTO items VALUES ('A'), ('B'), ('C')");

        $stdout = fopen('php://memory', 'r+');
        $handler = new DaemonStreamHandler($db, $stdout);

        $handler->handle([
            'id' => 'stream_frame_1',
            'sql' => 'SELECT val FROM items',
            'params' => [],
        ]);

        rewind($stdout);

        $frames = [];
        while (($line = fgets($stdout)) !== false) {
            $frames[] = json_decode($line, true);
        }

        expect($frames)->toHaveCount(4);

        expect($frames[0]['status'])->toBe('ROW')
            ->and($frames[0]['row']['val'])->toBe('A')
            ->and($frames[0]['id'])->toBe('stream_frame_1')
        ;

        expect($frames[1]['status'])->toBe('ROW')
            ->and($frames[1]['row']['val'])->toBe('B')
        ;

        expect($frames[2]['status'])->toBe('ROW')
            ->and($frames[2]['row']['val'])->toBe('C')
        ;
        expect($frames[3]['status'])->toBe('COMPLETED')
            ->and($frames[3]['id'])->toBe('stream_frame_1')
        ;

        fclose($stdout);
        $db->close();
    });

    it('binds parameters correctly during streaming queries', function (): void {
        $db = new SQLite3(':memory:');
        $db->exec('CREATE TABLE items (id INTEGER, val TEXT)');
        $db->exec("INSERT INTO items VALUES (1, 'A'), (2, 'B'), (3, 'C')");

        $stdout = fopen('php://memory', 'r+');
        $handler = new DaemonStreamHandler($db, $stdout);

        $handler->handle([
            'id' => 'stream_frame_2',
            'sql' => 'SELECT val FROM items WHERE id > :id',
            'params' => ['id' => 1],
        ]);

        rewind($stdout);

        $frames = [];
        while (($line = fgets($stdout)) !== false) {
            $frames[] = json_decode($line, true);
        }

        expect($frames)->toHaveCount(3)
            ->and($frames[0]['row']['val'])->toBe('B')
            ->and($frames[1]['row']['val'])->toBe('C')
            ->and($frames[2]['status'])->toBe('COMPLETED')
        ;

        fclose($stdout);
        $db->close();
    });
});
