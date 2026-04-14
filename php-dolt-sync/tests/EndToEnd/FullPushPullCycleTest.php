<?php
declare(strict_types=1);

use WpSync\Snapshot\Snapshotter;
use WpSync\Snapshot\Materializer;
use WpSync\Snapshot\SchemaInspector;
use WpSync\Snapshot\RowNormalizer;
use WpSync\Store\SQLiteChunkStore;
use WpSync\Store\SQLiteRefStore;
use WpSync\Store\HttpChunkStore;
use WpSync\Sync\SyncEngine;
use WpSync\Sync\PushCommand;
use WpSync\Sync\FetchCommand;

require_once __DIR__ . '/../harness/WpFixture.php';
require_once __DIR__ . '/../harness/InProcessTransport.php';

TestRun::group('FullPushPullCycle (A<->B via HTTP transport)', function () {
    TestRun::it('A pushes, B pulls+materializes, B commits+pushes, A pulls+materializes', function () {
        // Two WP-like dbs.
        $dbA = \WpSync\Test\Harness\WpFixture::createDb('https://site-a.test');
        \WpSync\Test\Harness\WpFixture::insertPost($dbA, 'Post from A', '<img src="https://site-a.test/a.png">');
        $stmt = $dbA->prepare("INSERT INTO wp_options(option_name, option_value) VALUES('shared', ?)");
$stmt->bindValue(1, serialize(['k' => 'v'])); $stmt->execute();

        $dbB = \WpSync\Test\Harness\WpFixture::createDb('https://site-b.test');

        // Two chunk stores (one per site).
        $pA = tempnam(sys_get_temp_dir(), 'a_'); unlink($pA);
        $csA = new SQLiteChunkStore($pA); $rsA = SQLiteRefStore::open($pA);
        $pB = tempnam(sys_get_temp_dir(), 'b_'); unlink($pB);
        $csB = new SQLiteChunkStore($pB); $rsB = SQLiteRefStore::open($pB);

        // In-process HTTP transports: A's client hits B's stores, and B's client hits A's stores.
        $xportToB = new \WpSync\Test\Harness\InProcessTransport($csB, $rsB);
        $httpToB = new HttpChunkStore('http://b', 'u', 'p', $xportToB->asCallable());
        $xportToA = new \WpSync\Test\Harness\InProcessTransport($csA, $rsA);
        $httpToA = new HttpChunkStore('http://a', 'u', 'p', $xportToA->asCallable());

        $filters = ['wp_options' => fn($r) => RowNormalizer::isExcludedOption((string)$r['option_name']) ? null : $r];

        // --- A commits & pushes to B ---
        $snapA = new Snapshotter($dbA, $csA, 'https://site-a.test');
        $cA1 = $snapA->commit(SchemaInspector::listTables($dbA), 'alice', 'from A', [], null, $filters);
        $rsA->casRef('refs/heads/main', null, $cA1);
        (new PushCommand(new SyncEngine(), $csA, $rsA, $httpToB))->run();

        // --- B pulls (fetch + materialize) ---
        $fetchB = new FetchCommand(new SyncEngine(), $csB, $rsB, $httpToB);
        $r = $fetchB->run();
        assertEq($cA1, $r['hash']);
        (new Materializer($dbB, $csB, 'https://site-b.test'))->materialize($cA1, null, $filters);
        // Fast-forward B's main to the fetched commit.
        $rsB->casRef('refs/heads/main', null, $cA1);

        // Verify B sees A's post (with URL rewritten).
        $res = $dbB->query("SELECT post_title, post_content FROM wp_posts");
        $row = $res->fetchArray(SQLITE3_ASSOC);
        assertEq('Post from A', $row['post_title']);
        assertEq('<img src="https://site-b.test/a.png">', $row['post_content']);

        // --- B makes changes and pushes back to A ---
        \WpSync\Test\Harness\WpFixture::insertPost($dbB, 'Post from B', 'hello');
        $snapB = new Snapshotter($dbB, $csB, 'https://site-b.test');
        $cB1 = $snapB->commit(
            SchemaInspector::listTables($dbB), 'bob', 'from B',
            [$cA1], $cA1 ? \WpSync\Snapshot\Snapshotter::loadCommit($cA1, $csB)['root'] : null,
            $filters,
        );
        $rsB->casRef('refs/heads/main', $cA1, $cB1);
        (new PushCommand(new SyncEngine(), $csB, $rsB, $httpToA))->run();

        // --- A fetches + materializes ---
        $fetchA = new FetchCommand(new SyncEngine(), $csA, $rsA, $httpToA);
        $rA = $fetchA->run();
        assertEq($cB1, $rA['hash']);
        (new Materializer($dbA, $csA, 'https://site-a.test'))->materialize($cB1, null, $filters);
        // Fast-forward A's main.
        $rsA->casRef('refs/heads/main', $cA1, $cB1);

        // Verify A has BOTH posts, with A's URL on the image.
        $res = $dbA->query("SELECT post_title, post_content FROM wp_posts ORDER BY post_title");
        $rows = []; while ($r = $res->fetchArray(SQLITE3_ASSOC)) $rows[] = $r;
        assertEq(2, count($rows));
        assertEq('Post from A', $rows[0]['post_title']);
        // A's original image URL should still be A's (placeholder->a on materialize).
        assertEq('<img src="https://site-a.test/a.png">', $rows[0]['post_content']);
        assertEq('Post from B', $rows[1]['post_title']);

        // A's siteurl must be unchanged.
        $res = $dbA->query("SELECT option_value FROM wp_options WHERE option_name='siteurl'");
        assertEq('https://site-a.test', $res->fetchArray(SQLITE3_ASSOC)['option_value']);
    });

    TestRun::it('empty commit has same db root hash as parent', function () {
        $db = \WpSync\Test\Harness\WpFixture::createDb('https://a.test');
        $p = tempnam(sys_get_temp_dir(), 'cs_'); unlink($p);
        $cs = new SQLiteChunkStore($p);
        $filters = ['wp_options' => fn($r) => RowNormalizer::isExcludedOption((string)$r['option_name']) ? null : $r];
        $snap = new Snapshotter($db, $cs, 'https://a.test');
        $c1 = $snap->commit(SchemaInspector::listTables($db), 'a', 'first', [], null, $filters);

        // Second commit, no changes.
        \WpSync\Snapshot\DirtyTracker::install($db, SchemaInspector::listTables($db));
        // Without dirty tracking the second commit rebuilds everything but the
        // deterministic tree still produces the same root. Verify.
        $c2 = $snap->commit(SchemaInspector::listTables($db), 'a', 'empty', [$c1],
            \WpSync\Snapshot\Snapshotter::loadCommit($c1, $cs)['root'],
            $filters);
        assertNotEq($c1, $c2);
        $r1 = \WpSync\Snapshot\Snapshotter::loadCommit($c1, $cs)['root'];
        $r2 = \WpSync\Snapshot\Snapshotter::loadCommit($c2, $cs)['root'];
        assertEq($r1, $r2);
    });
});
