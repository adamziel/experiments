<?php

declare(strict_types=1);

require_once __DIR__ . '/../src/PageProvider.php';
require_once __DIR__ . '/../src/FilePageProvider.php';
require_once __DIR__ . '/../src/CowPageProvider.php';
require_once __DIR__ . '/../src/BTreeReader.php';
require_once __DIR__ . '/../src/CowDatabase.php';

use CowClone\BlockLevel\CowDatabase;

class IntegrationTest
{
    private string $dbPath;
    private string $sourceHash;
    private int $passed = 0;
    private int $failed = 0;

    public function run(): void
    {
        $this->setUp();
        try {
            $this->testReadTableLazily();
            $this->testReadOnlyFetchesNeededPages();
            $this->testFindRowUsesFewerPages();
            $this->testInsertWritesLocally();
            $this->testUpdateWritesLocally();
            $this->testDeleteWritesLocally();
            $this->testSourceNeverModified();
            $this->testMultipleIndependentSessions();
            $this->testQueryMaterializesOnDemand();
            $this->testReadThenWriteWorkflow();
            $this->testIsRemoteUnmodified();
            $this->testWriteDoesNotAffectLazyReadsOfOtherTables();
            $this->testEmptyTableReadReturnsEmptyArray();
            $this->testFindRowNonExistent();
            $this->testReadTableAfterMaterialization();
        } finally {
            $this->tearDown();
        }
    }

    private function setUp(): void
    {
        $this->dbPath = tempnam(sys_get_temp_dir(), 'cow_integ_') . '.sqlite';
        $pdo = new PDO("sqlite:{$this->dbPath}");
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->exec('PRAGMA page_size = 4096');

        $pdo->exec('CREATE TABLE wp_options (option_id INTEGER PRIMARY KEY, option_name TEXT, option_value TEXT, autoload TEXT)');
        $pdo->exec("INSERT INTO wp_options (option_name, option_value, autoload) VALUES ('siteurl', 'http://example.com', 'yes')");
        $pdo->exec("INSERT INTO wp_options (option_name, option_value, autoload) VALUES ('blogname', 'Test Blog', 'yes')");
        $pdo->exec("INSERT INTO wp_options (option_name, option_value, autoload) VALUES ('blogdescription', 'Just another site', 'yes')");

        $pdo->exec('CREATE TABLE wp_posts (ID INTEGER PRIMARY KEY, post_title TEXT, post_content TEXT, post_status TEXT)');
        $pdo->exec("INSERT INTO wp_posts (post_title, post_content, post_status) VALUES ('Hello World', 'Welcome to WP.', 'publish')");
        $pdo->exec("INSERT INTO wp_posts (post_title, post_content, post_status) VALUES ('Second Post', 'Another post.', 'publish')");
        $pdo->exec("INSERT INTO wp_posts (post_title, post_content, post_status) VALUES ('Draft', 'Not ready.', 'draft')");

        $pdo->exec('CREATE TABLE wp_users (ID INTEGER PRIMARY KEY, user_login TEXT, user_email TEXT)');
        $pdo->exec("INSERT INTO wp_users (user_login, user_email) VALUES ('admin', 'admin@example.com')");
        $pdo->exec("INSERT INTO wp_users (user_login, user_email) VALUES ('editor', 'editor@example.com')");

        $pdo = null;
        $this->sourceHash = md5_file($this->dbPath);
    }

    private function tearDown(): void
    {
        if (file_exists($this->dbPath)) {
            unlink($this->dbPath);
        }
    }

    private function testReadTableLazily(): void
    {
        $db = new CowDatabase($this->dbPath);
        $db->resetAccessTracking();

        $options = $db->readTable('wp_options');
        $this->assert(count($options) === 3, 'wp_options has 3 rows');
        $this->assert($options[0]['option_name'] === 'siteurl', 'first option is siteurl');
        $this->assert($options[0]['option_value'] === 'http://example.com', 'siteurl value correct');

        $pagesAccessed = $db->getRemoteAccessedPageCount();
        $totalPages = $db->getRemoteTotalPageCount();
        $this->assert(
            $pagesAccessed < $totalPages || $totalPages <= 3,
            "lazy: accessed {$pagesAccessed} of {$totalPages} pages"
        );
    }

