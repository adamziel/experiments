<?php
/**
 * Integration tests for Solution 3 (table-level materialization) against a
 * real MariaDB WordPress database. Uses RealRemoteTableClient.
 *
 * Key behavior of Solution 3:
 *   - Constructor eagerly calls listTables() — 1 remote query. "Cold start"
 *     is therefore "constructor completes in <100ms", which includes that
 *     single round-trip.
 *   - On the first query that references a table, the ENTIRE table is fetched
 *     and imported into local SQLite. After that, all ops are local.
 *   - Writes go to local SQLite and are logged in the journal; they never
 *     reach the remote (we verify via Com_insert/Com_update/Com_delete).
 *
 * Honesty notes:
 *   - "Bytes transferred from remote" is approximated in two ways, same as
 *     solution 1: strlen of scalar values in returned rows (payload-ish)
 *     plus SHOW GLOBAL STATUS 'Bytes_sent' delta.
 *   - For the <1MB cold-start assertion we measure bytes AFTER construction
 *     but BEFORE any table is referenced. Once a table is materialized the
 *     transfer can easily exceed 1MB for wp_posts etc. — we assert that
 *     large tables are ONLY fetched on first reference.
 */

namespace CowClone\IntegrationHarness\Solution3;

use CowClone\CowDatabase;
use CowClone\IntegrationHarness\IntegrationTestCase;
use CowClone\IntegrationHarness\RealRemoteTableClient;
use function CowClone\IntegrationHarness\harness_env;
use function CowClone\IntegrationHarness\harness_pdo;
use function CowClone\IntegrationHarness\remote_status_snapshot;
use function CowClone\IntegrationHarness\remote_status_delta;

class Solution3IntegrationTest extends IntegrationTestCase
{
    private RealRemoteTableClient $remote;
    private CowDatabase $cow;
    private \PDO $adminPdo;

    protected function setUp(): void
    {
        $e = harness_env();
        $this->remote = new RealRemoteTableClient(
            $e['host'], $e['port'], $e['user'], $e['pass'], $e['db']
        );
        $localPdo = new \PDO('sqlite::memory:');
        $this->cow = new CowDatabase($this->remote, $localPdo);
        $this->adminPdo = harness_pdo();
    }

    public function testReadSiteUrlReturnsRealValue(): void
    {
        $rows = $this->cow->query(
            "SELECT option_value FROM wp_options WHERE option_name = :n",
            [':n' => 'siteurl']
        );
        $this->assertEquals(1, count($rows));
        $this->assertEquals('http://cow-integration-test.local', $rows[0]['option_value']);

        // wp_options is now materialized; other tables should NOT be.
        $this->assertTrue($this->cow->getTracker()->isMaterialized('wp_options'));
        $this->assertFalse($this->cow->getTracker()->isMaterialized('wp_posts'),
            'wp_posts must NOT be materialized yet');
    }

    public function testLocalInsertDoesNotReachRemote(): void
    {
        $remoteCountBefore = (int) $this->adminPdo->query("SELECT COUNT(*) FROM wp_posts")->fetchColumn();
        $snapBefore = remote_status_snapshot($this->adminPdo);

        $this->cow->insert('wp_posts', [
            'post_author'           => 1,
            'post_date'             => '2025-01-01 00:00:00',
            'post_date_gmt'         => '2025-01-01 00:00:00',
            'post_content'          => 'local body',
            'post_title'            => 'cow-local-post',
            'post_excerpt'          => '',
            'post_status'           => 'publish',
            'comment_status'        => 'open',
            'ping_status'           => 'open',
            'post_password'         => '',
            'post_name'             => 'cow-local-post',
            'to_ping'               => '',
            'pinged'                => '',
            'post_modified'         => '2025-01-01 00:00:00',
            'post_modified_gmt'     => '2025-01-01 00:00:00',
            'post_content_filtered' => '',
            'post_parent'           => 0,
            'guid'                  => 'http://example.com/?p=cow',
            'menu_order'            => 0,
            'post_type'             => 'post',
            'post_mime_type'        => '',
            'comment_count'         => 0,
        ]);

        $snapAfter = remote_status_snapshot($this->adminPdo);
        $delta = remote_status_delta($snapBefore, $snapAfter);
        $this->assertEquals(0, $delta['Com_insert'], 'no INSERT reached remote');

        $remoteCountAfter = (int) $this->adminPdo->query("SELECT COUNT(*) FROM wp_posts")->fetchColumn();
        $this->assertEquals($remoteCountBefore, $remoteCountAfter);

        // Local read sees it.
        $rows = $this->cow->query("SELECT post_title FROM wp_posts WHERE post_title = :t",
            [':t' => 'cow-local-post']);
        $this->assertEquals(1, count($rows));
    }

