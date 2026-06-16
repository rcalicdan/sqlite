<?php

declare(strict_types=1);

require __DIR__ . '/vendor/autoload.php';

use Hibla\Sqlite\Internals\Connection;
use Hibla\Sqlite\ValueObjects\SqliteConfig;

use function Hibla\await;

@unlink(__DIR__ . '/trace.log');

ini_set('memory_limit', '32M');

function memPeak(): string {
    return number_format(memory_get_peak_usage(false) / 1024 / 1024, 2) . ' MB';
}

echo "=================================================\n";
echo "   SQLite IPC Streaming & Backpressure Test\n";
echo "=================================================\n";
echo "Parent Memory limit : 32M (Will fatal if backpressure fails)\n";
echo "Target Row Count    : 500,000\n";
echo "Buffer Size         : 50\n";
echo "-------------------------------------------------\n";

$dbId = uniqid();
$dbFile = __DIR__ . "/stream_test_{$dbId}.sqlite";

try {
    $config = new SqliteConfig(database: $dbFile);
    $connection = new Connection($config);

    echo "⏳ Spawning raw SQLite worker daemon...\n";
    await($connection->connect());
    
    $baseMem = memory_get_usage(false) / 1024 / 1024;
    echo "✅ Worker connected. Baseline Used Memory: " . number_format($baseMem, 2) . " MB\n\n";

    echo "⏳ Initiating 500,000 row stream from worker...\n";
    
    $start = microtime(true);
    
    // We lowered the buffer back down to 50 to keep the SplQueue tiny!
    $stream = await($connection->streamQuery("
        WITH RECURSIVE cnt(x) AS (
            SELECT 1 
            UNION ALL 
            SELECT x+1 FROM cnt LIMIT 500000
        ) 
        SELECT x, 'user_name_' || x AS name, hex(randomblob(50)) AS payload FROM cnt;
    ", bufferSize: 50));

    $count = 0;
    $memSamples = [];

    foreach ($stream as $row) {
        $count++;

        if ($count % 10000 === 0) {
            // true = Reserved by OS, false = Actually used by PHP variables
            $reservedMem = memory_get_usage(true) / 1024 / 1024;
            $usedMem = memory_get_usage(false) / 1024 / 1024;
            
            $memSamples[] = $usedMem;
            
            printf(
                "  Received %s rows | Used Mem: %.2f MB | Reserved Mem: %.2f MB\n",
                str_pad(number_format($count), 7, ' ', STR_PAD_LEFT),
                $usedMem,
                $reservedMem
            );
        }
    }

    echo "\n=================================================\n";
    echo "✅ STREAM COMPLETE\n";
    echo "-------------------------------------------------\n";
    echo "Total Rows Streamed : " . number_format($count) . "\n";
    echo "Time Taken          : " . number_format(microtime(true) - $start, 2) . "s\n";
    echo "Final Peak Used Mem : " . memPeak() . "\n";

    $max = max($memSamples);
    
    if ($max < 6.0) {
        echo "✅ PASS: Memory footprint is ultra-lean. Plateaus at {$max} MB!\n";
    } else {
        echo "❌ FAIL: Used Memory exceeded safe limits ({$max} MB).\n";
    }

} catch (\Throwable $e) {
    echo "\n❌ FATAL ERROR: " . $e->getMessage() . "\n";
    echo $e->getTraceAsString() . "\n";
} finally {
    echo "\n⏳ Tearing down worker...\n";
    if (isset($connection)) {
        $connection->close(true);
    }
    foreach (['', '-wal', '-shm'] as $ext) {
        $file = $dbFile . $ext;
        if (file_exists($file)) @unlink($file);
    }
    echo "🧹 Cleanup complete.\n";
}