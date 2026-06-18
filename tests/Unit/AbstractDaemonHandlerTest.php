<?php

declare(strict_types=1);

use Hibla\Sqlite\Handlers\AbstractDaemonHandler;

describe('AbstractDaemonHandler', function (): void {

    it('writes a JSON encoded frame to stdout with a trailing newline', function (): void {
        $db = new SQLite3(':memory:');
        $stdout = fopen('php://memory', 'r+');

        $handler = new class ($db, $stdout) extends AbstractDaemonHandler {
            public function testWriteFrame(array $data): void
            {
                $this->writeFrame($data);
            }
        };

        $handler->testWriteFrame(['id' => '123', 'status' => 'COMPLETED']);

        rewind($stdout);
        $output = fgets($stdout);
        $data = json_decode($output, true);

        expect($data)->toBeArray()
            ->and($data['id'])->toBe('123')
            ->and($data['status'])->toBe('COMPLETED')
            ->and(str_ends_with($output, "\n"))->toBeTrue()
        ;

        fclose($stdout);
        $db->close();
    });

    it('catches JsonExceptions and writes an ERROR frame when encoding fails', function (): void {
        $db = new SQLite3(':memory:');
        $stdout = fopen('php://memory', 'r+');

        $handler = new class ($db, $stdout) extends AbstractDaemonHandler {
            public function testWriteFrame(array $data): void
            {
                $this->writeFrame($data);
            }
        };

        $handler->testWriteFrame([
            'id' => 'err_test',
            'value' => NAN,
        ]);

        rewind($stdout);
        $output = fgets($stdout);
        $data = json_decode($output, true);

        expect($data)->toBeArray()
            ->and($data['id'])->toBe('err_test')
            ->and($data['status'])->toBe('ERROR')
            ->and($data['errorMessage'])->toContain('JSON Encoding Error')
        ;

        fclose($stdout);
        $db->close();
    });

    it('binds parameters with correct SQLITE3 data types', function (): void {
        $db = new SQLite3(':memory:');
        $db->enableExceptions(true);

        $stdout = fopen('php://memory', 'r+');

        $handler = new class ($db, $stdout) extends AbstractDaemonHandler {
            public function testBindParams(SQLite3Stmt $stmt, array $params): void
            {
                $this->bindParams($stmt, $params);
            }
        };

        $stmt = $db->prepare('SELECT :int AS i, :float AS f, :null AS n, :bool AS b, :text AS t');
        expect($stmt)->not->toBeFalse();

        $handler->testBindParams($stmt, [
            'int' => 100,
            'float' => 3.14,
            'null' => null,
            'bool' => true,
            'text' => 'hello',
        ]);

        $result = $stmt->execute();
        $row = $result->fetchArray(SQLITE3_ASSOC);

        expect($row['i'])->toBe(100)
            ->and($row['f'])->toBe(3.14)
            ->and($row['n'])->toBeNull()
            ->and($row['b'])->toBe(1)
            ->and($row['t'])->toBe('hello')
        ;

        $result->finalize();
        $stmt->close();
        fclose($stdout);
        $db->close();
    });

    it('correctly maps 0-indexed positional parameters to 1-based SQLite parameters', function (): void {
        $db = new SQLite3(':memory:');
        $db->enableExceptions(true);

        $stdout = fopen('php://memory', 'r+');

        $handler = new class ($db, $stdout) extends AbstractDaemonHandler {
            public function testBindParams(SQLite3Stmt $stmt, array $params): void
            {
                $this->bindParams($stmt, $params);
            }
        };

        $stmt = $db->prepare('SELECT ? AS first, ? AS second');
        expect($stmt)->not->toBeFalse();

        $handler->testBindParams($stmt, [
            0 => 'first_value',
            1 => 'second_value',
        ]);

        $result = $stmt->execute();
        $row = $result->fetchArray(SQLITE3_ASSOC);

        expect($row['first'])->toBe('first_value')
            ->and($row['second'])->toBe('second_value')
        ;

        $result->finalize();
        $stmt->close();
        fclose($stdout);
        $db->close();
    });
});