    public function testUpdateLocalThenRemoteUnchanged(): void
    {
        $remoteBefore = $this->adminPdo->query(
            "SELECT option_value FROM wp_options WHERE option_name='blogname'"
        )->fetchColumn();

        $snapBefore = remote_status_snapshot($this->adminPdo);
        $this->cow->update(
            'wp_options',
            ['option_value' => 'COW-LOCAL-TITLE'],
            'option_name = :n',
            [':n' => 'blogname']
        );
        $snapAfter = remote_status_snapshot($this->adminPdo);
        $delta = remote_status_delta($snapBefore, $snapAfter);
        $this->assertEquals(0, $delta['Com_update']);

        $remoteAfter = $this->adminPdo->query(
            "SELECT option_value FROM wp_options WHERE option_name='blogname'"
        )->fetchColumn();
        $this->assertEquals($remoteBefore, $remoteAfter);

        $rows = $this->cow->query("SELECT option_value FROM wp_options WHERE option_name = :n",
            [':n' => 'blogname']);
        $this->assertEquals('COW-LOCAL-TITLE', $rows[0]['option_value']);
    }

    public function testDeleteLocalThenRemoteStillHasRow(): void
    {
        $pid = (int) $this->adminPdo->query(
            "SELECT ID FROM wp_posts WHERE post_name='seeded-post-42' LIMIT 1"
        )->fetchColumn();
        $this->assertGreaterThan(0, (float) $pid);

        $snapBefore = remote_status_snapshot($this->adminPdo);
        $affected = $this->cow->delete('wp_posts', 'ID = :id', [':id' => $pid]);
        $snapAfter = remote_status_snapshot($this->adminPdo);
        $delta = remote_status_delta($snapBefore, $snapAfter);

        $this->assertEquals(1, $affected, 'local DELETE removed 1 row');
        $this->assertEquals(0, $delta['Com_delete'], 'no DELETE on remote');

        $stillThere = (int) $this->adminPdo->query(
            "SELECT COUNT(*) FROM wp_posts WHERE ID = {$pid}"
        )->fetchColumn();
        $this->assertEquals(1, $stillThere);

        // Local read confirms it's gone.
        $rows = $this->cow->query("SELECT ID FROM wp_posts WHERE ID = :id", [':id' => $pid]);
        $this->assertEquals(0, count($rows));
    }

