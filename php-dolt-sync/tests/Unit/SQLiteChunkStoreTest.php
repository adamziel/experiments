<?php
declare(strict_types=1);

use WpSync\Store\Chunk;
use WpSync\Store\SQLiteChunkStore;

TestRun::group('SQLiteChunkStore', function () {
    $newStore = static function () {
        $p = tempnam(sys_get_temp_dir(), 'cs_'); unlink($p);
        return [new SQLiteChunkStore($p), $p];
    };

    TestRun::it('put, has, get round-trip', function () use ($newStore) {
        [$cs] = $newStore();
        $c = Chunk::of('hello', []);
        $cs->putMany([$c]);
        assertEq([$c->hash => true], $cs->hasMany([$c->hash]));
        $got = $cs->getMany([$c->hash]);
        assertEq('hello', $got[$c->hash]->bytes);
    });

    TestRun::it('rejects wrong-hash chunk', function () use ($newStore) {
        [$cs] = $newStore();
        $bad = new Chunk(str_repeat('0', 64), 'different', []);
        assertThrows(fn() => $cs->putMany([$bad]));
    });

    TestRun::it('idempotent put', function () use ($newStore) {
        [$cs] = $newStore();
        $c = Chunk::of('x');
        $cs->putMany([$c, $c]);
        assertEq(1, $cs->count());
    });

    TestRun::it('hasMany/getMany with mix of present and absent', function () use ($newStore) {
        [$cs] = $newStore();
        $c = Chunk::of('yo');
        $cs->putMany([$c]);
        $ghost = str_repeat('a', 64);
        $has = $cs->hasMany([$c->hash, $ghost]);
        assertTrue(isset($has[$c->hash]));
        assertTrue(!isset($has[$ghost]));
        $got = $cs->getMany([$c->hash, $ghost]);
        assertEq(1, count($got));
        assertTrue(isset($got[$c->hash]));
    });

    TestRun::it('stores refs list', function () use ($newStore) {
        [$cs] = $newStore();
        $child = Chunk::of('child');
        $parent = Chunk::of('parent', [$child->hash]);
        $cs->putMany([$child, $parent]);
        $got = $cs->getMany([$parent->hash]);
        assertEq([$child->hash], $got[$parent->hash]->refs);
    });
});
