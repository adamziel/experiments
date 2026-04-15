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

class IntegrationTest
{
    private int $passed = 0;
    private int $failed = 0;
    private array $failures = [];

    public function run(): array
    {
        $this->testInsertAndSelect();
        $this->testUpdateAndSelect();
        $this->testDeleteAndSelect();
        $this->testNoWriteQueriesToRemote();
        $this->testMultipleOperationsCompose();
        $this->testSelectWithWhereAfterInsert();
        $this->testUpdateLocallyInsertedRow();
        $this->testDeleteLocallyInsertedRow();
        $this->testCreateTableAndInsert();
        $this->testSelectFromEmptyRemoteWithLocalInserts();
        $this->testRemoteDataNotModified();
        $this->testMultipleTablesIndependent();
        $this->testConcurrentReadAndWrite();
        $this->testUpdateNonExistentRowIsNoOp();
        $this->testDeleteThenInsertSameTable();
        $this->testLargeNumberOfLocalInserts();
        $this->testSelectWithLimitOnlyReturnsLimitedRows();

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

    private function assertEqual($expected, $actual, string $message): void
    {
        $this->assert(
            $expected === $actual,
            "$message: expected " . var_export($expected, true) . ", got " . var_export($actual, true)
        );
    }

    /**
     * Create a fresh CowDatabase with mock remote data.
     */
    private function createDatabase(array $tables = []): array
    {
        $remote = new MockRemoteConnection();
        foreach ($tables as $tableName => $config) {
            $remote->addTable(
                $tableName,
                $config['columns'] ?? ['id' => 'INTEGER', 'title' => 'VARCHAR(255)'],
                $config['rows'] ?? [],
                $config['primary_key'] ?? 'id'
            );
        }

        $overlay = new LocalOverlayStore();
        $db = new CowDatabase($remote, $overlay);

        return [$db, $remote, $overlay];
    }

    private function testInsertAndSelect(): void
    {
        /** @var CowDatabase $db */
        [$db, $remote] = $this->createDatabase([
            'wp_posts' => [
                'columns' => ['id' => 'INTEGER', 'title' => 'VARCHAR(255)', 'status' => 'VARCHAR(50)'],
                'rows' => [
                    ['id' => '1', 'title' => 'Existing Post', 'status' => 'publish'],
                ],
            ],
        ]);

        // Insert a new row locally
        $db->query("INSERT INTO wp_posts (title, status) VALUES ('New Post', 'draft')");

        // Select all - should see both remote and local
        $results = $db->query("SELECT * FROM wp_posts");
        $this->assertEqual(2, count($results), "Insert+Select: should see 2 rows");
        $this->assertEqual('Existing Post', $results[0]['title'], "First row from remote");
        $this->assertEqual('New Post', $results[1]['title'], "Second row inserted locally");
    }

    private function testUpdateAndSelect(): void
    {
        [$db, $remote] = $this->createDatabase([
            'wp_posts' => [
                'columns' => ['id' => 'INTEGER', 'title' => 'VARCHAR(255)'],
                'rows' => [
                    ['id' => '1', 'title' => 'Original Title'],
                    ['id' => '2', 'title' => 'Another Post'],
                ],
            ],
        ]);

        // Update row 1 locally
        $db->query("UPDATE wp_posts SET title = 'Modified Title' WHERE id = 1");

        // Select all - should see updated title
        $results = $db->query("SELECT * FROM wp_posts");
        $this->assertEqual(2, count($results), "Update+Select: should see 2 rows");
        $this->assertEqual('Modified Title', $results[0]['title'], "Row 1 should have updated title");
        $this->assertEqual('Another Post', $results[1]['title'], "Row 2 should be unchanged");
    }

    private function testDeleteAndSelect(): void
    {
        [$db, $remote] = $this->createDatabase([
            'wp_posts' => [
                'columns' => ['id' => 'INTEGER', 'title' => 'VARCHAR(255)'],
                'rows' => [
                    ['id' => '1', 'title' => 'Post 1'],
                    ['id' => '2', 'title' => 'Post 2'],
                    ['id' => '3', 'title' => 'Post 3'],
                ],
            ],
        ]);

        // Delete row 2 locally
        $db->query("DELETE FROM wp_posts WHERE id = 2");

        // Select all - should not see row 2
        $results = $db->query("SELECT * FROM wp_posts");
        $this->assertEqual(2, count($results), "Delete+Select: should see 2 rows");
        $this->assertEqual('1', $results[0]['id'], "First row should be id=1");
        $this->assertEqual('3', $results[1]['id'], "Second row should be id=3");
    }

    private function testNoWriteQueriesToRemote(): void
    {
        /** @var MockRemoteConnection $remote */
        [$db, $remote] = $this->createDatabase([
            'wp_posts' => [
                'columns' => ['id' => 'INTEGER', 'title' => 'VARCHAR(255)'],
                'rows' => [
                    ['id' => '1', 'title' => 'Post 1'],
                ],
            ],
        ]);

        // Perform various writes
        $db->query("INSERT INTO wp_posts (title) VALUES ('New')");
        $db->query("UPDATE wp_posts SET title = 'Changed' WHERE id = 1");
        $db->query("DELETE FROM wp_posts WHERE id = 1");

        // Remote should have received NO write queries
        $writes = $remote->getWriteQueries();
        $this->assertEqual(0, count($writes), "Remote should receive NO write queries, got " . count($writes));

        // Remote data should be unchanged
        $remoteData = $remote->getTableData('wp_posts');
        $this->assertEqual(1, count($remoteData), "Remote data should still have 1 row");
        $this->assertEqual('Post 1', $remoteData[0]['title'], "Remote title should be unchanged");
    }

    private function testMultipleOperationsCompose(): void
    {
        [$db] = $this->createDatabase([
            'wp_posts' => [
                'columns' => ['id' => 'INTEGER', 'title' => 'VARCHAR(255)', 'status' => 'VARCHAR(50)'],
                'rows' => [
                    ['id' => '1', 'title' => 'Post 1', 'status' => 'publish'],
                    ['id' => '2', 'title' => 'Post 2', 'status' => 'draft'],
                    ['id' => '3', 'title' => 'Post 3', 'status' => 'publish'],
                ],
            ],
        ]);

        // Compose multiple operations
        $db->query("DELETE FROM wp_posts WHERE id = 2");                          // Delete post 2
        $db->query("UPDATE wp_posts SET title = 'Updated Post 1' WHERE id = 1"); // Update post 1
        $db->query("INSERT INTO wp_posts (title, status) VALUES ('Post 4', 'publish')"); // Insert post 4
        $db->query("UPDATE wp_posts SET status = 'trash' WHERE id = 3");          // Update post 3

        $results = $db->query("SELECT * FROM wp_posts");
        $this->assertEqual(3, count($results), "Composed ops: 3 original - 1 deleted + 1 inserted = 3");

        // Verify each row
        $this->assertEqual('Updated Post 1', $results[0]['title'], "Post 1 title updated");
        $this->assertEqual('publish', $results[0]['status'], "Post 1 status unchanged");

        $this->assertEqual('3', $results[1]['id'], "Post 3 should be second");
        $this->assertEqual('trash', $results[1]['status'], "Post 3 status updated to trash");

        $this->assertEqual('Post 4', $results[2]['title'], "Post 4 inserted");
        $this->assertEqual('publish', $results[2]['status'], "Post 4 status is publish");
    }

    private function testSelectWithWhereAfterInsert(): void
    {
        [$db] = $this->createDatabase([
            'wp_posts' => [
                'columns' => ['id' => 'INTEGER', 'title' => 'VARCHAR(255)', 'status' => 'VARCHAR(50)'],
                'rows' => [
                    ['id' => '1', 'title' => 'Published', 'status' => 'publish'],
                ],
            ],
        ]);

        $db->query("INSERT INTO wp_posts (title, status) VALUES ('Draft Post', 'draft')");
        $db->query("INSERT INTO wp_posts (title, status) VALUES ('Another Published', 'publish')");

        // Select only published posts
        $results = $db->query("SELECT * FROM wp_posts WHERE status = 'publish'");
        $this->assertEqual(2, count($results), "WHERE filter: should see 2 published posts");
    }

    private function testUpdateLocallyInsertedRow(): void
    {
        [$db] = $this->createDatabase([
            'wp_posts' => [
                'columns' => ['id' => 'INTEGER', 'title' => 'VARCHAR(255)'],
                'rows' => [],
            ],
        ]);

        // Insert then update the same row
        $db->query("INSERT INTO wp_posts (title) VALUES ('Original')");

        // Get the inserted row to find its ID
        $results = $db->query("SELECT * FROM wp_posts");
        $this->assertEqual(1, count($results), "Should have 1 inserted row");
        $localId = $results[0]['id'];

        // Update it
        $db->query("UPDATE wp_posts SET title = 'Updated' WHERE id = {$localId}");

        $results = $db->query("SELECT * FROM wp_posts");
        $this->assertEqual(1, count($results), "Still 1 row after update");
        $this->assertEqual('Updated', $results[0]['title'], "Locally inserted row should be updated");
    }

    private function testDeleteLocallyInsertedRow(): void
    {
        [$db] = $this->createDatabase([
            'wp_posts' => [
                'columns' => ['id' => 'INTEGER', 'title' => 'VARCHAR(255)'],
                'rows' => [],
            ],
        ]);

        $db->query("INSERT INTO wp_posts (title) VALUES ('Temporary')");
        $results = $db->query("SELECT * FROM wp_posts");
        $this->assertEqual(1, count($results), "Should have 1 row after insert");

        $localId = $results[0]['id'];
        $db->query("DELETE FROM wp_posts WHERE id = {$localId}");

        $results = $db->query("SELECT * FROM wp_posts");
        $this->assertEqual(0, count($results), "Should have 0 rows after deleting local insert");
    }

    private function testCreateTableAndInsert(): void
    {
        [$db] = $this->createDatabase([]);

        // Create a new table locally
        $db->query("CREATE TABLE wp_custom (id INTEGER PRIMARY KEY, name VARCHAR(255), value TEXT)");

        // Insert into it
        $db->query("INSERT INTO wp_custom (name, value) VALUES ('setting1', 'hello')");

        // Query it
        $results = $db->query("SELECT * FROM wp_custom");
        $this->assertEqual(1, count($results), "Local table should have 1 row");
        $this->assertEqual('setting1', $results[0]['name'], "Should have correct name");
        $this->assertEqual('hello', $results[0]['value'], "Should have correct value");
    }

    private function testSelectFromEmptyRemoteWithLocalInserts(): void
    {
        [$db] = $this->createDatabase([
            'wp_options' => [
                'columns' => ['id' => 'INTEGER', 'option_name' => 'VARCHAR(255)', 'option_value' => 'TEXT'],
                'rows' => [],
            ],
        ]);

        $db->query("INSERT INTO wp_options (option_name, option_value) VALUES ('siteurl', 'http://localhost')");
        $db->query("INSERT INTO wp_options (option_name, option_value) VALUES ('blogname', 'Test Blog')");

        $results = $db->query("SELECT * FROM wp_options");
        $this->assertEqual(2, count($results), "Empty remote + 2 inserts = 2 rows");
    }

    private function testRemoteDataNotModified(): void
    {
        /** @var MockRemoteConnection $remote */
        [$db, $remote] = $this->createDatabase([
            'wp_posts' => [
                'columns' => ['id' => 'INTEGER', 'title' => 'VARCHAR(255)'],
                'rows' => [
                    ['id' => '1', 'title' => 'Original'],
                ],
            ],
        ]);

        // Do a bunch of operations
        $db->query("UPDATE wp_posts SET title = 'Changed' WHERE id = 1");
        $db->query("INSERT INTO wp_posts (title) VALUES ('New')");
        $db->query("DELETE FROM wp_posts WHERE id = 1");

        // Verify remote is completely untouched
        $remoteData = $remote->getTableData('wp_posts');
        $this->assertEqual(1, count($remoteData), "Remote should still have exactly 1 row");
        $this->assertEqual('Original', $remoteData[0]['title'], "Remote title should be 'Original'");
        $this->assertEqual(0, count($remote->getWriteQueries()), "Remote should have 0 write queries");
    }

    private function testMultipleTablesIndependent(): void
    {
        [$db] = $this->createDatabase([
            'wp_posts' => [
                'columns' => ['id' => 'INTEGER', 'title' => 'VARCHAR(255)'],
                'rows' => [
                    ['id' => '1', 'title' => 'Post 1'],
                ],
            ],
            'wp_options' => [
                'columns' => ['id' => 'INTEGER', 'option_name' => 'VARCHAR(255)', 'option_value' => 'TEXT'],
                'rows' => [
                    ['id' => '1', 'option_name' => 'siteurl', 'option_value' => 'http://example.com'],
                ],
            ],
        ]);

        // Modify posts
        $db->query("DELETE FROM wp_posts WHERE id = 1");
        $db->query("INSERT INTO wp_posts (title) VALUES ('New Post')");

        // Modify options
        $db->query("UPDATE wp_options SET option_value = 'http://localhost' WHERE id = 1");

        // Verify posts
        $posts = $db->query("SELECT * FROM wp_posts");
        $this->assertEqual(1, count($posts), "Posts should have 1 row (deleted + inserted)");
        $this->assertEqual('New Post', $posts[0]['title'], "Should be the new post");

        // Verify options (independent of posts changes)
        $options = $db->query("SELECT * FROM wp_options");
        $this->assertEqual(1, count($options), "Options should have 1 row");
        $this->assertEqual('http://localhost', $options[0]['option_value'], "Option value should be updated");
    }

    private function testConcurrentReadAndWrite(): void
    {
        [$db] = $this->createDatabase([
            'wp_posts' => [
                'columns' => ['id' => 'INTEGER', 'title' => 'VARCHAR(255)', 'status' => 'VARCHAR(50)'],
                'rows' => [
                    ['id' => '1', 'title' => 'Remote Post', 'status' => 'publish'],
                ],
            ],
        ]);

        // Write a new row, then immediately read back
        $db->query("INSERT INTO wp_posts (title, status) VALUES ('Local Post', 'draft')");
        $results = $db->query("SELECT * FROM wp_posts");
        $this->assertEqual(2, count($results), "ConcurrentRW: should see both remote and local rows");
        $this->assertEqual('Remote Post', $results[0]['title'], "ConcurrentRW: first row is remote");
        $this->assertEqual('Local Post', $results[1]['title'], "ConcurrentRW: second row is locally inserted");

        // Now update the remote row and read again
        $db->query("UPDATE wp_posts SET title = 'Updated Remote' WHERE id = 1");
        $results = $db->query("SELECT * FROM wp_posts");
        $this->assertEqual(2, count($results), "ConcurrentRW: still 2 rows after update");
        $this->assertEqual('Updated Remote', $results[0]['title'], "ConcurrentRW: remote row updated");
        $this->assertEqual('Local Post', $results[1]['title'], "ConcurrentRW: local row unchanged after remote update");

        // Delete the local row and read again
        $localId = $results[1]['id'];
        $db->query("DELETE FROM wp_posts WHERE id = {$localId}");
        $results = $db->query("SELECT * FROM wp_posts");
        $this->assertEqual(1, count($results), "ConcurrentRW: 1 row after deleting local insert");
        $this->assertEqual('Updated Remote', $results[0]['title'], "ConcurrentRW: only updated remote row remains");
    }

    private function testUpdateNonExistentRowIsNoOp(): void
    {
        [$db] = $this->createDatabase([
            'wp_posts' => [
                'columns' => ['id' => 'INTEGER', 'title' => 'VARCHAR(255)'],
                'rows' => [
                    ['id' => '1', 'title' => 'Post 1'],
                ],
            ],
        ]);

        // Update a row with a key that does not exist anywhere
        $affected = $db->query("UPDATE wp_posts SET title = 'Ghost' WHERE id = 999");
        $this->assertEqual(1, $affected, "UpdateNonExistent: returns 1 (records intent even for missing row)");

        // The existing data should be completely unchanged
        $results = $db->query("SELECT * FROM wp_posts");
        $this->assertEqual(1, count($results), "UpdateNonExistent: still exactly 1 row");
        $this->assertEqual('Post 1', $results[0]['title'], "UpdateNonExistent: original row title unchanged");
        $this->assertEqual('1', $results[0]['id'], "UpdateNonExistent: original row id unchanged");
    }

    private function testDeleteThenInsertSameTable(): void
    {
        [$db] = $this->createDatabase([
            'wp_posts' => [
                'columns' => ['id' => 'INTEGER', 'title' => 'VARCHAR(255)'],
                'rows' => [
                    ['id' => '1', 'title' => 'Old Post A'],
                    ['id' => '2', 'title' => 'Old Post B'],
                    ['id' => '3', 'title' => 'Old Post C'],
                ],
            ],
        ]);

        // Delete all remote rows one by one
        $db->query("DELETE FROM wp_posts WHERE id = 1");
        $db->query("DELETE FROM wp_posts WHERE id = 2");
        $db->query("DELETE FROM wp_posts WHERE id = 3");

        // Verify table appears empty
        $results = $db->query("SELECT * FROM wp_posts");
        $this->assertEqual(0, count($results), "DeleteThenInsert: 0 rows after deleting all remote rows");

        // Insert fresh rows
        $db->query("INSERT INTO wp_posts (title) VALUES ('New Post X')");
        $db->query("INSERT INTO wp_posts (title) VALUES ('New Post Y')");

        $results = $db->query("SELECT * FROM wp_posts");
        $this->assertEqual(2, count($results), "DeleteThenInsert: 2 new rows after inserting");
        $this->assertEqual('New Post X', $results[0]['title'], "DeleteThenInsert: first new row");
        $this->assertEqual('New Post Y', $results[1]['title'], "DeleteThenInsert: second new row");

        // Verify old rows do not leak through
        $titles = array_column($results, 'title');
        $this->assert(
            !in_array('Old Post A', $titles) && !in_array('Old Post B', $titles) && !in_array('Old Post C', $titles),
            "DeleteThenInsert: none of the old remote titles appear"
        );
    }

    private function testLargeNumberOfLocalInserts(): void
    {
        [$db] = $this->createDatabase([
            'wp_posts' => [
                'columns' => ['id' => 'INTEGER', 'title' => 'VARCHAR(255)'],
                'rows' => [
                    ['id' => '1', 'title' => 'Remote Anchor'],
                ],
            ],
        ]);

        // Insert 100 rows locally
        for ($i = 0; $i < 100; $i++) {
            $db->query("INSERT INTO wp_posts (title) VALUES ('Local Post {$i}')");
        }

        $results = $db->query("SELECT * FROM wp_posts");
        $this->assertEqual(101, count($results), "LargeInserts: 1 remote + 100 local = 101 rows");

        // First row should be the remote row
        $this->assertEqual('Remote Anchor', $results[0]['title'], "LargeInserts: first row is remote anchor");

        // Last row should be the 100th local insert
        $this->assertEqual('Local Post 99', $results[100]['title'], "LargeInserts: last row is Local Post 99");

        // Spot-check a row in the middle
        $this->assertEqual('Local Post 49', $results[50]['title'], "LargeInserts: middle row is Local Post 49");

        // Verify all local rows have negative IDs (auto-assigned)
        $allLocalNegative = true;
        for ($i = 1; $i <= 100; $i++) {
            if ((int)$results[$i]['id'] >= 0) {
                $allLocalNegative = false;
                break;
            }
        }
        $this->assert($allLocalNegative, "LargeInserts: all 100 local rows have negative (local) IDs");
    }

    private function testSelectWithLimitOnlyReturnsLimitedRows(): void
    {
        [$db] = $this->createDatabase([
            'wp_posts' => [
                'columns' => ['id' => 'INTEGER', 'title' => 'VARCHAR(255)'],
                'rows' => [
                    ['id' => '1', 'title' => 'Post 1'],
                    ['id' => '2', 'title' => 'Post 2'],
                    ['id' => '3', 'title' => 'Post 3'],
                    ['id' => '4', 'title' => 'Post 4'],
                    ['id' => '5', 'title' => 'Post 5'],
                ],
            ],
        ]);

        // LIMIT is applied by the mock remote's query method
        $results = $db->query("SELECT * FROM wp_posts LIMIT 2");
        // The remote returns at most 2 rows; local inserts (none here) are appended after
        $this->assert(
            count($results) <= 2,
            "LimitSelect: should return at most 2 rows, got " . count($results)
        );
        $this->assertEqual(2, count($results), "LimitSelect: exactly 2 rows with LIMIT 2");
        $this->assertEqual('Post 1', $results[0]['title'], "LimitSelect: first row is Post 1");
        $this->assertEqual('Post 2', $results[1]['title'], "LimitSelect: second row is Post 2");

        // LIMIT 1 should return a single row
        $results = $db->query("SELECT * FROM wp_posts LIMIT 1");
        $this->assertEqual(1, count($results), "LimitSelect: exactly 1 row with LIMIT 1");
        $this->assertEqual('Post 1', $results[0]['title'], "LimitSelect: single row is Post 1");
    }
}
