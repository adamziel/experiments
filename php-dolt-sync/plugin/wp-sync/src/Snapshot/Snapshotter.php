<?php
declare(strict_types=1);

namespace WpSync\Snapshot;

use WpSync\Encoding\CanonicalEncoder;
use WpSync\Store\Chunk;
use WpSync\Store\ChunkStore;
use WpSync\Tree\TableTree;

/**
 * Reads the live WordPress SQLite database and produces a content-addressed
 * commit in the chunk store.
 */
final class Snapshotter
{
    public function __construct(
        private readonly \SQLite3 $db,
        private readonly ChunkStore $chunks,
        private readonly string $siteUrl,
    ) {}

    /**
     * Produce a commit and return its hash.
     *
     * @param string[]       $tables            Tables to include.
     * @param string[]       $parents           Parent commit hashes.
     * @param ?string        $priorRootHash     If set, unchanged tables reuse the previous tree.
     * @param array<string,callable> $rowFilter Optional per-table row filter: name => fn(row):?row
     */
    public function commit(
        array $tables,
        string $author,
        string $message,
        array $parents = [],
        ?string $priorRootHash = null,
        array $rowFilters = [],
    ): string {
        // Load prior root to reuse unchanged table trees.
        $priorRoot = null;
        $priorDirty = null;
        if ($priorRootHash !== null) {
            $priorRoot = self::loadRoot($priorRootHash, $this->chunks);
            if (DirtyTracker::isInstalled($this->db)) {
                $priorDirty = array_flip(DirtyTracker::dirtyTables($this->db));
            }
        }

        $tablesOut = [];
        foreach ($tables as $t) {
            $schema = SchemaInspector::inspect($this->db, $t);
            $reusePrior = $priorRoot
                && isset($priorRoot['tables'][$t])
                && $priorRoot['tables'][$t]['schema'] === $schema['schemaHash']
                && $priorDirty !== null
                && !isset($priorDirty[$t]);

            if ($reusePrior) {
                $tablesOut[$t] = $priorRoot['tables'][$t];
                continue;
            }

            $filter = $rowFilters[$t] ?? null;
            $tree = $this->buildTableTree($t, $schema, $filter);
            $tablesOut[$t] = ['tree' => $tree, 'schema' => $schema['schemaHash']];
        }

        ksort($tablesOut, SORT_STRING);

        $dbRoot = ['tables' => $tablesOut];
        $rootBytes = CanonicalEncoder::encode($dbRoot);
        $rootRefs = [];
        foreach ($tablesOut as $t) {
            $rootRefs[] = $t['tree'];
        }
        $rootChunk = Chunk::of($rootBytes, $rootRefs);
        $this->chunks->putMany([$rootChunk]);

        $commit = [
            'root' => $rootChunk->hash,
            'parents' => array_values($parents),
            'author' => $author,
            'message' => $message,
            'timestamp' => time(),
        ];
        $commitBytes = CanonicalEncoder::encode($commit);
        $commitChunk = Chunk::of($commitBytes, array_merge([$rootChunk->hash], $commit['parents']));
        $this->chunks->putMany([$commitChunk]);

        // Clear dirty log after successful commit.
        if (DirtyTracker::isInstalled($this->db)) {
            DirtyTracker::clear($this->db);
        }

        return $commitChunk->hash;
    }

    private function buildTableTree(string $table, array $schema, ?callable $filter): string
    {
        $pk = $schema['pk'];
        $cols = $schema['columns'];
        $quoted = SchemaInspector::quoteIdent($table);
        $selectCols = $pk ? implode(',', array_map([SchemaInspector::class, 'quoteIdent'], $cols))
                          : 'rowid AS __sync_rowid__, ' . implode(',', array_map([SchemaInspector::class, 'quoteIdent'], $cols));
        $res = $this->db->query("SELECT {$selectCols} FROM {$quoted}");

        $entries = [];
        while ($row = $res->fetchArray(SQLITE3_ASSOC)) {
            $rowid = $pk ? null : (int)$row['__sync_rowid__'];
            if (!$pk) unset($row['__sync_rowid__']);

            if ($filter !== null) {
                $row = $filter($row);
                if ($row === null) continue;
            }

            $normalized = RowNormalizer::normalizeForEncode($row, $this->siteUrl);
            $key = SchemaInspector::makeKey($pk, $row, $rowid);
            $value = CanonicalEncoder::encode($normalized);
            $entries[] = [$key, $value];
        }

        return TableTree::build($entries, $this->chunks);
    }

    public static function loadCommit(string $hash, ChunkStore $chunks): array
    {
        $got = $chunks->getMany([$hash]);
        if (!isset($got[$hash])) {
            throw new \RuntimeException('commit not found: ' . $hash);
        }
        return CanonicalEncoder::decode($got[$hash]->bytes);
    }

    public static function loadRoot(string $hash, ChunkStore $chunks): array
    {
        $got = $chunks->getMany([$hash]);
        if (!isset($got[$hash])) {
            throw new \RuntimeException('root not found: ' . $hash);
        }
        return CanonicalEncoder::decode($got[$hash]->bytes);
    }
}