    public function testFrontPageRenderNoWritesReachRemote(): void
    {
        $snapBefore = remote_status_snapshot($this->adminPdo);
        $totalQueries = 0;

        // After the first reference to wp_options and wp_posts, both are
        // fully materialized locally -- so all further queries are local.
        for ($i = 0; $i < 100; $i++) {
            $this->cow->query(
                "SELECT option_value FROM wp_options WHERE option_name = :n",
                [':n' => "seed_option_{$i}"]
            );
            $totalQueries++;
        }
        $ids = $this->adminPdo->query(
            "SELECT ID FROM wp_posts WHERE post_status='publish' ORDER BY ID LIMIT 100"
        )->fetchAll(\PDO::FETCH_COLUMN);
        foreach ($ids as $id) {
            $this->cow->query("SELECT ID, post_title FROM wp_posts WHERE ID = :id", [':id' => $id]);
            $totalQueries++;
        }
        foreach (array_slice($ids, 0, 100) as $id) {
            $this->cow->query("SELECT meta_key, meta_value FROM wp_postmeta WHERE post_id = :id",
                [':id' => $id]);
            $totalQueries++;
        }
        $uids = $this->adminPdo->query("SELECT ID FROM wp_users ORDER BY ID LIMIT 30")
            ->fetchAll(\PDO::FETCH_COLUMN);
        foreach ($uids as $uid) {
            $this->cow->query("SELECT ID, user_login FROM wp_users WHERE ID = :id", [':id' => $uid]);
            $totalQueries++;
        }
        for ($i = 1; $i <= 20; $i++) {
            $this->cow->query("SELECT * FROM wp_options WHERE option_id = :i", [':i' => $i]);
            $totalQueries++;
        }

        $snapAfter = remote_status_snapshot($this->adminPdo);
        $delta = remote_status_delta($snapBefore, $snapAfter);

        $this->assertGreaterThan(290, (float) $totalQueries);
        $this->assertEquals(0, $delta['Com_insert']);
        $this->assertEquals(0, $delta['Com_update']);
        $this->assertEquals(0, $delta['Com_delete']);

        // Also assert: after materialization, MOST of the 300 queries did not
        // cause per-query remote traffic. Selects on remote should roughly
        // match materialization count + INFORMATION_SCHEMA lookups, not 300+.
        $this->assertLessThan(50, (float) $delta['Com_select'],
            "Com_select delta={$delta['Com_select']} — too many remote selects");
    }

    public function testColdStartUnder100ms(): void
    {
        $e = harness_env();
        $t0 = microtime(true);
        $remote = new RealRemoteTableClient(
            $e['host'], $e['port'], $e['user'], $e['pass'], $e['db']
        );
        $localPdo = new \PDO('sqlite::memory:');
        $cow = new CowDatabase($remote, $localPdo);
        $elapsedMs = (microtime(true) - $t0) * 1000;
        $this->assertLessThan(100.0, $elapsedMs, "cold start {$elapsedMs}ms > 100ms");
    }

    public function testColdStartupTransfersUnder1MB(): void
    {
        $e = harness_env();
        $snapBefore = remote_status_snapshot($this->adminPdo);
        $remote = new RealRemoteTableClient(
            $e['host'], $e['port'], $e['user'], $e['pass'], $e['db']
        );
        $localPdo = new \PDO('sqlite::memory:');
        $cow = new CowDatabase($remote, $localPdo);
        $snapAfter = remote_status_snapshot($this->adminPdo);
        $delta = remote_status_delta($snapBefore, $snapAfter);

        // Our approximated payload bytes:
        $this->assertLessThan(1_048_576, (float) $remote->bytesReceived,
            "bytesReceived after construction = " . $remote->bytesReceived);

        // Server-side Bytes_sent delta (includes framing).
        $this->assertLessThan(1_048_576, (float) $delta['Bytes_sent'],
            "Bytes_sent delta = " . $delta['Bytes_sent']);

        // No tables should be materialized yet.
        $this->assertEquals(0, count($cow->getTracker()->getMaterializedTables()),
            'no tables materialized at startup');
    }

    public function testLazyMaterialization(): void
    {
        // Nothing materialized at start.
        $this->assertEquals(0, count($this->cow->getTracker()->getMaterializedTables()));

        // Query wp_options -> only wp_options materializes.
        $this->cow->query("SELECT * FROM wp_options WHERE option_id = :i", [':i' => 1]);
        $mat = $this->cow->getTracker()->getMaterializedTables();
        $this->assertContains('wp_options', array_column($mat, 'table_name'));

        // wp_posts should still be un-materialized.
        $this->assertFalse($this->cow->getTracker()->isMaterialized('wp_posts'));

        // Second option query: no new materialization (fetchCount for
        // wp_options should stay at 1).
        $this->cow->query("SELECT * FROM wp_options WHERE option_id = :i", [':i' => 2]);
        $this->assertEquals(1, $this->remote->fetchCount['wp_options'] ?? 0,
            'wp_options fetched exactly once');
    }
}
