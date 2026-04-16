<?php

namespace CowClone\Tests;

require_once __DIR__ . '/../src/QueryClassifier.php';
require_once __DIR__ . '/../src/LocalOverlayStore.php';
require_once __DIR__ . '/../src/RemoteMySQLConnection.php';
require_once __DIR__ . '/../src/SchemaCache.php';
require_once __DIR__ . '/../src/ResultMerger.php';
require_once __DIR__ . '/../src/CowDatabase.php';

use CowClone\CowDatabase;
use CowClone\LocalOverlayStore;
use CowClone\MockRemoteConnection;

class StartupTimeTest
{
    private int $passed = 0;
    private int $failed = 0;
    private array $failures = [];

    public function run(): array
    {
        $this->testStartupTimeSmallDatabase();
        $this->testStartupTimeLargeDatabase();
        $this->testStartupTimeIndependentOfSize();
        $this->testFirstQueryDoesNotLoadAllRows();
        $this->testNoRemoteQueriesOnStartup();

        return [
            'class' => static::class,
            'passed' => $this->passed,
            'failed' => $this->failed,
            'failures' => $this->failures,
        ];
    }

    private function assert(bool $condition, string $message): void
    {
        if ($condition) {
            $this->passed++;
        } else {
            $this->failed++;
            $this->failures[] = $message;
        }
    }

    private function testStartupTimeSmallDatabase(): void
    {
        $start = microtime(true);

        $remote = new MockRemoteConnection();
        $remote->addTable('wp_posts', ['id' => 'INTEGER', 'title' => 'VARCHAR(255)'], [
            ['id' => '1', 'title' => 'Post 1'],
        ]);

        $overlay = new LocalOverlayStore();
        $db = new CowDatabase($remote, $overlay);

        $elapsed = (microtime(true) - $start) * 1000; // ms

        $this->assert(
            $elapsed < 50,
            "Small DB startup should be < 50ms, was {$elapsed}ms"
        );
    }

    private function testStartupTimeLargeDatabase(): void
    {
        $start = microtime(true);

        $remote = new MockRemoteConnection();
        // Simulate a large database without actually creating rows
        $remote->setSimulatedRowCount(1000000);
        $remote->addTable('wp_posts', ['id' => 'INTEGER', 'title' => 'VARCHAR(255)'], [
            // Only a few actual rows - the mock simulates 1M rows
            ['id' => '1', 'title' => 'Post 1'],
            ['id' => '2', 'title' => 'Post 2'],
        ]);
        $remote->addTable('wp_postmeta', ['meta_id' => 'INTEGER', 'post_id' => 'INTEGER', 'meta_key' => 'VARCHAR(255)'], []);
        $remote->addTable('wp_options', ['option_id' => 'INTEGER', 'option_name' => 'VARCHAR(255)'], []);
        $remote->addTable('wp_users', ['ID' => 'INTEGER', 'user_login' => 'VARCHAR(60)'], []);
        $remote->addTable('wp_usermeta', ['umeta_id' => 'INTEGER', 'user_id' => 'INTEGER'], []);
        $remote->addTable('wp_terms', ['term_id' => 'INTEGER', 'name' => 'VARCHAR(200)'], []);
        $remote->addTable('wp_term_taxonomy', ['term_taxonomy_id' => 'INTEGER'], []);
        $remote->addTable('wp_term_relationships', ['object_id' => 'INTEGER'], []);
        $remote->addTable('wp_comments', ['comment_ID' => 'INTEGER', 'comment_content' => 'TEXT'], []);
        $remote->addTable('wp_commentmeta', ['meta_id' => 'INTEGER'], []);
        $remote->addTable('wp_links', ['link_id' => 'INTEGER'], []);

        $overlay = new LocalOverlayStore();
        $db = new CowDatabase($remote, $overlay);

        $elapsed = (microtime(true) - $start) * 1000;

        $this->assert(
            $elapsed < 50,
            "Large DB (simulated 1M rows, 11 tables) startup should be < 50ms, was {$elapsed}ms"
        );
    }