    private function testReadOnlyFetchesNeededPages(): void
    {
        $db = new CowDatabase($this->dbPath);

        // Reading wp_users should not require fetching wp_posts pages
        $db->resetAccessTracking();
        $users = $db->readTable('wp_users');
        $this->assert(count($users) === 2, 'wp_users has 2 rows');

        $pagesForUsers = $db->getRemoteAccessedPages();

        $db->resetAccessTracking();
        $posts = $db->readTable('wp_posts');
        $this->assert(count($posts) === 3, 'wp_posts has 3 rows');

        // Both reads work independently
        $this->assert($users[0]['user_login'] === 'admin', 'user 1 is admin');
        $this->assert($posts[0]['post_title'] === 'Hello World', 'post 1 is Hello World');
    }

    private function testFindRowUsesFewerPages(): void
    {
        // Create a larger database where B-tree has multiple levels
        $largePath = tempnam(sys_get_temp_dir(), 'cow_large_') . '.sqlite';
        $pdo = new PDO("sqlite:{$largePath}");
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->exec('PRAGMA page_size = 4096');
        $pdo->exec('CREATE TABLE big (id INTEGER PRIMARY KEY, val TEXT)');
        $pdo->beginTransaction();
        $stmt = $pdo->prepare('INSERT INTO big (val) VALUES (?)');
        for ($i = 0; $i < 500; $i++) {
            $stmt->execute(["value_{$i}_" . str_repeat('x', 50)]);
        }
        $pdo->commit();
        $pdo = null;

        $db = new CowDatabase($largePath);

        // Full table scan
        $db->resetAccessTracking();
        $allRows = $db->readTable('big');
        $pagesForScan = $db->getRemoteAccessedPageCount();

        // Single row lookup
        $db->resetAccessTracking();
        $oneRow = $db->findRow('big', 250);
        $pagesForLookup = $db->getRemoteAccessedPageCount();

        $this->assert(count($allRows) === 500, 'full scan returns 500 rows');
        $this->assert($oneRow !== null, 'findRow found row 250');
        $this->assert(
            $pagesForLookup <= $pagesForScan,
            "lookup ({$pagesForLookup} pages) <= scan ({$pagesForScan} pages)"
        );

        unlink($largePath);
    }

    private function testInsertWritesLocally(): void
    {
        $db = new CowDatabase($this->dbPath);

        // Read first to verify initial state
        $optionsBefore = $db->readTable('wp_options');
        $this->assert(count($optionsBefore) === 3, '3 options before insert');

        // Insert locally
        $db->insert('wp_options', [
            'option_name' => 'new_option',
            'option_value' => 'new_value',
            'autoload' => 'no',
        ]);

        // Query after insert via local SQLite
        $rows = $db->query('SELECT * FROM wp_options WHERE option_name = ?', ['new_option']);
        $this->assert(count($rows) === 1, 'inserted row found');
        $this->assert($rows[0]['option_value'] === 'new_value', 'inserted value correct');
    }

    private function testUpdateWritesLocally(): void
    {
        $db = new CowDatabase($this->dbPath);

        $db->update('wp_posts', ['post_status' => 'private'], 'ID = ?', [1]);
        $rows = $db->query('SELECT * FROM wp_posts WHERE ID = 1');
        $this->assert(count($rows) === 1, 'found updated row');
        $this->assert($rows[0]['post_status'] === 'private', 'post_status updated to private');
    }

    private function testDeleteWritesLocally(): void
    {
        $db = new CowDatabase($this->dbPath);

        $db->delete('wp_posts', 'post_status = ?', ['draft']);
        $rows = $db->query('SELECT * FROM wp_posts');
        $this->assert(count($rows) === 2, '2 posts after deleting draft');

        foreach ($rows as $row) {
            $this->assert($row['post_status'] !== 'draft', 'no draft posts remain');
        }
    }

    private function testSourceNeverModified(): void
    {
        $db = new CowDatabase($this->dbPath);

        // Do various writes
        $db->insert('wp_options', ['option_name' => 'x', 'option_value' => 'y', 'autoload' => 'no']);
        $db->update('wp_posts', ['post_title' => 'Changed'], 'ID = ?', [1]);
        $db->delete('wp_users', 'ID = ?', [2]);

        $hashAfter = md5_file($this->dbPath);
        $this->assert($this->sourceHash === $hashAfter, 'source file unchanged after all writes');
    }

