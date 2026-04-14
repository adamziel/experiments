<?php
declare(strict_types=1);

namespace WpSync\Store;

final class SQLiteChunkStore implements ChunkStore
{
    private \SQLite3 $db;

    public function __construct(string $path)
    {
        $this->db = new \SQLite3($path, SQLITE3_OPEN_CREATE | SQLITE3_OPEN_READWRITE);
        $this->db->enableExceptions(true);
        $this->db->exec('PRAGMA journal_mode=WAL');
        $this->db->exec('PRAGMA synchronous=NORMAL');
        $this->db->exec('PRAGMA foreign_keys=ON');
        $this->db->exec(<<<SQL
            CREATE TABLE IF NOT EXISTS chunks (
                hash TEXT PRIMARY KEY,
                data BLOB NOT NULL,
                refs TEXT NOT NULL DEFAULT '[]'
            )
        SQL);
    }

    public function db(): \SQLite3
    {
        return $this->db;
    }

    public function close(): void
    {
        $this->db->close();
    }

    public function hasMany(array $hashes): array
    {
        $out = [];
        if (!$hashes) return $out;
        foreach (array_chunk(array_values($hashes), 200) as $batch) {
            $marks = implode(',', array_fill(0, count($batch), '?'));
            $stmt = $this->db->prepare("SELECT hash FROM chunks WHERE hash IN ($marks)");
            foreach ($batch as $i => $h) {
                $stmt->bindValue($i + 1, $h, SQLITE3_TEXT);
            }
            $res = $stmt->execute();
            while ($row = $res->fetchArray(SQLITE3_ASSOC)) {
                $out[$row['hash']] = true;
            }
        }
        return $out;
    }

    public function getMany(array $hashes): array
    {
        $out = [];
        if (!$hashes) return $out;
        foreach (array_chunk(array_values($hashes), 200) as $batch) {
            $marks = implode(',', array_fill(0, count($batch), '?'));
            $stmt = $this->db->prepare("SELECT hash, data, refs FROM chunks WHERE hash IN ($marks)");
            foreach ($batch as $i => $h) {
                $stmt->bindValue($i + 1, $h, SQLITE3_TEXT);
            }
            $res = $stmt->execute();
            while ($row = $res->fetchArray(SQLITE3_ASSOC)) {
                $refs = json_decode($row['refs'], true, flags: JSON_THROW_ON_ERROR);
                $out[$row['hash']] = new Chunk($row['hash'], $row['data'], $refs);
            }
        }
        return $out;
    }

    public function putMany(array $chunks): void
    {
        if (!$chunks) return;
        $this->db->exec('BEGIN IMMEDIATE');
        try {
            $stmt = $this->db->prepare(
                'INSERT OR IGNORE INTO chunks (hash, data, refs) VALUES (?, ?, ?)'
            );
            foreach ($chunks as $c) {
                if (!$c instanceof Chunk) {
                    throw new \InvalidArgumentException('putMany expects Chunk objects');
                }
                $c->verify();
                $stmt->bindValue(1, $c->hash, SQLITE3_TEXT);
                $stmt->bindValue(2, $c->bytes, SQLITE3_BLOB);
                $stmt->bindValue(3, json_encode($c->refs, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR), SQLITE3_TEXT);
                $stmt->execute();
                $stmt->reset();
            }
            $this->db->exec('COMMIT');
        } catch (\Throwable $e) {
            $this->db->exec('ROLLBACK');
            throw $e;
        }
    }

    public function count(): int
    {
        $res = $this->db->query('SELECT COUNT(*) AS n FROM chunks');
        $row = $res->fetchArray(SQLITE3_ASSOC);
        return (int)$row['n'];
    }
}
