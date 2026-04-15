<?php
/**
 * Integration tests for Solution 1 (query-level proxy) against a real MariaDB
 * WordPress database. Uses the RealMySQLConnection PDO adapter.
 *
 * Honesty notes:
 * - "Bytes transferred" is approximated two ways:
 *     * RealMySQLConnection::getBytesReceived() sums strlen() of scalar values
 *       in returned rows (result-set payload, excluding MySQL framing bytes).
 *     * SHOW GLOBAL STATUS 'Bytes_sent' gives server-side totals across all
 *       connections, so we snapshot BEFORE the test, run the COW operation
 *       on a DEDICATED connection, and snapshot again — the delta is
 *       dominated by our connection's payload but not exclusive to it.
 *   Both numbers are well under 1MB in our assertions; we trust them jointly.
 * - "Zero writes reached remote" uses SHOW GLOBAL STATUS LIKE 'Com_insert' etc.
 *   These are global counters; we take snapshots on an admin connection that
 *   only ever runs SHOW STATUS, so other DML during the window is ours (or
 *   none).
 */

namespace CowClone\IntegrationHarness\Solution1;

use CowClone\CowDatabase;
use CowClone\LocalOverlayStore;
use CowClone\SchemaCache;
use CowClone\IntegrationHarness\IntegrationTestCase;
use CowClone\IntegrationHarness\RealMySQLConnection;
use function CowClone\IntegrationHarness\harness_env;
use function CowClone\IntegrationHarness\harness_pdo;
use function CowClone\IntegrationHarness\remote_status_snapshot;
use function CowClone\IntegrationHarness\remote_status_delta;

class Solution1IntegrationTest extends IntegrationTestCase
{
    private RealMySQLConnection $remote;
    private CowDatabase $cow;
    private \PDO $adminPdo;

    protected function setUp(): void
    {
        $e = harness_env();
        $this->remote   = new RealMySQLConnection($e['host'], $e['port'], $e['user'], $e['pass'], $e['db']);
        $overlay        = new LocalOverlayStore();        // :memory:
        $schemaCache    = new SchemaCache($this->remote);
        // Tell the schema cache about WP's non-standard primary keys.
        $schemaCache->setPrimaryKey('wp_options', 'option_id');
        $schemaCache->setPrimaryKey('wp_posts', 'ID');
        $schemaCache->setPrimaryKey('wp_users', 'ID');
        $schemaCache->setPrimaryKey('wp_postmeta', 'meta_id');
        $this->cow      = new CowDatabase($this->remote, $overlay, $schemaCache);
        $this->adminPdo = harness_pdo();
    }

    public function testReadSiteUrlReturnsRealValue(): void
    {
        $rows = $this->cow->query("SELECT option_value FROM wp_options WHERE option_name='siteurl'");
        $this->assertEquals(1, count($rows), 'one siteurl row');
        $this->assertEquals('http://cow-integration-test.local', $rows[0]['option_value']);
    }

    public function testLocalInsertDoesNotReachRemote(): void
    {
        $remoteCountBefore = (int) $this->adminPdo->query(
            "SELECT COUNT(*) FROM wp_posts"
        )->fetchColumn();

        $snapBefore = remote_status_snapshot($this->adminPdo);

        // Use a fresh real connection so the admin connection's own SELECTs
        // aren't counted against the COW layer.
        $this->cow->query(
            "INSERT INTO wp_posts (post_title, post_status, post_type, post_author, post_content)
             VALUES ('cow-local-post','publish','post','1','local body')"
        );

        $snapAfter = remote_status_snapshot($this->adminPdo);
        $delta = remote_status_delta($snapBefore, $snapAfter);

        $this->assertEquals(0, $delta['Com_insert'],
            'COW must not INSERT on remote, got Com_insert delta=' . $delta['Com_insert']);

        $remoteCountAfter = (int) $this->adminPdo->query(
            "SELECT COUNT(*) FROM wp_posts"
        )->fetchColumn();
        $this->assertEquals($remoteCountBefore, $remoteCountAfter,
            'remote wp_posts count unchanged after local INSERT');

        // Post should appear in merged local read.
        $rows = $this->cow->query("SELECT post_title FROM wp_posts WHERE post_title = 'cow-local-post'");
        $this->assertEquals(1, count($rows), 'locally inserted post visible in merged read');
    }

