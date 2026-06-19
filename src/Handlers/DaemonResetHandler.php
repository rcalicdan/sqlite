<?php

declare(strict_types=1);

namespace Hibla\Sqlite\Handlers;

use Hibla\Sqlite\Internals\StateResetter;
use Hibla\Sqlite\ValueObjects\SqliteConfig;

/**
 * Clears session state inside the worker daemon, mimicking PostgreSQL's DISCARD ALL.
 *
 * @internal
 */
final class DaemonResetHandler extends AbstractDaemonHandler
{
    /**
     * @param resource $stdout
     */
    public function __construct(
        \SQLite3 $db,
        mixed $stdout,
        private readonly SqliteConfig $config
    ) {
        parent::__construct($db, $stdout);
    }

    /**
       * @param array<int|string, mixed> $request
      */
    public function handle(array $request): void
    {
        $id = isset($request['id']) && \is_string($request['id']) ? $request['id'] : '';

        StateResetter::execute($this->db, $this->config);

        $this->writeFrame([
            'id' => $id,
            'status' => 'COMPLETED',
        ]);
    }
}
