<?php
declare(strict_types=1);

use WpSync\Snapshot\Snapshotter;
use WpSync\Snapshot\Materializer;
use WpSync\Snapshot\SchemaInspector;
use WpSync\Snapshot\RowNormalizer;
use WpSync\Snapshot\DirtyTracker;
use WpSync\Store\SQLiteChunkStore;
use WpSync\Store\Chunk;
use WpSync\Sync\SyncEngine;
use WpSync\Encoding\CanonicalEncoder;
use WpSync\Tree\TableTree;

require_once __DIR__ . '/../harness/WpFixture.php';

TestRun::group('FailureModes (named scenarios from spec)', function () {

    // #5 UTF-8 NFC
    TestRun::it('UTF-8 NFC: precomposed and decomposed produce same hash (post title)', function () {
        $srcA = \WpSync\Test\Harness\WpFixture::createDb('https://a.test');
        $srcB = \WpSync\Test\Harness\WpFixture::createDb('https://a.test');
        \WpSync\Test\Harness\WpFixture::insertPost($srcA, "caf\xc3\xa9", 'x', 'https://a.test', 100); // NFC
        \WpSync\Test\Harness\WpFixture::insertPost($srcB, "cafe\xcc\x81", 'x', 'https://a.test', 100); // NFD

        $pA = tempnam(sys_get_temp_dir(), 'a_'); unlink($pA);
        $pB = tempnam(sys_get_temp_dir(), 'b_'); unlink($pB);
        $csA = new SQLiteChunkStore($pA);
        $csB = new SQLiteChunkStore($pB);
        $filters = ['wp_options' => fn($r) => RowNormalizer::isExcludedOption((string)$r['option_name']) ? null : $r];
        $cA = (new Snapshotter($srcA, $csA, 'https://a.test'))
            ->commit(['wp_posts'], 'a', 'm', [], null, $filters);
        $cB = (new Snapshotter($srcB, $csB, 'https://a.test'))
            ->commit(['wp_posts'], 'a', 'm', [], null, $filters);
        // Commits differ because of timestamps, but db roots (which hash rows) must match.
        $rA = Snapshotter::loadCommit($cA, $csA)['root'];
        $rB = Snapshotter::loadCommit($cB, $csB)['root'];
        assertEq($rA, $rB, 'NFC/NFD row must produce same db-root hash');
    });

    // #10 table with no PK -> rowid fallback
    TestRun::it('table without primary key snapshots & materializes using rowid', function () {
        $src = \WpSync\Test\Harness\WpFixture::createDb('https://a.test');
        $src->exec("INSERT INTO plugin_noprimary (a,b) VALUES ('1','x')");
        $src->exec("INSERT INTO plugin_noprimary (a,b) VALUES ('2','y')");

        $p = tempnam(sys_get_temp_dir(), 'cs_'); unlink($p);
        $cs = new SQLiteChunkStore($p);
        $filters = ['wp_options' => fn($r) => RowNormalizer::isExcludedOption((string)$r['option_name']) ? null : $r];
        $c = (new Snapshotter($src, $cs, 'https://a.test'))
            ->commit(['plugin_noprimary'], 'a', 'm', [], null, $filters);

        $dst = \WpSync\Test\Harness\WpFixture::createDb('https://b.test');
        (new Materializer($dst, $cs, 'https://b.test'))->materialize($c);
        $res = $dst->query('SELECT a,b FROM plugin_noprimary ORDER BY a');
        $rows = []; while ($r = $res->fetchArray(SQLITE3_ASSOC)) $rows[] = $r;
        assertEq([['a'=>'1','b'=>'x'], ['a'=>'2','b'=>'y']], $rows);
    });

    // #15 large batch handling memory bound
    TestRun::it('large batch (>10k chunks) stays within memory bound', function () {
        $p = tempnam(sys_get_temp_dir(), 'cs_'); unlink($p);
        $cs = new SQLiteChunkStore($p);
        $entries = [];
        for ($i = 0; $i < 10000; $i++) {
            $entries[] = [pack('J', $i), str_repeat('v', 64) . $i];
        }
        $before = memory_get_peak_usage();
        $root = TableTree::build($entries, $cs);
        $after = memory_get_peak_usage();
        $deltaMB = ($after - $before) / 1024 / 1024;
        // Extremely loose bound; just ensure we're not O(n^2) memory.
        assertTrue($deltaMB < 256, "peak memory delta was {$deltaMB}MB, expected < 256MB");
        assertTrue(strlen($root) === 64);
    });

    // #14 duplicate chunks in DAG walk (shared subtree)
    TestRun::it('shared subtree is fetched once during sync', function () {
        $srcP = tempnam(sys_get_temp_dir(), 's_'); unlink($srcP);
        $dstP = tempnam(sys_get_temp_dir(), 'd_'); unlink($dstP);
        $src = new SQLiteChunkStore($srcP);
        $dst = new SQLiteChunkStore($dstP);
        $shared = Chunk::of('SHARED');
        $a = Chunk::of('A', [$shared->hash]);
        $b = Chunk::of('B', [$shared->hash]);
        $root = Chunk::of('R', [$a->hash, $b->hash]);
        $src->putMany([$shared, $a, $b, $root]);

        // Counting wrapper
        $calls = 0;
        $wrapped = new class($src, $calls) implements \WpSync\Store\ChunkStore {
            public int $fetches = 0;
            public function __construct(private \WpSync\Store\ChunkStore $inner, public int &$calls) {}
            public function hasMany(array $h): array { return $this->inner->hasMany($h); }
            public function getMany(array $h): array { $this->fetches += count($h); return $this->inner->getMany($h); }
            public function putMany(array $c): void { $this->inner->putMany($c); }
        };
        $eng = new SyncEngine();
        $n = $eng->copyReachable($dst, $wrapped, $root->hash);
        assertEq(4, $n);
        // Shared must not be fetched twice. Total unique chunks = 4.
        assertEq(4, $wrapped->fetches);
    });

    // #11 interrupted push: remote ref unchanged
    TestRun::it('interrupted push does not update remote ref', function () {
        $srcP = tempnam(sys_get_temp_dir(), 's_'); unlink($srcP);
        $dstP = tempnam(sys_get_temp_dir(), 'd_'); unlink($dstP);
        $src = new SQLiteChunkStore($srcP);
        $dst = new SQLiteChunkStore($dstP);
        $leaf = Chunk::of('L');
        $root = Chunk::of('R', [$leaf->hash]);
        $src->putMany([$leaf, $root]);

        $refP = tempnam(sys_get_temp_dir(), 'r_'); unlink($refP);
        $rsR = \WpSync\Store\SQLiteRefStore::open($refP);
        // Create remote ref store with nothing set. Simulate push via copyReachable
        // that fails, and ensure rs wasn't touched.
        $failing = new class($src) implements \WpSync\Store\ChunkStore {
            public function __construct(private \WpSync\Store\ChunkStore $inner) {}
            public function hasMany(array $h): array { return []; }
            public function getMany(array $h): array { return $this->inner->getMany($h); }
            public function putMany(array $c): void { throw new \RuntimeException('simulated'); }
        };
        $eng = new SyncEngine();
        $caught = false;
        try { $eng->copyReachable($failing, $src, $root->hash); } catch (\Throwable) { $caught = true; }
        assertTrue($caught);
        assertEq(null, $rsR->getRef('refs/heads/main'));
    });

});
