<?php
declare(strict_types=1);

namespace WpSync\Snapshot;

/**
 * SQL-trigger-based dirty-row tracking. On each tracked table, INSERT/UPDATE/DELETE
 * populates _sync_dirty(tbl, rowid, op) with the last op per (tbl, rowid).
 *
 * During materialization we DROP triggers (via suspend()) and recreate them
 * (resume()) so that applied writes don't fill the dirty log with noise.
 */
final class DirtyTracker
{
    public const DIRTY_TABLE = '_sync_dirty';

    public static function install(\SQLite3 $db, array $tables): void
    {
        $db->exec(<<<SQL
            CREATE TABLE IF NOT EXISTS _sync_dirty (
                tbl   TEXT    NOT NULL,
                rowid INTEGER NOT NULL,
                op    TEXT    NOT NULL,
                PRIMARY KEY (tbl, rowid)
            )
        SQL);
        foreach ($tables as $t) {
            self::createTriggers($db, $t);
        }
    }

    public static function createTriggers(\SQLite3 $db, string $table): void
    {
        $q = SchemaInspector::quoteIdent($table);
        $n = addslashes($table);
        $iName = self::trigName($table, 'i');
        $uName = self::trigName($table, 'u');
        $dName = self::trigName($table, 'd');
        $db->exec("DROP TRIGGER IF EXISTS {$iName}");
        $db->exec("DROP TRIGGER IF EXISTS {$uName}");
        $db->exec("DROP TRIGGER IF EXISTS {$dName}");
        $db->exec("CREATE TRIGGER {$iName} AFTER INSERT ON {$q} BEGIN
            INSERT OR REPLACE INTO _sync_dirty(tbl,rowid,op) VALUES('{$n}', NEW.rowid, 'I');
        END");
        $db->exec("CREATE TRIGGER {$uName} AFTER UPDATE ON {$q} BEGIN
            INSERT OR REPLACE INTO _sync_dirty(tbl,rowid,op) VALUES('{$n}', NEW.rowid, 'U');
        END");
        $db->exec("CREATE TRIGGER {$dName} AFTER DELETE ON {$q} BEGIN
            INSERT OR REPLACE INTO _sync_dirty(tbl,rowid,op) VALUES('{$n}', OLD.rowid, 'D');
        END");
    }

    public static function suspend(\SQLite3 $db, array $tables): void
    {
        foreach ($tables as $t) {
            $db->exec('DROP TRIGGER IF EXISTS ' . self::trigName($t, 'i'));
            $db->exec('DROP TRIGGER IF EXISTS ' . self::trigName($t, 'u'));
            $db->exec('DROP TRIGGER IF EXISTS ' . self::trigName($t, 'd'));
        }
    }

    public static function resume(\SQLite3 $db, array $tables): void
    {
        foreach ($tables as $t) {
            self::createTriggers($db, $t);
        }
    }

    public static function dirtyTables(\SQLite3 $db): array
    {
        $res = $db->query('SELECT DISTINCT tbl FROM _sync_dirty');
        if (!$res) return [];
        $out = [];
        while ($row = $res->fetchArray(SQLITE3_ASSOC)) {
            $out[] = $row['tbl'];
        }
        return $out;
    }

    public static function clear(\SQLite3 $db): void
    {
        $db->exec('DELETE FROM ' . self::DIRTY_TABLE);
    }

    public static function isInstalled(\SQLite3 $db): bool
    {
        $res = $db->query("SELECT name FROM sqlite_master WHERE type='table' AND name='_sync_dirty'");
        return (bool)($res && $res->fetchArray(SQLITE3_ASSOC));
    }

    private static function trigName(string $table, string $suffix): string
    {
        // Trigger names must be safe identifiers.
        if (!preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $table)) {
            throw new \InvalidArgumentException("bad table name: {$table}");
        }
        return "_sync_trk_{$table}_{$suffix}";
    }
}
