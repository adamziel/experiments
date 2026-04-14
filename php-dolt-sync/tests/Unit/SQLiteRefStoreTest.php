<?php
declare(strict_types=1);

use WpSync\Store\SQLiteRefStore;

TestRun::group('SQLiteRefStore', function () {
    $newStore = static function () {
        $p = tempnam(sys_get_temp_dir(), 'refs_'); unlink($p);
        return SQLiteRefStore::open($p);
    };

    TestRun::it('getRef nonexistent returns null', function () use ($newStore) {
        $rs = $newStore();
        assertEq(null, $rs->getRef('refs/heads/main'));
    });

    TestRun::it('casRef with null creates new', function () use ($newStore) {
        $rs = $newStore();
        $h = str_repeat('1', 64);
        assertTrue($rs->casRef('refs/heads/main', null, $h));
        assertEq($h, $rs->getRef('refs/heads/main'));
    });

    TestRun::it('casRef with null fails if ref exists', function () use ($newStore) {
        $rs = $newStore();
        $h = str_repeat('1', 64);
        $rs->casRef('refs/heads/main', null, $h);
        assertTrue(!$rs->casRef('refs/heads/main', null, str_repeat('2', 64)));
    });

    TestRun::it('casRef succeeds when expected matches', function () use ($newStore) {
        $rs = $newStore();
        $h1 = str_repeat('1', 64); $h2 = str_repeat('2', 64);
        $rs->casRef('refs/heads/main', null, $h1);
        assertTrue($rs->casRef('refs/heads/main', $h1, $h2));
        assertEq($h2, $rs->getRef('refs/heads/main'));
    });

    TestRun::it('casRef fails when expected mismatches', function () use ($newStore) {
        $rs = $newStore();
        $h1 = str_repeat('1', 64); $h2 = str_repeat('2', 64); $h3 = str_repeat('3', 64);
        $rs->casRef('refs/heads/main', null, $h1);
        assertTrue(!$rs->casRef('refs/heads/main', $h2, $h3));
        assertEq($h1, $rs->getRef('refs/heads/main'));
    });

    TestRun::it('listRefs with prefix', function () use ($newStore) {
        $rs = $newStore();
        $h = str_repeat('a', 64);
        $rs->casRef('refs/heads/main', null, $h);
        $rs->casRef('refs/remotes/origin/main', null, $h);
        $heads = $rs->listRefs('refs/heads/');
        assertEq(1, count($heads));
        assertTrue(isset($heads['refs/heads/main']));
    });
});
