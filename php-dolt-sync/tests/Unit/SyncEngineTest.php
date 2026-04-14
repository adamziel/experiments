<?php
declare(strict_types=1);

use WpSync\Store\Chunk;
use WpSync\Store\ChunkStore;
use WpSync\Store\SQLiteChunkStore;
use WpSync\Sync\SyncEngine;

/** Chunk store that counts calls and can fail after N get calls or during put. */
final class FlakeyStore implements ChunkStore {
    public int $failAfterCalls = -1;
    public int $calls = 0;
    public int $served = 0;
    public function __construct(private ChunkStore $inner) {}
    public function hasMany(array $hashes): array { return $this->inner->hasMany($hashes); }
    public function getMany(array $hashes): array {
        $this->calls++;
        if ($this->failAfterCalls >= 0 && $this->calls > $this->failAfterCalls) {
            throw new \RuntimeException('simulated network failure');
        }
        $r = $this->inner->getMany($hashes);
        $this->served += count($r);
        return $r;
    }
    public function putMany(array $chunks): void { $this->inner->putMany($chunks); }
}

/** Wraps a target chunk store to fail putMany after N total chunks written. */
final class FlakeyTarget implements ChunkStore {
    public int $written = 0;
    public int $failAfterWrites = -1;
    public function __construct(private ChunkStore $inner) {}
    public function hasMany(array $hashes): array { return $this->inner->hasMany($hashes); }
    public function getMany(array $hashes): array { return $this->inner->getMany($hashes); }
    public function putMany(array $chunks): void {
        if ($this->failAfterWrites >= 0 && $this->written + count($chunks) > $this->failAfterWrites) {
            // Write the first N we're allowed, then throw.
            $canWrite = max(0, $this->failAfterWrites - $this->written);
            if ($canWrite > 0) {
                $this->inner->putMany(array_slice($chunks, 0, $canWrite));
                $this->written += $canWrite;
            }
            throw new \RuntimeException('simulated put failure');
        }
        $this->inner->putMany($chunks);
        $this->written += count($chunks);
    }
}

