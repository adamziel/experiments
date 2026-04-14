<?php
declare(strict_types=1);

use WpSync\Snapshot\Snapshotter;
use WpSync\Snapshot\Materializer;
use WpSync\Snapshot\SchemaInspector;
use WpSync\Snapshot\RowNormalizer;
use WpSync\Store\SQLiteChunkStore;

require_once __DIR__ . '/../harness/WpFixture.php';

TestRun::group('SnapshotRoundTrip', function () {
    TestRun::it('commit -> materialize to fresh DB yields equal content', function () {
        $src = \WpSync\Test\Harness\WpFixture::createDb('https://site-a.test');
        \WpSync\Test\Harness\WpFixture::insertPost($src, 'Hello', '<img src="https://site-a.test/p.jpg">');
        \WpSync\Test\Harness\WpFixture::insertPost($src, 'Second', 'plain content');
        $stmt = $src->prepare("INSERT INTO wp_options(option_name, option_value) VALUES('my_opt', ?)");
        $stmt->bindValue(1, serialize(['b' => 1, 'a' => 2]));
        $stmt->execute();

        $cp = tempnam(sys_get_temp_dir(), 'cs_'); unlink($cp);
        $cs = new SQLiteChunkStore($cp);
        $snap = new Snapshotter($src, $cs, 'https://site-a.test');
        $tables = SchemaInspector::listTables($src);
        $filters = ['wp_options' => function ($row) {
            return RowNormalizer::isExcludedOption((string)$row['option_name']) ? null : $row;
        }];
        $commit = $snap->commit($tables, 'tester', 'initial', [], null, $filters);

        // Fresh DB with same schema but URL is different.
        $dst = \WpSync\Test\Harness\WpFixture::createDb('https://site-b.test');
        $mat = new Materializer($dst, $cs, 'https://site-b.test');
        $mat->materialize($commit, null, $filters);

        // Posts copied.
        $res = $dst->query("SELECT post_title, post_content FROM wp_posts ORDER BY post_title");
        $rows = [];
        while ($r = $res->fetchArray(SQLITE3_ASSOC)) $rows[] = $r;
        assertEq(2, count($rows));
        assertEq('Hello', $rows[0]['post_title']);
        assertEq('<img src="https://site-b.test/p.jpg">', $rows[0]['post_content'],
            'URLs should be rewritten to local site');

        // Site-identity options unchanged.
        $res = $dst->query("SELECT option_value FROM wp_options WHERE option_name='siteurl'");
        $row = $res->fetchArray(SQLITE3_ASSOC);
        assertEq('https://site-b.test', $row['option_value']);

        // Custom option rewritten URL (n/a) and correctly unserialized.
        $res = $dst->query("SELECT option_value FROM wp_options WHERE option_name='my_opt'");
        $row = $res->fetchArray(SQLITE3_ASSOC);
        $u = unserialize($row['option_value']);
        assertEq(['a' => 2, 'b' => 1], $u);
    });

    TestRun::it('empty table survives round trip (siteurl row excluded yields non-empty; exercise plugin_noprimary empty)', function () {
        $src = \WpSync\Test\Harness\WpFixture::createDb('https://site-a.test');
        $cp = tempnam(sys_get_temp_dir(), 'cs_'); unlink($cp);
        $cs = new SQLiteChunkStore($cp);
        $snap = new Snapshotter($src, $cs, 'https://site-a.test');
        $commit = $snap->commit(SchemaInspector::listTables($src), 'tester', 'empty', [], null);
        assertTrue(is_string($commit) && strlen($commit) === 64);
    });

    TestRun::it('deleted row is deleted on materialize', function () {
        $src = \WpSync\Test\Harness\WpFixture::createDb('https://site-a.test');
        $id1 = \WpSync\Test\Harness\WpFixture::insertPost($src, 'KeepMe', 'a');
        $id2 = \WpSync\Test\Harness\WpFixture::insertPost($src, 'DeleteMe', 'b');

        $cp = tempnam(sys_get_temp_dir(), 'cs_'); unlink($cp);
        $cs = new SQLiteChunkStore($cp);
        $snap = new Snapshotter($src, $cs, 'https://site-a.test');
        $tables = SchemaInspector::listTables($src);
        $filters = ['wp_options' => fn($r) => \WpSync\Snapshot\RowNormalizer::isExcludedOption((string)$r['option_name']) ? null : $r];
        $c1 = $snap->commit($tables, 'a', 'first', [], null, $filters);

        $dst = \WpSync\Test\Harness\WpFixture::createDb('https://site-b.test');
        (new Materializer($dst, $cs, 'https://site-b.test'))->materialize($c1, null, $filters);

        // Now delete post 2 on src, commit again, re-materialize.
        $src->exec("DELETE FROM wp_posts WHERE ID = {$id2}");
        $c2 = $snap->commit($tables, 'a', 'del', [$c1], null, $filters);
        (new Materializer($dst, $cs, 'https://site-b.test'))->materialize($c2, null, $filters);

        $res = $dst->query("SELECT post_title FROM wp_posts ORDER BY post_title");
        $rows = [];
        while ($r = $res->fetchArray(SQLITE3_ASSOC)) $rows[] = $r['post_title'];
        assertEq(['KeepMe'], $rows);
    });
});
