<?php
declare(strict_types=1);

namespace WpSync\Snapshot;

use WpSync\Encoding\CanonicalEncoder;
use WpSync\Store\ChunkStore;
use WpSync\Tree\TableTree;

/**
 * Apply a commit to a live WordPress SQLite database.
 *
 *  - Runs inside BEGIN/COMMIT.
 *  - Suspends dirty-tracking triggers.
 *  - Diffs remote tree vs. current state; applies INSERT/UPDATE/DELETE.
 *  - Rewrites {{SITE_URL}} placeholder back to the local site URL.
 *  - Warns and skips tables with mismatching schema hash.
 */
final class Materializer
{
    /** @var string[] */
    public array $warnings = [];

    public function __construct(
        private readonly \SQLite3 $db,
        private readonly ChunkStore $chunks,
        private readonly string $localSiteUrl,
    ) {}

    /**
     * @param string $commitHash
     * @param string[] $tables Restrict to these tables (else all in root).
     * @param array<string,callable> $rowFilters Optional: skip rows per table; filter(row) -> bool
     */
    public function materialize(string $commitHash, ?array $tables = null, array $rowFilters = []): void
    {
        $commit = Snapshotter::loadCommit($commitHash, $this->chunks);
        $remoteRoot = Snapshotter::loadRoot($commit['root'], $this->chunks);

        $affectedTables = array_keys($remoteRoot['tables']);
        if ($tables !== null) {
            $affectedTables = array_values(array_intersect($affectedTables, $tables));
        }

        // Suspend triggers for *all* user tables with triggers installed, to avoid
        // leaking dirty entries.
        $dirtyInstalled = DirtyTracker::isInstalled($this->db);
        $userTables = SchemaInspector::listTables($this->db);
        if ($dirtyInstalled) {
            DirtyTracker::suspend($this->db, $userTables);
        }

        $this->db->exec('BEGIN IMMEDIATE');
        try {
            foreach ($affectedTables as $t) {
                if (!in_array($t, $userTables, true)) {
                    // Table doesn't exist locally — skip (schema migration out of scope).
                    $this->warnings[] = "table {$t} missing locally; skipped";
                    continue;
                }
                $schema = SchemaInspector::inspect($this->db, $t);
                if ($schema['schemaHash'] !== $remoteRoot['tables'][$t]['schema']) {
                    $this->warnings[] = "schema mismatch for {$t}; skipped";
                    continue;
                }
                $this->applyTable($t, $schema, $remoteRoot['tables'][$t]['tree'], $rowFilters[$t] ?? null);
            }
            $this->db->exec('COMMIT');
        } catch (\Throwable $e) {
            $this->db->exec('ROLLBACK');
            if ($dirtyInstalled) DirtyTracker::resume($this->db, $userTables);
            throw $e;
        }

        if ($dirtyInstalled) {
            DirtyTracker::resume($this->db, $userTables);
            // Materialization shouldn't pollute dirty; defensively clear.
            DirtyTracker::clear($this->db);
        }
    }

    private function applyTable(string $table, array $schema, string $remoteTreeHash, ?callable $filter): void
    {
        $pk = $schema['pk'];
        $quoted = SchemaInspector::quoteIdent($table);

        // Load local rows keyed by PK, EXCLUDING any row that the filter
        // would have skipped on snapshot — those rows must be neither inserted,
        // updated, nor deleted by the materializer.
        $local = $this->loadLocalKeyed($table, $schema, $filter);

        // Walk the remote tree entries.
        $remote = iterator_to_array(TableTree::iterate($remoteTreeHash, $this->chunks), false);
        $remoteKeys = [];
        foreach ($remote as [$k, $encVal]) {
            $remoteKeys[$k] = true;
            $row = CanonicalEncoder::decode($encVal);
            if (!is_array($row)) {
                throw new \RuntimeException("row value for {$table} is not a map");
            }

            $applied = RowNormalizer::denormalizeForApply($row, $this->localSiteUrl);
            if ($filter !== null && $filter($applied) === null) continue;

            if (isset($local[$k])) {
                if ($local[$k]['encoded'] !== $encVal) {
                    $this->applyUpsert($table, $schema, $applied, 'UPDATE', $local[$k]['rowid']);
                }
            } else {
                $this->applyUpsert($table, $schema, $applied, 'INSERT', null);
            }
        }

        // Deletions: keys in local (and syncable) but not in remote.
        foreach ($local as $k => $info) {
            if (!isset($remoteKeys[$k])) {
                $this->applyDelete($table, $info['rowid']);
            }
        }
    }

