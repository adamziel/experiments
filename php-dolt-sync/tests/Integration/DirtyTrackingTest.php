<?php
declare(strict_types=1);

use WpSync\Snapshot\DirtyTracker;
use WpSync\Snapshot\SchemaInspector;
use WpSync\Snapshot\Materializer;
use WpSync\Snapshot\Snapshotter;
use WpSync\Snapshot\RowNormalizer;
use WpSync\Store\SQLiteChunkStore;

require_once __DIR__ . '/../harness/WpFixture.php';

TestRun::group('DirtyTracking', function () {
    TestRun::it('INSERT records dirty row', function () {
        $db = \WpSync\Test\Harness\WpFixture::createDb();
        DirtyTracker::install($db, SchemaInspector::listTables($db));
        DirtyTracker::clear($db);
        \WpSync\Test\Harness\WpFixture::insertPost($db, 'x', 'y');
        $res = $db->query('SELECT tbl, op FROM _sync_dirty');
        $rows = []; while ($r = $res->fetchArray(SQLITE3_ASSOC)) $rows[] = $r;
        assertTrue(count($rows) >= 1);
        $ops = array_column($rows, 'op');
        assertTrue(in_array('I', $ops, true));
    });

    TestRun::it('UPDATE then INSERT collapse (last op wins per rowid)', function () {
        $db = \WpSync\Test\Harness\WpFixture::createDb();
        DirtyTracker::install($db, SchemaInspector::listTables($db));
        DirtyTracker::clear($db);
        $id = \WpSync\Test\Harness\WpFixture::insertPost($db, 'x', 'y');
        $db->exec("UPDATE wp_posts SET post_title='z' WHERE ID={$id}");
        $res = $db->query("SELECT op FROM _sync_dirty WHERE tbl='wp_posts' AND rowid={$id}");
        $row = $res->fetchArray(SQLITE3_ASSOC);
        assertEq('U', $row['op']);
    });

    TestRun::it('materialize does not populate dirty log', function () {
        $src = \WpSync\Test\Harness\WpFixture::createDb('https://a.test');
        \WpSync\Test\Harness\WpFixture::insertPost($src, 'Post', 'content');

        $p = tempnam(sys_get_temp_dir(), 'cs_'); unlink($p);
        $cs = new SQLiteChunkStore($p);
        $filters = ['wp_options' => fn($r) => RowNormalizer::isExcludedOption((string)$r['option_name']) ? null : $r];
        $commit = (new Snapshotter($src, $cs, 'https://a.test'))
            ->commit(SchemaInspector::listTables($src), 'a', 'm', [], null, $filters);

        $dst = \WpSync\Test\Harness\WpFixture::createDb('https://b.test');
        DirtyTracker::install($dst, SchemaInspector::listTables($dst));
        DirtyTracker::clear($dst);
        (new Materializer($dst, $cs, 'https://b.test'))->materialize($commit, null, $filters);
        $res = $dst->query('SELECT COUNT(*) AS n FROM _sync_dirty');
        assertEq(0, (int)$res->fetchArray(SQLITE3_ASSOC)['n']);
    });

    TestRun::it('commit reuses unchanged table tree when only one table is dirty', function () {
        $src = \WpSync\Test\Harness\WpFixture::createDb('https://a.test');
        DirtyTracker::install($src, SchemaInspector::listTables($src));
        \WpSync\Test\Harness\WpFixture::insertPost($src, 'Post', 'content');

        $p = tempnam(sys_get_temp_dir(), 'cs_'); unlink($p);
        $cs = new SQLiteChunkStore($p);
        $filters = ['wp_options' => fn($r) => RowNormalizer::isExcludedOption((string)$r['option_name']) ? null : $r];
        $snap = new Snapshotter($src, $cs, 'https://a.test');
        $tables = SchemaInspector::listTables($src);
        $c1 = $snap->commit($tables, 'a', 'first', [], null, $filters);

        // Only modify wp_options (add a non-excluded one).
        $src->exec("INSERT INTO wp_options(option_name, option_value) VALUES('new_opt', 'v')");

        // Load prior root's table tree for wp_posts.
        $commit1 = Snapshotter::loadCommit($c1, $cs);
        $priorRoot = Snapshotter::loadRoot($commit1['root'], $cs);
        $priorPostsTree = $priorRoot['tables']['wp_posts']['tree'];

        $c2 = $snap->commit($tables, 'a', 'second', [$c1], $commit1['root'], $filters);
        $commit2 = Snapshotter::loadCommit($c2, $cs);
        $newRoot = Snapshotter::loadRoot($commit2['root'], $cs);
        assertEq($priorPostsTree, $newRoot['tables']['wp_posts']['tree'],
            'unchanged wp_posts tree should be reused');
    });
});