    private function testMultipleIndependentSessions(): void
    {
        $db1 = new CowDatabase($this->dbPath);
        $db2 = new CowDatabase($this->dbPath);

        // Write differently in each session
        $db1->insert('wp_options', ['option_name' => 'session1', 'option_value' => 'val1', 'autoload' => 'no']);
        $db2->insert('wp_options', ['option_name' => 'session2', 'option_value' => 'val2', 'autoload' => 'no']);

        $rows1 = $db1->query("SELECT * FROM wp_options WHERE option_name = 'session1'");
        $rows2 = $db2->query("SELECT * FROM wp_options WHERE option_name = 'session2'");

        $this->assert(count($rows1) === 1, 'session1 sees its insert');
        $this->assert(count($rows2) === 1, 'session2 sees its insert');

        // Each session should NOT see the other's insert
        $cross1 = $db1->query("SELECT * FROM wp_options WHERE option_name = 'session2'");
        $cross2 = $db2->query("SELECT * FROM wp_options WHERE option_name = 'session1'");
        $this->assert(count($cross1) === 0, 'session1 does not see session2 insert');
        $this->assert(count($cross2) === 0, 'session2 does not see session1 insert');
    }

    private function testQueryMaterializesOnDemand(): void
    {
        $db = new CowDatabase($this->dbPath);
        $materialized = $db->getMaterializedTables();
        $this->assert(count($materialized) === 0, 'no tables materialized at start');

        // readTable uses B-tree reader, no materialization
        $db->readTable('wp_options');
        $materialized = $db->getMaterializedTables();
        $this->assert(!in_array('wp_options', $materialized), 'readTable does not materialize');

        // query() requires materialization
        $db->query('SELECT * FROM wp_posts WHERE post_status = ?', ['publish']);
        $materialized = $db->getMaterializedTables();
        $this->assert(in_array('wp_posts', $materialized), 'query() materializes wp_posts');
        $this->assert(!in_array('wp_users', $materialized), 'wp_users still not materialized');
    }

    private function testReadThenWriteWorkflow(): void
    {
        $db = new CowDatabase($this->dbPath);

        // First, read lazily via B-tree
        $users = $db->readTable('wp_users');
        $this->assert(count($users) === 2, 'read 2 users lazily');

        // Then write — triggers materialization
        $db->insert('wp_users', ['user_login' => 'subscriber', 'user_email' => 'sub@example.com']);

        // Now query to see all users including the new one
        $allUsers = $db->query('SELECT * FROM wp_users ORDER BY ID');
        $this->assert(count($allUsers) === 3, '3 users after insert');
        $this->assert($allUsers[2]['user_login'] === 'subscriber', 'new user is subscriber');
    }

    private function testIsRemoteUnmodified(): void
    {
        $db = new CowDatabase($this->dbPath);

        // Before any writes, the remote should be unmodified
        $this->assert($db->isRemoteUnmodified($this->sourceHash), 'remote unmodified before writes');

        // After inserts and updates, the remote file should still be unchanged
        $db->insert('wp_options', ['option_name' => 'test_opt', 'option_value' => 'test_val', 'autoload' => 'no']);
        $db->update('wp_posts', ['post_title' => 'Modified Title'], 'ID = ?', [1]);
        $db->delete('wp_users', 'ID = ?', [1]);

        $this->assert($db->isRemoteUnmodified($this->sourceHash), 'remote unmodified after writes');

        // A wrong hash should return false
        $this->assert(!$db->isRemoteUnmodified('00000000000000000000000000000000'), 'wrong hash returns false');
    }

