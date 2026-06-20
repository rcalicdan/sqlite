<?php

declare(strict_types=1);

namespace Hibla\Sqlite\Utilities;

use Hibla\Sql\Exceptions\ConnectionException;

/**
 * @internal
 *
 * System environment utilities and helpers for the SQLite async client.
 */
final class SystemHelper
{
    private static ?string $phpBinary = null;

    private static ?string $autoloadPath = null;

    /**
     * Disable instantiation.
     */
    private function __construct()
    {
    }

    /**
     * Detects the path to the active PHP binary executable with cross-platform fallback.
     * Caches the result on the first call.
     */
    public static function getPhpBinary(): string
    {
        if (self::$phpBinary !== null) {
            return self::$phpBinary;
        }

        if (\defined('PHP_BINARY') && \is_executable(PHP_BINARY)) {
            return self::$phpBinary = PHP_BINARY;
        }

        $possiblePaths = [
            'php',
            'php.exe',
            '/usr/bin/php',
            '/usr/local/bin/php',
            '/opt/php/bin/php',
            'C:\\php\\php.exe',
            'C:\\Program Files\\PHP\\php.exe',
        ];

        foreach ($possiblePaths as $path) {
            if (\is_executable($path)) {
                return self::$phpBinary = $path;
            }

            $which = PHP_OS_FAMILY === 'Windows' ? 'where' : 'which';
            $nullDevice = PHP_OS_FAMILY === 'Windows' ? 'nul' : '/dev/null';
            $result = @\shell_exec("{$which} {$path} 2>{$nullDevice}");

            if ($result !== null && \is_string($result) && \trim($result) !== '') {
                $foundPath = \trim($result);
                if (\is_executable($foundPath)) {
                    return self::$phpBinary = $foundPath;
                }
            }
        }

        return self::$phpBinary = 'php';
    }

    /**
     * Traverses upwards from the current folder to reliably find the vendor/autoload.php path.
     * Caches the result after the first lookup to avoid repeating filesystem I/O.
     */
    public static function getAutoloadPath(): string
    {
        if (self::$autoloadPath !== null) {
            return self::$autoloadPath;
        }

        $dir = __DIR__;

        while ($dir !== \dirname($dir)) {
            // standard composer vendor layout (vendor/autoload.php)
            $path = $dir . '/vendor/autoload.php';
            if (\file_exists($path)) {
                $realPath = \realpath($path);

                return self::$autoloadPath = $realPath !== false ? $realPath : $path;
            }

            // running inside a vendor package folder already (upwards is the actual project vendor root)
            $path = $dir . '/autoload.php';
            if (\file_exists($path) && \basename(\dirname($path)) === 'vendor') {
                $realPath = \realpath($path);

                return self::$autoloadPath = $realPath !== false ? $realPath : $path;
            }

            $dir = \dirname($dir);
        }

        throw new \RuntimeException('Failed to locate vendor/autoload.php. Make sure Composer has been run.');
    }

    /**
     * Checks if the current environment supports async background workers.
     * Returns false if proc_open, exec, or shell_exec are disabled.
     */
    public static function isAsyncSupported(): bool
    {
        $requiredFunctions = ['proc_open', 'exec', 'shell_exec'];

        foreach ($requiredFunctions as $function) {
            if (! \function_exists($function)) {
                return false;
            }
        }

        return true;
    }
}