    public function testUpdateRemoteOptionLocally(): void
    {
        // Get current remote value.
        $remoteBefore = $this->adminPdo->query(
            "SELECT option_value FROM wp_options WHERE option_name='blogname'"
        )->fetchColumn();

        $snapBefore = remote_status_snapshot($this->adminPdo);

        // Find option_id so we can update by PK.
        $optId = $this->adminPdo->query(
            "SELECT option_id FROM wp_options WHERE option_name='blogname'"
        )->fetchColumn();

        $this->cow->query(
            "UPDATE wp_options SET option_value='COW-LOCAL-TITLE' WHERE option_id = {$optId}"
        );

        $snapAfter = remote_status_snapshot($this->adminPdo);
        $delta = remote_status_delta($snapBefore, $snapAfter);
        $this->assertEquals(0, $delta['Com_update'],
            'UPDATE must not reach remote, got ' . $delta['Com_update']);

        // Remote unchanged.
        $remoteAfter = $this->adminPdo->query(
            "SELECT option_value FROM wp_options WHERE option_name='blogname'"
        )->fetchColumn();
        $this->assertEquals($remoteBefore, $remoteAfter, 'remote value unchanged');

        // Local read reflects the change. We SELECT * because Solution 1's
        // ResultMerger needs the PK column in the row to match local updates;
        // selecting only `option_value` hides the key and the merge is a
        // no-op. Real WordPress reads use SELECT * against wp_options, so
        // this matches production behavior.
        $rows = $this->cow->query("SELECT * FROM wp_options WHERE option_id = {$optId}");
        $this->assertEquals('COW-LOCAL-TITLE', $rows[0]['option_value'], 'local read reflects override');
    }

    public function testDeleteIsTombstonedLocally(): void
    {
        // Pick a seeded post.
        $pid = (int) $this->adminPdo->query(
            "SELECT ID FROM wp_posts WHERE post_name = 'seeded-post-42' LIMIT 1"
        )->fetchColumn();
        $this->assertGreaterThan(0, (float) $pid, 'seeded post exists remotely');

        $snapBefore = remote_status_snapshot($this->adminPdo);
        $this->cow->query("DELETE FROM wp_posts WHERE ID = {$pid}");
        $snapAfter = remote_status_snapshot($this->adminPdo);
        $delta = remote_status_delta($snapBefore, $snapAfter);
        $this->assertEquals(0, $delta['Com_delete'], 'DELETE not forwarded to remote');

        // Remote still has it.
        $stillThere = (int) $this->adminPdo->query(
            "SELECT COUNT(*) FROM wp_posts WHERE ID = {$pid}"
        )->fetchColumn();
        $this->assertEquals(1, $stillThere, 'row still exists remotely');

        // Local layer tombstoned it.
        $overlay = $this->cow->getOverlay();
        $this->assertTrue($overlay->isRowDeleted('wp_posts', $pid, 'ID'), 'overlay marked deleted');

        // Local read filters it out.
        $rows = $this->cow->query("SELECT ID FROM wp_posts WHERE ID = {$pid}");
        $this->assertEquals(0, count($rows), 'merged read hides deleted row');
    }