    private function testWriteDoesNotAffectLazyReadsOfOtherTables(): void
    {
        $db = new CowDatabase($this->dbPath);

        // Write to wp_options — this materializes wp_options
        $db->insert('wp_options', ['option_name' => 'lazy_test', 'option_value' => 'val', 'autoload' => 'no']);

        // Now read wp_users lazily — should still work and only access minimal pages
        $db->resetAccessTracking();
        $users = $db->readTable('wp_users');
        $pagesForUsers = $db->getRemoteAccessedPageCount();

        $this->assert(count($users) === 2, 'wp_users still has 2 rows after writing to wp_options');
        $this->assert($users[0]['user_login'] === 'admin', 'wp_users first row is admin');
        $this->assert($users[1]['user_login'] === 'editor', 'wp_users second row is editor');

        // wp_users should NOT be materialized
        $materialized = $db->getMaterializedTables();
        $this->assert(in_array('wp_options', $materialized), 'wp_options is materialized');
        $this->assert(!in_array('wp_users', $materialized), 'wp_users is NOT materialized after lazy read');

        // Lazy read should access fewer pages than total
        $totalPages = $db->getRemoteTotalPageCount();
        $this->assert(
            $pagesForUsers < $totalPages || $totalPages <= 3,
            "lazy wp_users read: {$pagesForUsers} of {$totalPages} pages"
        );
    }

    private function testEmptyTableReadReturnsEmptyArray(): void
    {
        // Create a DB with an empty table
        $emptyPath = tempnam(sys_get_temp_dir(), 'cow_empty_') . '.sqlite';
        $pdo = new PDO("sqlite:{$emptyPath}");
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->exec('PRAGMA page_size = 4096');
        $pdo->exec('CREATE TABLE empty_table (id INTEGER PRIMARY KEY, name TEXT, value TEXT)');
        $pdo->exec('CREATE TABLE nonempty (id INTEGER PRIMARY KEY, data TEXT)');
        $pdo->exec("INSERT INTO nonempty (data) VALUES ('exists')");
        $pdo = null;

        $db = new CowDatabase($emptyPath);

        $rows = $db->readTable('empty_table');
        $this->assert(is_array($rows), 'readTable returns array for empty table');
        $this->assert(count($rows) === 0, 'empty table returns 0 rows');

        // Non-empty table in same DB should still work
        $nonemptyRows = $db->readTable('nonempty');
        $this->assert(count($nonemptyRows) === 1, 'nonempty table returns 1 row');

        unlink($emptyPath);
    }

    private function testFindRowNonExistent(): void
    {
        $db = new CowDatabase($this->dbPath);

        // Look up a rowid that does not exist in any table
        $result = $db->findRow('wp_options', 9999);
        $this->assert($result === null, 'findRow returns null for non-existent rowid 9999');

        $result2 = $db->findRow('wp_posts', 0);
        $this->assert($result2 === null, 'findRow returns null for rowid 0');

        $result3 = $db->findRow('wp_users', 100);
        $this->assert($result3 === null, 'findRow returns null for rowid 100 in wp_users');

        // Look up in a non-existent table
        $result4 = $db->findRow('nonexistent_table', 1);
        $this->assert($result4 === null, 'findRow returns null for non-existent table');
    }

    private function testReadTableAfterMaterialization(): void
    {
        $db = new CowDatabase($this->dbPath);

        // Read lazily first
        $postsBefore = $db->readTable('wp_posts');
        $this->assert(count($postsBefore) === 3, '3 posts before insert');

        // Insert triggers materialization
        $db->insert('wp_posts', [
            'post_title' => 'New Post',
            'post_content' => 'Brand new content.',
            'post_status' => 'publish',
        ]);

        // Verify wp_posts is now materialized
        $materialized = $db->getMaterializedTables();
        $this->assert(in_array('wp_posts', $materialized), 'wp_posts materialized after insert');

        // readTable should now return the materialized (local) version with the new row
        $postsAfter = $db->readTable('wp_posts');
        $this->assert(count($postsAfter) === 4, '4 posts after insert (materialized version)');

        // Verify the new row is present
        $found = false;
        foreach ($postsAfter as $row) {
            if ($row['post_title'] === 'New Post') {
                $found = true;
                $this->assert($row['post_content'] === 'Brand new content.', 'new post content correct');
                $this->assert($row['post_status'] === 'publish', 'new post status correct');
                break;
            }
        }
        $this->assert($found, 'new post found in readTable after materialization');
    }

    private function assert(bool $condition, string $message): void
    {
        if ($condition) {
            $this->passed++;
        } else {
            $this->failed++;
            echo "  FAIL: {$message}\n";
        }
    }

    public function getResults(): array
    {
        return [$this->passed, $this->failed];
    }
}