    /**
     * Load all rows of a table keyed by the same PK byte-string the snapshotter uses.
     * @return array<string,array{encoded:string, rowid:int}>
     */
    private function loadLocalKeyed(string $table, array $schema, ?callable $filter = null): array
    {
        $pk = $schema['pk'];
        $cols = $schema['columns'];
        $quoted = SchemaInspector::quoteIdent($table);
        $selectCols = 'rowid AS __sync_rowid__,' . implode(',', array_map([SchemaInspector::class, 'quoteIdent'], $cols));
        $res = $this->db->query("SELECT {$selectCols} FROM {$quoted}");
        $out = [];
        $siteUrl = $this->localSiteUrl;
        while ($row = $res->fetchArray(SQLITE3_ASSOC)) {
            $rowid = (int)$row['__sync_rowid__'];
            unset($row['__sync_rowid__']);
            if ($filter !== null && $filter($row) === null) {
                continue;
            }
            $normalized = RowNormalizer::normalizeForEncode($row, $siteUrl);
            $key = SchemaInspector::makeKey($pk, $row, $rowid);
            $out[$key] = ['encoded' => CanonicalEncoder::encode($normalized), 'rowid' => $rowid];
        }
        return $out;
    }

    private function applyUpsert(string $table, array $schema, array $row, string $op, ?int $rowid): void
    {
        $quoted = SchemaInspector::quoteIdent($table);
        $cols = array_keys($row);
        // Remove generated / unknown columns by restricting to table columns.
        $valid = array_flip($schema['columns']);
        $cols = array_values(array_filter($cols, fn($c) => isset($valid[$c])));
        $set = array_intersect_key($row, $valid);

        if ($op === 'INSERT') {
            $placeholders = implode(',', array_fill(0, count($cols), '?'));
            $colSql = implode(',', array_map([SchemaInspector::class, 'quoteIdent'], $cols));
            $sql = "INSERT INTO {$quoted} ({$colSql}) VALUES ({$placeholders})";
            $stmt = $this->db->prepare($sql);
            $i = 1;
            foreach ($cols as $c) {
                $this->bindValue($stmt, $i++, $set[$c]);
            }
            $stmt->execute();
            return;
        }

        // UPDATE by rowid
        $assigns = implode(',', array_map(fn($c) => SchemaInspector::quoteIdent($c) . '=?', $cols));
        $sql = "UPDATE {$quoted} SET {$assigns} WHERE rowid = ?";
        $stmt = $this->db->prepare($sql);
        $i = 1;
        foreach ($cols as $c) {
            $this->bindValue($stmt, $i++, $set[$c]);
        }
        $stmt->bindValue($i, $rowid, SQLITE3_INTEGER);
        $stmt->execute();
    }

    private function applyDelete(string $table, int $rowid): void
    {
        $q = SchemaInspector::quoteIdent($table);
        $stmt = $this->db->prepare("DELETE FROM {$q} WHERE rowid = ?");
        $stmt->bindValue(1, $rowid, SQLITE3_INTEGER);
        $stmt->execute();
    }

    private function bindValue(\SQLite3Stmt $stmt, int $i, mixed $v): void
    {
        if ($v === null) {
            $stmt->bindValue($i, null, SQLITE3_NULL);
        } elseif (is_int($v)) {
            $stmt->bindValue($i, $v, SQLITE3_INTEGER);
        } elseif (is_float($v)) {
            $stmt->bindValue($i, $v, SQLITE3_FLOAT);
        } else {
            $stmt->bindValue($i, (string)$v, SQLITE3_TEXT);
        }
    }
}