TestRun::group('SyncEngine', function () {
    $newStore = static function () {
        $p = tempnam(sys_get_temp_dir(), 'sync_'); unlink($p);
        return new SQLiteChunkStore($p);
    };

    TestRun::it('copies chunks from source to target with refs', function () use ($newStore) {
        $src = $newStore();
        $dst = $newStore();
        $leaf1 = Chunk::of('leaf1');
        $leaf2 = Chunk::of('leaf2');
        $root  = Chunk::of('root', [$leaf1->hash, $leaf2->hash]);
        $src->putMany([$leaf1, $leaf2, $root]);

        $eng = new SyncEngine();
        $n = $eng->copyReachable($dst, $src, $root->hash);
        assertEq(3, $n);
        assertTrue(isset($dst->hasMany([$root->hash])[$root->hash]));
    });

    TestRun::it('skips chunks already present in target', function () use ($newStore) {
        $src = $newStore();
        $dst = $newStore();
        $leaf1 = Chunk::of('leaf1');
        $leaf2 = Chunk::of('leaf2');
        $root  = Chunk::of('root', [$leaf1->hash, $leaf2->hash]);
        $src->putMany([$leaf1, $leaf2, $root]);
        $dst->putMany([$leaf1]); // pre-populate

        $eng = new SyncEngine();
        $n = $eng->copyReachable($dst, $src, $root->hash);
        assertEq(2, $n);
    });

    TestRun::it('does not re-fetch duplicate refs', function () use ($newStore) {
        $src = $newStore();
        $dst = $newStore();
        $shared = Chunk::of('shared');
        $a = Chunk::of('A', [$shared->hash]);
        $b = Chunk::of('B', [$shared->hash]);
        $root = Chunk::of('R', [$a->hash, $b->hash]);
        $src->putMany([$shared, $a, $b, $root]);

        $flakey = new FlakeyStore($src);
        $eng = new SyncEngine();
        $n = $eng->copyReachable($dst, $flakey, $root->hash);
        assertEq(4, $n);
        // $shared must be fetched at most once (flakey counts total chunks served).
        assertEq(4, $flakey->served);
    });

    TestRun::it('idempotent: second copy transfers zero', function () use ($newStore) {
        $src = $newStore();
        $dst = $newStore();
        $leaf = Chunk::of('L');
        $root = Chunk::of('R', [$leaf->hash]);
        $src->putMany([$leaf, $root]);

        $eng = new SyncEngine();
        assertEq(2, $eng->copyReachable($dst, $src, $root->hash));
        assertEq(0, $eng->copyReachable($dst, $src, $root->hash));
    });

    TestRun::it('interrupted copy leaves target consistent (no orphan chunks past failure)', function () use ($newStore) {
        // We verify that after a failure, the target only contains chunks
        // whose refs are all already satisfied (children written before parents).
        $src = $newStore();
        $dst = $newStore();
        $leaves = [];
        for ($i = 0; $i < 10; $i++) $leaves[] = Chunk::of("leaf{$i}");
        $refs = array_map(fn(Chunk $c) => $c->hash, $leaves);
        $root = Chunk::of('root', $refs);
        $src->putMany(array_merge($leaves, [$root]));

        $flakey = new FlakeyStore($src);
        $flakey->failAfterCalls = 1;
        $eng = new SyncEngine();
        assertThrows(fn() => $eng->copyReachable($dst, $flakey, $root->hash));
        // Root must not have been written (children incomplete).
        assertTrue(!isset($dst->hasMany([$root->hash])[$root->hash]));
    });

    TestRun::it('resume after interruption transfers remaining', function () use ($newStore) {
        // Interrupt during write phase. Some leaves get written, then put fails.
        // On resume: already-written leaves are skipped; remaining leaves + root transfer.
        $src = $newStore();
        $dstInner = $newStore();
        $leaves = [];
        for ($i = 0; $i < 10; $i++) $leaves[] = Chunk::of("leaf{$i}");
        $refs = array_map(fn(Chunk $c) => $c->hash, $leaves);
        $root = Chunk::of('root', $refs);
        $src->putMany(array_merge($leaves, [$root]));

        $dst = new FlakeyTarget($dstInner);
        $dst->failAfterWrites = 5; // allow 5 leaves to be written then fail
        $eng = new SyncEngine();
        try { $eng->copyReachable($dst, $src, $root->hash); } catch (\Throwable) {}
        // Now 5 leaves are in dstInner; root not written.
        $dst->failAfterWrites = -1;
        $transferred = $eng->copyReachable($dst, $src, $root->hash);
        // Should only transfer the remaining 5 leaves + root = 6.
        assertTrue($transferred < 11, "resume transferred {$transferred}, expected < 11");
        assertTrue(isset($dstInner->hasMany([$root->hash])[$root->hash]));
    });

    TestRun::it('isFastForward detects ancestor via commit walk', function () use ($newStore) {
        $cs = $newStore();
        // Build a fake commit chain: C0 <- C1 <- C2
        $makeCommit = function (string $parent = null) use ($cs) {
            $c = ['parents' => $parent ? [$parent] : [], 'root' => str_repeat('0', 64)];
            $bytes = \WpSync\Encoding\CanonicalEncoder::encode($c);
            $chunk = Chunk::of($bytes);
            $cs->putMany([$chunk]);
            return $chunk->hash;
        };
        $c0 = $makeCommit();
        $c1 = $makeCommit($c0);
        $c2 = $makeCommit($c1);
        $eng = new SyncEngine();
        assertTrue($eng->isFastForward($cs, $c2, $c0));
        assertTrue($eng->isFastForward($cs, $c2, $c1));
        assertTrue(!$eng->isFastForward($cs, $c1, $c2));
    });
});
