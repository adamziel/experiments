<?php
declare(strict_types=1);

use WpSync\Store\SQLiteChunkStore;
use WpSync\Tree\TableTree;

TestRun::group('TableTree', function () {
    $newStore = static function () {
        $p = tempnam(sys_get_temp_dir(), 'tree_'); unlink($p);
        return new SQLiteChunkStore($p);
    };

    TestRun::it('empty tree has deterministic root', function () use ($newStore) {
        $cs = $newStore();
        $r1 = TableTree::build([], $cs);
        $r2 = TableTree::build([], $cs);
        assertEq($r1, $r2);
    });

    TestRun::it('single entry', function () use ($newStore) {
        $cs = $newStore();
        $r = TableTree::build([['k1', 'v1']], $cs);
        assertEq('v1', TableTree::get($r, 'k1', $cs));
        assertEq(null, TableTree::get($r, 'missing', $cs));
    });

    TestRun::it('exactly FANOUT entries (one full leaf)', function () use ($newStore) {
        $cs = $newStore();
        $entries = [];
        for ($i = 1; $i <= TableTree::FANOUT; $i++) {
            $entries[] = [sprintf('%08d', $i), "v{$i}"];
        }
        $r = TableTree::build($entries, $cs);
        assertEq('v32', TableTree::get($r, sprintf('%08d', 32), $cs));
    });

    TestRun::it('FANOUT+1 entries forces a split', function () use ($newStore) {
        $cs = $newStore();
        $n = TableTree::FANOUT + 1;
        $entries = [];
        for ($i = 1; $i <= $n; $i++) {
            $entries[] = [sprintf('%08d', $i), "v{$i}"];
        }
        $r = TableTree::build($entries, $cs);
        assertEq("v{$n}", TableTree::get($r, sprintf('%08d', $n), $cs));
    });

    TestRun::it('determinism across insertion orders (10k entries)', function () use ($newStore) {
        $cs = $newStore();
        $entries1 = [];
        for ($i = 0; $i < 10000; $i++) {
            $entries1[] = [pack('J', $i), "v{$i}"];
        }
        $entries2 = $entries1;
        shuffle($entries2);
        $r1 = TableTree::build($entries1, $cs);
        $r2 = TableTree::build($entries2, $cs);
        assertEq($r1, $r2);
    });

    TestRun::it('iterate yields sorted entries', function () use ($newStore) {
        $cs = $newStore();
        $entries = [['b', '2'], ['a', '1'], ['c', '3']];
        $r = TableTree::build($entries, $cs);
        $out = iterator_to_array(TableTree::iterate($r, $cs), false);
        assertEq([['a','1'], ['b','2'], ['c','3']], $out);
    });
});
