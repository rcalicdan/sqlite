<?php

declare(strict_types=1);

namespace Hibla\Sqlite\Internals;

use Hibla\Promise\Interfaces\PromiseInterface;
use Hibla\Sqlite\Interfaces\ConnectionInterface;
use Hibla\Sqlite\Utilities\SystemHelper;
use Hibla\Sqlite\ValueObjects\SqliteConfig;

/**
 * @internal
 */
final class ConnectionFactory
{
    /**
     * Creates the optimal connection type based on environment capabilities.
     * 
     * @param SqliteConfig|array<string, mixed>|string $config
     * @return PromiseInterface<ConnectionInterface>
     */
    public static function create(SqliteConfig|array|string $config): PromiseInterface
    {
        $configObj = match (true) {
            $config instanceof SqliteConfig => $config,
            \is_array($config) => SqliteConfig::fromArray($config),
            \is_string($config) => SqliteConfig::fromUri($config),
        };

        if ($configObj->forceSync || !SystemHelper::isAsyncSupported()) {
            $conn = new SyncConnection($configObj);
        } else {
            $conn = new AsyncConnection($configObj);
        }

        return $conn->connect();
    }
}