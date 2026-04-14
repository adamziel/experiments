<?php
declare(strict_types=1);

namespace WpSync\Store;

final class SQLiteRefStore implements RefStore
{
    public function __construct(private readonly \SQLite3 $db)
    {
        $this->db->enableExceptions(true);
        $this->db->exec(<<<SQL
            CREATE TABLE IF NOT EXISTS refs (
                name TEXT PRIMARY KEY,
                hash TEXT NOT NULL
            )
        SQL);
    }

    public static function open(string $path): self
    {
        $db = new \SQLite3($path, SQLITE3_OPEN_CREATE | SQLITE3_OPEN_READWRITE);
        $db->enableExceptions(true);
        $db->exec('PRAGMA journal_mode=WAL');
        $db->exec('PRAGMA synchronous=NORMAL');
        return new self($db);
    }

    public function getRef(string $name): ?string
    {
        $stmt = $this->db->prepare('SELECT hash FROM refs WHERE name = ?');
        $stmt->bindValue(1, $name, SQLITE3_TEXT);
        $res = $stmt->execute();
        $row = $res->fetchArray(SQLITE3_ASSOC);
        return $row ? (string)$row['hash'] : null;
    }

    public function listRefs(string $prefix = ''): array
    {
        $out = [];
        if ($prefix === '') {
            $res = $this->db->query('SELECT name, hash FROM refs');
        } else {
            $stmt = $this->db->prepare('SELECT name, hash FROM refs WHERE name LIKE ?');
            $stmt->bindValue(1, $prefix . '%', SQLITE3_TEXT);
            $res = $stmt->execute();
        }
        while ($row = $res->fetchArray(SQLITE3_ASSOC)) {
            $out[$row['name']] = $row['hash'];
        }
        return $out;
    }

    public function casRef(string $name, ?string $expectedOldHash, string $newHash): bool
    {
        $this->db->exec('BEGIN IMMEDIATE');
        try {
            if ($expectedOldHash === null) {
                $stmt = $this->db->prepare('INSERT OR IGNORE INTO refs (name, hash) VALUES (?, ?)');
                $stmt->bindValue(1, $name, SQLITE3_TEXT);
                $stmt->bindValue(2, $newHash, SQLITE3_TEXT);
                $stmt->execute();
                $ok = $this->db->changes() === 1;
            } else {
                $stmt = $this->db->prepare('UPDATE refs SET hash = ? WHERE name = ? AND hash = ?');
                $stmt->bindValue(1, $newHash, SQLITE3_TEXT);
                $stmt->bindValue(2, $name, SQLITE3_TEXT);
                $stmt->bindValue(3, $expectedOldHash, SQLITE3_TEXT);
                $stmt->execute();
                $ok = $this->db->changes() === 1;
            }
            $this->db->exec($ok ? 'COMMIT' : 'ROLLBACK');
            return $ok;
        } catch (\Throwable $e) {
            $this->db->exec('ROLLBACK');
            throw $e;
        }
    }
}
