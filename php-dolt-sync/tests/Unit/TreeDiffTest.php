<?php
declare(strict_types=1);

use WpSync\Store\SQLiteChunkStore;
use WpSync\Tree\TableTree;

/**
 * A ChunkStore decorator counting getMany calls, to verify diff is O(log n).
 */
final class CountingStore implements \WpSync\Store\ChunkStore {
    public int $getCalls = 0;
    public int $chunksFetched = 0;
    public function __construct(private \WpSync\Store\ChunkStore $inner) {}
    public function hasMany(array $hashes): array { return $this->inner->hasMany($hashes); }
    public function getMany(array $hashes): array {
        $this->getCalls++;
        $r = $this->inner->getMany($hashes);
        $this->chunksFetched += count($r);
        return $r;
    }
    public function putMany(array $chunks): void { $this->inner->putMany($chunks); }
}

TestRun::group('TreeDiff', function () {
    $newStore = static function () {
        $p = tempnam(sys_get_temp_dir(), 'tree_'); unlink($p);
        return new SQLiteChunkStore($p);
    };

    TestRun::it('identical trees -> zero diff', function () use ($newStore) {
        $cs = $newStore();
        $e = [['a','1'], ['b','2']];
        $r = TableTree::build($e, $cs);
        assertEq([], iterator_to_array(TableTree::diff($r, $r, $cs), false));
    });

    TestRun::it('changed entry yields exactly one diff', function () use ($newStore) {
        $cs = $newStore();
        $e1 = [['a','1'], ['b','2'], ['c','3']];
        $e2 = [['a','1'], ['b','X'], ['c','3']];
        $r1 = TableTree::build($e1, $cs);
        $r2 = TableTree::build($e2, $cs);
        $d = iterator_to_array(TableTree::diff($r1, $r2, $cs), false);
        assertEq(1, count($d));
        assertEq(['b', '2', 'X'], $d[0]);
    });

    TestRun::it('added entry', function () use ($newStore) {
        $cs = $newStore();
        $r1 = TableTree::build([['a','1']], $cs);
        $r2 = TableTree::build([['a','1'], ['b','2']], $cs);
        $d = iterator_to_array(TableTree::diff($r1, $r2, $cs), false);
        assertEq([['b', null, '2']], $d);
    });

    TestRun::it('deleted entry', function () use ($newStore) {
        $cs = $newStore();
        $r1 = TableTree::build([['a','1'], ['b','2']], $cs);
        $r2 = TableTree::build([['a','1']], $cs);
        $d = iterator_to_array(TableTree::diff($r1, $r2, $cs), false);
        assertEq([['b', '2', null]], $d);
    });

    TestRun::it('diff skips unchanged subtrees (logarithmic fetch)', function () use ($newStore) {
        $cs = $newStore();
        $entries = [];
        for ($i = 0; $i < 10000; $i++) {
            $entries[] = [sprintf('%08d', $i), "v{$i}"];
        }
        $r1 = TableTree::build($entries, $cs);
        // change one entry
        $entries[5000] = [sprintf('%08d', 5000), 'CHANGED'];
        $r2 = TableTree::build($entries, $cs);

        $counter = new CountingStore($cs);
        $d = iterator_to_array(TableTree::diff($r1, $r2, $counter), false);
        assertEq(1, count($d));
        // For 10k entries, FANOUT=64: depth ~ log_64(10000/64) = ~ 2 levels above leaves.
        // Diff should fetch at most a handful of chunks; bound at 100 is very loose but
        // guarantees not O(n).
        assertTrue($counter->chunksFetched < 100, "diff fetched {$counter->chunksFetched} chunks, expected << 10000");
    });
});
