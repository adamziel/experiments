<?php
declare(strict_types=1);

namespace WpSync\Snapshot;

use WpSync\Encoding\CanonicalEncoder;

final class SchemaInspector
{
    /**
     * @return array{pk:string[], columns:string[], schemaHash:string}
     */
    public static function inspect(\SQLite3 $db, string $table): array
    {
        $info = [];
        $res = $db->query('PRAGMA table_info(' . self::quoteIdent($table) . ')');
        while ($row = $res->fetchArray(SQLITE3_ASSOC)) {
            $info[] = $row;
        }
        if (!$info) {
            throw new \RuntimeException("Table not found: {$table}");
        }
        $pk = [];
        $cols = [];
        $pkInfo = [];
        foreach ($info as $c) {
            $cols[] = $c['name'];
            if ((int)$c['pk'] > 0) {
                $pkInfo[(int)$c['pk']] = $c['name'];
            }
        }
        ksort($pkInfo);
        $pk = array_values($pkInfo);

        // Canonical CREATE TABLE for schema hash: use sqlite_master.
        $stmt = $db->prepare("SELECT sql FROM sqlite_master WHERE type='table' AND name = ?");
        $stmt->bindValue(1, $table, SQLITE3_TEXT);
        $r = $stmt->execute();
        $sql = (string)($r->fetchArray(SQLITE3_ASSOC)['sql'] ?? '');
        $canonical = self::canonicalizeCreateTable($sql);
        $schemaHash = CanonicalEncoder::hashBytes($canonical);

        return ['pk' => $pk, 'columns' => $cols, 'schemaHash' => $schemaHash];
    }

    public static function canonicalizeCreateTable(string $sql): string
    {
        // Collapse whitespace, strip comments, uppercase keywords. Good enough
        // to detect meaningful schema changes while ignoring cosmetic diffs.
        $sql = preg_replace('/--[^\n]*/', '', $sql) ?? $sql;
        $sql = preg_replace('/\s+/', ' ', $sql) ?? $sql;
        return trim($sql);
    }

    public static function quoteIdent(string $name): string
    {
        if (!preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $name)) {
            throw new \InvalidArgumentException("bad identifier: {$name}");
        }
        return '"' . $name . '"';
    }

    /**
     * Build the primary-key byte string for a row. If no PK, returns rowid packed big-endian.
     * @param string[] $pk
     * @param array<string,mixed> $row
     */
    public static function makeKey(array $pk, array $row, ?int $rowid): string
    {
        if (!$pk) {
            if ($rowid === null) {
                throw new \RuntimeException('no pk and no rowid');
            }
            return "\x00rowid:" . pack('J', $rowid);
        }
        $parts = [];
        foreach ($pk as $col) {
            $v = $row[$col] ?? null;
            $parts[] = $v === null ? 'N' : 'V:' . (string)$v;
        }
        return implode("\x1f", $parts);
    }

    /** List all user tables excluding internal sync tables and sqlite_* tables. */
    public static function listTables(\SQLite3 $db): array
    {
        $out = [];
        $res = $db->query("SELECT name FROM sqlite_master WHERE type='table' ORDER BY name");
        while ($row = $res->fetchArray(SQLITE3_ASSOC)) {
            $n = $row['name'];
            if (str_starts_with($n, 'sqlite_')) continue;
            if (str_starts_with($n, '_sync_')) continue;
            $out[] = $n;
        }
        return $out;
    }
}