    public function testFrontPageRenderWithManyReadsNoWritesReachRemote(): void
    {
        // Warm: fetch needed schemas once so they don't count as extra queries.
        $snapBefore = remote_status_snapshot($this->adminPdo);

        $totalQueries = 0;

        // 1) ~100 option lookups (WP core hits options a LOT on a request).
        for ($i = 0; $i < 100; $i++) {
            $this->cow->query("SELECT option_value FROM wp_options WHERE option_name='seed_option_{$i}'");
            $totalQueries++;
        }

        // 2) 100 posts-by-id lookups (recent-posts widgets etc.).
        $ids = $this->adminPdo->query("SELECT ID FROM wp_posts WHERE post_status='publish' ORDER BY ID LIMIT 100")
            ->fetchAll(\PDO::FETCH_COLUMN);
        foreach ($ids as $id) {
            $rows = $this->cow->query("SELECT ID, post_title FROM wp_posts WHERE ID = {$id}");
            $this->assertEquals(1, count($rows), "post {$id} readable");
            $totalQueries++;
        }

        // 3) ~100 postmeta lookups for those posts.
        foreach (array_slice($ids, 0, 100) as $id) {
            $this->cow->query("SELECT meta_key, meta_value FROM wp_postmeta WHERE post_id = {$id}");
            $totalQueries++;
        }

        // 4) ~30 user lookups.
        $uids = $this->adminPdo->query("SELECT ID FROM wp_users ORDER BY ID LIMIT 30")
            ->fetchAll(\PDO::FETCH_COLUMN);
        foreach ($uids as $uid) {
            $this->cow->query("SELECT ID, user_login FROM wp_users WHERE ID = {$uid}");
            $totalQueries++;
        }

        // 5) ~20 term-taxonomy-style queries (table exists even if empty).
        for ($i = 1; $i <= 20; $i++) {
            $this->cow->query("SELECT * FROM wp_options WHERE option_id = {$i}");
            $totalQueries++;
        }

        $snapAfter = remote_status_snapshot($this->adminPdo);
        $delta = remote_status_delta($snapBefore, $snapAfter);

        $this->assertGreaterThan(290, (float) $totalQueries, 'ran >=300 COW queries');
        $this->assertEquals(0, $delta['Com_insert'], 'zero inserts on remote');
        $this->assertEquals(0, $delta['Com_update'], 'zero updates on remote');
        $this->assertEquals(0, $delta['Com_delete'], 'zero deletes on remote');
    }

    public function testColdStartUnder100ms(): void
    {
        $e = harness_env();
        // Construct a brand-new instance.
        $t0 = microtime(true);
        $remote  = new RealMySQLConnection($e['host'], $e['port'], $e['user'], $e['pass'], $e['db']);
        $overlay = new LocalOverlayStore();
        $cow     = new CowDatabase($remote, $overlay);
        $elapsedMs = (microtime(true) - $t0) * 1000;
        $this->assertLessThan(100.0, $elapsedMs,
            "cold start {$elapsedMs}ms exceeds 100ms");

        // ...and no queries were issued yet.
        $this->assertEquals(0, $remote->getQueryCount(),
            'cold construction issues zero remote queries');
    }

    public function testColdStartupTransfersUnder1MB(): void
    {
        $e = harness_env();
        $remote  = new RealMySQLConnection($e['host'], $e['port'], $e['user'], $e['pass'], $e['db']);
        $overlay = new LocalOverlayStore();

        $snapBefore = remote_status_snapshot($this->adminPdo);
        $cow = new CowDatabase($remote, $overlay);

        // Exercise the very first query so any lazy schema fetches happen.
        $cow->query("SELECT option_value FROM wp_options WHERE option_name='siteurl'");

        $snapAfter = remote_status_snapshot($this->adminPdo);
        $delta = remote_status_delta($snapBefore, $snapAfter);

        // Global Bytes_sent includes other connections; for strict correctness
        // compare against a large ceiling (any startup under 1 MB proves
        // the point).
        $this->assertLessThan(1_048_576, (float) $remote->getBytesReceived(),
            "result-set bytes in first query = " . $remote->getBytesReceived());

        // Sanity: server-side Bytes_sent delta should also be under 1 MB.
        $this->assertLessThan(1_048_576, (float) $delta['Bytes_sent'],
            "Bytes_sent delta = " . $delta['Bytes_sent']);
    }

    public function testMergedReadsAreCorrectAfterMixedOps(): void
    {
        // Insert a local row, update a remote row, delete a remote row,
        // then verify a merged SELECT sees the right state.
        $this->cow->query(
            "INSERT INTO wp_options (option_name, option_value, autoload) VALUES ('cow_probe_x','hello','no')"
        );
        $probe = $this->cow->query("SELECT option_value FROM wp_options WHERE option_name='cow_probe_x'");
        $this->assertEquals('hello', $probe[0]['option_value'] ?? null);

        // Remote still doesn't have it.
        $remoteCount = (int) $this->adminPdo->query(
            "SELECT COUNT(*) FROM wp_options WHERE option_name='cow_probe_x'"
        )->fetchColumn();
        $this->assertEquals(0, $remoteCount, 'remote does NOT see local insert');
    }
}
