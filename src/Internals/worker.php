<?php

declare(strict_types=1);

set_time_limit(0);

$autoloadPath = $argv[1] ?? '';
$configBase64 = $argv[2] ?? '';

/**
 * Register a shutown function ensuring socket descriptor are not destroyed prematurely during process exit
 */
register_shutdown_function(function () {
    $error = error_get_last();

    if ($error !== null && \in_array($error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
        $payload = \json_encode([
            'id' => 'fatal',
            'status' => 'ERROR',
            'errorCode' => $error['type'],
            'errorMessage' => 'Fatal Worker Crash: ' . $error['message'] . ' in ' . $error['file'] . ':' . $error['line'],
        ], JSON_UNESCAPED_SLASHES) . "\n";

        @fwrite(STDOUT, $payload);
        @fflush(STDOUT);
    }

    if (PHP_OS_FAMILY === 'Windows') {
        @fflush(STDOUT);
        @stream_set_blocking(STDIN, false);

        $drainStart = hrtime(true);
        while ((hrtime(true) - $drainStart) < 500_000_000) {
            $chunk = @fread(STDIN, 1);
            if ($chunk === false || feof(STDIN)) {
                break;
            }
            usleep(5000);
        }
    }
});

if ($autoloadPath === '' || ! file_exists($autoloadPath)) {
    fwrite(STDERR, "FATAL: Autoloader not found at path: {$autoloadPath}\n");
    exit(1);
}

require_once $autoloadPath;

$config = unserialize(base64_decode($configBase64));
if (! $config instanceof Hibla\Sqlite\ValueObjects\SqliteConfig) {
    fwrite(STDERR, "FATAL: Invalid configuration payload.\n");
    exit(1);
}

// Boot the Daemon and enter the infinite RPC loop
$daemon = new Hibla\Sqlite\Internals\SqliteWorkerDaemon($config);
$daemon();