    private function testStartupTimeIndependentOfSize(): void
    {
        // Time startup with small config
        $start1 = microtime(true);
        $remote1 = new MockRemoteConnection();
        $remote1->addTable('t', ['id' => 'INTEGER'], [['id' => '1']]);
        $overlay1 = new LocalOverlayStore();
        $db1 = new CowDatabase($remote1, $overlay1);
        $small = (microtime(true) - $start1) * 1000;

        // Time startup with large config
        $start2 = microtime(true);
        $remote2 = new MockRemoteConnection();
        $remote2->setSimulatedRowCount(1000000);
        for ($i = 0; $i < 20; $i++) {
            $remote2->addTable("table_{$i}", ['id' => 'INTEGER'], [['id' => '1']]);
        }
        $overlay2 = new LocalOverlayStore();
        $db2 = new CowDatabase($remote2, $overlay2);
        $large = (microtime(true) - $start2) * 1000;

        // Large should not be significantly slower than small
        // Allow 10x tolerance (both should be sub-ms anyway)
        $ratio = $small > 0 ? $large / $small : 1;
        $this->assert(
            $ratio < 10,
            "Startup time ratio large/small should be < 10x, was {$ratio}x (small={$small}ms, large={$large}ms)"
        );
    }

    private function testFirstQueryDoesNotLoadAllRows(): void
    {
        $remote = new MockRemoteConnection();
        $remote->setSimulatedRowCount(1000000);
        $remote->addTable('wp_posts', ['id' => 'INTEGER', 'title' => 'VARCHAR(255)'], [
            ['id' => '1', 'title' => 'Post 1'],
        ]);

        $overlay = new LocalOverlayStore();
        $db = new CowDatabase($remote, $overlay);

        // The first query should be fast because it only fetches matching rows,
        // not the entire "1 million row" table
        $start = microtime(true);
        $results = $db->query("SELECT * FROM wp_posts WHERE id = 1");
        $elapsed = (microtime(true) - $start) * 1000;

        $this->assert(
            $elapsed < 50,
            "First query should be < 50ms even with 'large' remote, was {$elapsed}ms"
        );
        $this->assert(
            count($results) === 1,
            "Should return 1 result"
        );
    }

    private function testNoRemoteQueriesOnStartup(): void
    {
        $remote = new QueryCountingRemoteConnection();
        $remote->addTable('wp_posts', ['id' => 'INTEGER', 'title' => 'VARCHAR(255)'], [
            ['id' => '1', 'title' => 'Post 1'],
        ]);
        $remote->addTable('wp_options', ['id' => 'INTEGER', 'option_name' => 'VARCHAR(255)'], [
            ['id' => '1', 'option_name' => 'siteurl'],
        ]);

        // Reset the counter after addTable calls (which don't send queries, but just to be safe)
        $remote->resetQueryCount();

        $overlay = new LocalOverlayStore();

        // Construct CowDatabase -- this should send ZERO queries to remote
        $db = new CowDatabase($remote, $overlay);

        $this->assert(
            $remote->getTotalQueryCount() === 0,
            "NoRemoteQueriesOnStartup: constructing CowDatabase should send 0 queries to remote, got " . $remote->getTotalQueryCount()
        );

        // Now actually issue a query to prove the counter works
        $db->query("SELECT * FROM wp_posts");
        $this->assert(
            $remote->getTotalQueryCount() > 0,
            "NoRemoteQueriesOnStartup: after first SELECT, remote should have received at least 1 query"
        );
    }
}

/**
 * A wrapper around MockRemoteConnection that counts ALL queries (reads + writes).
 */
class QueryCountingRemoteConnection extends MockRemoteConnection
{
    private int $totalQueryCount = 0;

    public function query(string $sql): array
    {
        $this->totalQueryCount++;
        return parent::query($sql);
    }

    public function getTableSchema(string $table): ?array
    {
        $this->totalQueryCount++;
        return parent::getTableSchema($table);
    }

    public function getTotalQueryCount(): int
    {
        return $this->totalQueryCount;
    }

    public function resetQueryCount(): void
    {
        $this->totalQueryCount = 0;
    }
}
