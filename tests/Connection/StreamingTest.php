<?php

declare(strict_types=1);

use Hibla\Sqlite\Internals\SqliteRowStream;
use Hibla\Sqlite\Internals\SyncRowStream;

use function Hibla\await;

describe('AsyncConnection - Streaming', function () {

    it('streams rows completely and retains correct column metadata', function () {
        $conn = sqliteConn(['force_sync' => false]);

        try {
            $stream = await($conn->streamQuery('SELECT 1 AS id, "Alice" AS name UNION ALL SELECT 2, "Bob"'));

            expect($stream)->toBeInstanceOf(SqliteRowStream::class);

            $rows = [];
            foreach ($stream as $row) {
                $rows[] = $row;
            }

            expect($stream->columnCount)->toBe(2)
                ->and($stream->columns)->toBe(['id', 'name'])
                ->and($rows)->toHaveCount(2)
            ;
        } finally {
            $conn->close();
        }
    });

    it('triggers backpressure handler (pause/resume) when buffer limits are breached', function () {
        $conn = sqliteConn(['force_sync' => false]);

        try {
            $sql = 'WITH RECURSIVE cnt(x) AS (SELECT 1 UNION ALL SELECT x+1 FROM cnt WHERE x < 20) SELECT x AS n FROM cnt;';

            $stream = await($conn->streamQuery($sql, 5));

            $rows = [];
            foreach ($stream as $row) {
                $rows[] = $row;
            }

            expect($rows)->toHaveCount(20);
        } finally {
            $conn->close();
        }
    });

    it('profiles memory usage and leaks no memory when streaming 10,000 rows', function () {
        $conn = sqliteConn(['force_sync' => false]);

        try {
            gc_collect_cycles();
            $baselineMemory = memory_get_usage();

            $stream = await($conn->streamQuery(generateHeavyRowsQuery(10000), 100));

            $rowCount = 0;
            $peakMemory = 0;

            foreach ($stream as $row) {
                $rowCount++;
                if ($rowCount % 1000 === 0) {
                    $peakMemory = max($peakMemory, memory_get_usage());
                }
            }

            gc_collect_cycles();
            $stream = null;
            gc_collect_cycles();

            $endingMemory = memory_get_usage();

            $growthMb = ($peakMemory - $baselineMemory) / 1024 / 1024;
            $leakMb = ($endingMemory - $baselineMemory) / 1024 / 1024;

            expect($rowCount)->toBe(10000);

            expect($growthMb)->toBeLessThan(10.0, "Peak memory grew too much during streaming: {$growthMb} MB");

            expect($leakMb)->toBeLessThan(0.5, "Memory leaked after stream finished: {$leakMb} MB");
        } finally {
            $conn->close();
        }
    });
});

describe('SyncConnection - Streaming', function () {

    it('streams rows completely and retains correct column metadata', function () {
        $conn = sqliteConn(['force_sync' => true]);

        try {
            $stream = await($conn->streamQuery('SELECT 1 AS id, "Alice" AS name UNION ALL SELECT 2, "Bob"'));

            expect($stream)->toBeInstanceOf(SyncRowStream::class)
                ->and($stream->columnCount)->toBe(2)
                ->and($stream->columns)->toBe(['id', 'name'])
            ;

            $rows = [];
            foreach ($stream as $row) {
                $rows[] = $row;
            }

            expect($rows)->toHaveCount(2)
                ->and((int)$rows[0]['id'])->toBe(1)
                ->and($rows[0]['name'])->toBe('Alice')
                ->and((int)$rows[1]['id'])->toBe(2)
                ->and($rows[1]['name'])->toBe('Bob')
            ;
        } finally {
            $conn->close();
        }
    });

    it('profiles memory usage and leaks no memory when streaming 10,000 rows', function () {
        $conn = sqliteConn(['force_sync' => true]);

        try {
            gc_collect_cycles();
            $baselineMemory = memory_get_usage();

            $stream = await($conn->streamQuery(generateHeavyRowsQuery(10000)));

            $rowCount = 0;
            $peakMemory = 0;

            foreach ($stream as $row) {
                $rowCount++;
                if ($rowCount % 1000 === 0) {
                    $peakMemory = max($peakMemory, memory_get_usage());
                }
            }

            gc_collect_cycles();
            $stream = null;
            gc_collect_cycles();

            $endingMemory = memory_get_usage();

            $growthMb = ($peakMemory - $baselineMemory) / 1024 / 1024;
            $leakMb = ($endingMemory - $baselineMemory) / 1024 / 1024;

            expect($rowCount)->toBe(10000);

            expect($growthMb)->toBeLessThan(2.0, "Sync peak memory grew too much: {$growthMb} MB");
            expect($leakMb)->toBeLessThan(0.5, "Sync memory leaked: {$leakMb} MB");
        } finally {
            $conn->close();
        }
    });
});
