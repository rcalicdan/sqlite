<?php

declare(strict_types=1);

namespace Hibla\Sqlite\Internals;

use Hibla\Sqlite\ValueObjects\SqliteConfig;

/**
 * Shared utility to cleanly wipe session state on a raw SQLite3 instance.
 * Equivalent to PostgreSQL's DISCARD ALL.
 *
 * @internal
 */
final class StateResetter
{
    /**
     * Executes the comprehensive reset sequence on the provided database connection.
     */
    public static function execute(\SQLite3 $db, SqliteConfig $config): void
    {
        // Abort any hanging transactions
        @$db->exec('ROLLBACK');

        // Drop all Temporary Tables and Views
        $drops = [];
        $tempSchema = $db->query("SELECT name, type FROM sqlite_temp_master WHERE type IN ('table', 'view') AND name NOT LIKE 'sqlite_%'");

        if ($tempSchema !== false) {
            while ($row = $tempSchema->fetchArray(SQLITE3_ASSOC)) {
                $type = strtoupper($row['type']);
                $name = $row['name'];
                $drops[] = "DROP {$type} temp.\"{$name}\"";
            }
            $tempSchema->finalize();
        }

        foreach ($drops as $drop) {
            @$db->exec($drop);
        }

        // Restore Config PRAGMAs
        $db->busyTimeout($config->busyTimeout);
        $fkFlag = $config->foreignKeys ? 'ON' : 'OFF';
        $db->exec("PRAGMA foreign_keys = {$fkFlag}");

        // Reset Dangerous Session-Scoped PRAGMAs
        $db->exec('PRAGMA defer_foreign_keys = OFF');
        $db->exec('PRAGMA read_uncommitted = OFF');
        $db->exec('PRAGMA query_only = OFF');
        $db->exec('PRAGMA case_sensitive_like = OFF');
        $db->exec('PRAGMA ignore_check_constraints = OFF');

        // Reclaim Memory
        $db->exec('PRAGMA shrink_memory');
    }
}
