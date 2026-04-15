<?php

namespace CowClone\Tests;

require_once __DIR__ . '/../src/SchemaTranslator.php';
require_once __DIR__ . '/../src/RemoteTableClient.php';
require_once __DIR__ . '/../src/MaterializationTracker.php';
require_once __DIR__ . '/../src/ChangeJournal.php';
require_once __DIR__ . '/../src/TableMaterializer.php';
require_once __DIR__ . '/../src/CowDatabase.php';

use CowClone\CowDatabase;
use CowClone\MockRemoteTableClient;

class IntegrationTest
{
    private int $passed = 0;
    private int $failed = 0;

    public function run(): array
    {
        $this->testFirstSelectTriggersMaterialization();
        $this->testSecondSelectDoesNotRematerialize();
        $this->testOtherTablesRemainUnmaterialized();
        $this->testInsertAfterMaterialization();
        $this->testUpdateAfterMaterialization();
        $this->testDeleteAfterMaterialization();
        $this->testRemoteReceivesNoWrites();
        $this->testJoinTriggersMaterializationOfAllTables();
        $this->testChangeJournalAccuracyAfterMutations();
        $this->testStartupOnlyFetchesTableList();
        $this->testInsertBeforeAnySelectMaterializesTable();
        $this->testUpdateBeforeAnySelectMaterializesTable();
        $this->testMultipleMutationsThenVerify();
        $this->testWriteToOneTableDoesNotMaterializeOthers();
        $this->testEmptyTableMaterialization();

        return ['passed' => $this->passed, 'failed' => $this->failed];
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

    /**
     * Create a standard test setup with wp_posts, wp_options, and wp_users.
     */
    private function createTestSetup(): array
    {
        $remote = new MockRemoteTableClient();

        // wp_posts table
        $remote->addTable('wp_posts',
            "CREATE TABLE wp_posts (
                ID bigint(20) unsigned NOT NULL AUTO_INCREMENT,
                post_title text NOT NULL,
                post_content longtext NOT NULL,
                post_status varchar(20) NOT NULL DEFAULT 'publish',
                post_author bigint(20) unsigned NOT NULL DEFAULT 0,
                PRIMARY KEY (ID)
            )",
            [
                ['ID' => 1, 'post_title' => 'Hello World', 'post_content' => 'Welcome!', 'post_status' => 'publish', 'post_author' => 1],
                ['ID' => 2, 'post_title' => 'Second Post', 'post_content' => 'Content here', 'post_status' => 'draft', 'post_author' => 1],
            ]
        );

        // wp_options table
        $remote->addTable('wp_options',
            "CREATE TABLE wp_options (
                option_id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
                option_name varchar(191) NOT NULL DEFAULT '',
                option_value longtext NOT NULL,
                autoload varchar(20) NOT NULL DEFAULT 'yes',
                PRIMARY KEY (option_id),
                UNIQUE KEY option_name (option_name)
            )",
            [
                ['option_id' => 1, 'option_name' => 'siteurl', 'option_value' => 'http://example.com', 'autoload' => 'yes'],
                ['option_id' => 2, 'option_name' => 'blogname', 'option_value' => 'Test Blog', 'autoload' => 'yes'],
            ]
        );

        // wp_users table
        $remote->addTable('wp_users',
            "CREATE TABLE wp_users (
                ID bigint(20) unsigned NOT NULL AUTO_INCREMENT,
                user_login varchar(60) NOT NULL DEFAULT '',
                user_email varchar(100) NOT NULL DEFAULT '',
                PRIMARY KEY (ID),
                KEY user_login_key (user_login)
            )",
            [
                ['ID' => 1, 'user_login' => 'admin', 'user_email' => 'admin@example.com'],
            ]
        );

        $db = new \PDO('sqlite::memory:');
        $db->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);

        $cow = new CowDatabase($remote, $db);

        return [$remote, $db, $cow];
    }

    private function testFirstSelectTriggersMaterialization(): void
    {
        echo "  [Integration] First SELECT triggers materialization of the queried table only\n";
        /** @var MockRemoteTableClient $remote */
        [$remote, $db, $cow] = $this->createTestSetup();

        $results = $cow->query('SELECT * FROM wp_posts WHERE post_status = :status', [':status' => 'publish']);
        $this->assert(count($results) === 1, "Should return 1 published post");
        $this->assert($results[0]['post_title'] === 'Hello World', "Should return correct post");

        // wp_posts should be materialized
        $this->assert($cow->getTracker()->isMaterialized('wp_posts'), "wp_posts should be materialized");

        // wp_options and wp_users should NOT be materialized
        $this->assert(!$cow->getTracker()->isMaterialized('wp_options'), "wp_options should NOT be materialized yet");
        $this->assert(!$cow->getTracker()->isMaterialized('wp_users'), "wp_users should NOT be materialized yet");
    }

    private function testSecondSelectDoesNotRematerialize(): void
    {
        echo "  [Integration] Second SELECT does NOT re-materialize\n";
        /** @var MockRemoteTableClient $remote */
        [$remote, $db, $cow] = $this->createTestSetup();

        // First query
        $cow->query('SELECT * FROM wp_posts');
        $fetchCount1 = $remote->fetchCount;

        // Second query
        $cow->query('SELECT * FROM wp_posts WHERE ID = 1');
        $fetchCount2 = $remote->fetchCount;

        $this->assert($fetchCount1 === 1, "First query should fetch once");
        $this->assert($fetchCount2 === 1, "Second query should NOT fetch again (still 1)");
    }

    private function testOtherTablesRemainUnmaterialized(): void
    {
        echo "  [Integration] Other tables remain unmaterialized until accessed\n";
        /** @var MockRemoteTableClient $remote */
        [$remote, $db, $cow] = $this->createTestSetup();

        // Query only wp_posts
        $cow->query('SELECT * FROM wp_posts');

        // Check fetch counts
        $this->assert(
            isset($remote->tableFetchCounts['wp_posts']) && $remote->tableFetchCounts['wp_posts'] === 1,
            "wp_posts should have been fetched once"
        );
        $this->assert(
            !isset($remote->tableFetchCounts['wp_options']),
            "wp_options should not have been fetched"
        );
        $this->assert(
            !isset($remote->tableFetchCounts['wp_users']),
            "wp_users should not have been fetched"
        );

        // Now query wp_options
        $cow->query('SELECT * FROM wp_options');
        $this->assert(
            isset($remote->tableFetchCounts['wp_options']) && $remote->tableFetchCounts['wp_options'] === 1,
            "wp_options should now have been fetched once"
        );
        $this->assert(
            !isset($remote->tableFetchCounts['wp_users']),
            "wp_users should still not have been fetched"
        );
    }

    private function testInsertAfterMaterialization(): void
    {
        echo "  [Integration] INSERT after materialization is local only\n";
        /** @var MockRemoteTableClient $remote */
        [$remote, $db, $cow] = $this->createTestSetup();

        // Materialize wp_posts
        $cow->query('SELECT * FROM wp_posts');
        $initialFetchCount = $remote->fetchCount;

        // Insert a new post
        $insertId = $cow->insert('wp_posts', [
            'post_title'   => 'New Local Post',
            'post_content' => 'This is local only',
            'post_status'  => 'publish',
            'post_author'  => 1,
        ]);

        $this->assert($insertId > 0, "Should return a valid insert ID");
        $this->assert($remote->fetchCount === $initialFetchCount, "No additional remote fetches for INSERT");

        // Verify the row exists locally
        $results = $cow->query('SELECT * FROM wp_posts WHERE post_title = :title', [':title' => 'New Local Post']);
        $this->assert(count($results) === 1, "Inserted row should be queryable locally");
    }

    private function testUpdateAfterMaterialization(): void
    {
        echo "  [Integration] UPDATE after materialization is local only\n";
        /** @var MockRemoteTableClient $remote */
        [$remote, $db, $cow] = $this->createTestSetup();

        // Materialize wp_posts
        $cow->query('SELECT * FROM wp_posts');
        $initialFetchCount = $remote->fetchCount;

        // Update a post
        $affected = $cow->update('wp_posts',
            ['post_title' => 'Updated Title'],
            'ID = :id',
            [':id' => 1]
        );

        $this->assert($affected === 1, "Should affect 1 row");
        $this->assert($remote->fetchCount === $initialFetchCount, "No additional remote fetches for UPDATE");

        // Verify locally
        $results = $cow->query('SELECT * FROM wp_posts WHERE ID = 1');
        $this->assert($results[0]['post_title'] === 'Updated Title', "Title should be updated locally");
    }

    private function testDeleteAfterMaterialization(): void
    {
        echo "  [Integration] DELETE after materialization is local only\n";
        /** @var MockRemoteTableClient $remote */
        [$remote, $db, $cow] = $this->createTestSetup();

        // Materialize wp_posts
        $cow->query('SELECT * FROM wp_posts');
        $initialFetchCount = $remote->fetchCount;

        // Delete a post
        $affected = $cow->delete('wp_posts', 'ID = :id', [':id' => 2]);

        $this->assert($affected === 1, "Should affect 1 row");
        $this->assert($remote->fetchCount === $initialFetchCount, "No additional remote fetches for DELETE");

        // Verify locally
        $results = $cow->query('SELECT * FROM wp_posts');
        $this->assert(count($results) === 1, "Should only have 1 post remaining");
        $this->assert($results[0]['ID'] == 1, "Remaining post should be ID=1");
    }

    private function testRemoteReceivesNoWrites(): void
    {
        echo "  [Integration] Remote receives NO write operations\n";
        /** @var MockRemoteTableClient $remote */
        [$remote, $db, $cow] = $this->createTestSetup();

        // Materialize and do writes
        $cow->query('SELECT * FROM wp_posts');
        $cow->insert('wp_posts', ['post_title' => 'New', 'post_content' => 'Test', 'post_status' => 'draft', 'post_author' => 1]);
        $cow->update('wp_posts', ['post_title' => 'Changed'], 'ID = :id', [':id' => 1]);
        $cow->delete('wp_posts', 'ID = :id', [':id' => 2]);

        // The mock client only has getTableRows as a fetch method.
        // Fetch count should be exactly 1 (the initial materialization).
        $this->assert($remote->fetchCount === 1, "Remote should only have been read once (materialization)");

        // The remote data should be unchanged
        $remoteRows = $remote->getTableRows('wp_posts'); // This increments fetchCount, but that's just verification
        $this->assert(count($remoteRows) === 2, "Remote should still have original 2 rows");
        $this->assert($remoteRows[0]['post_title'] === 'Hello World', "Remote row 1 unchanged");
        $this->assert($remoteRows[1]['post_title'] === 'Second Post', "Remote row 2 unchanged");
    }

    private function testJoinTriggersMaterializationOfAllTables(): void
    {
        echo "  [Integration] JOIN triggers materialization of all involved tables\n";
        /** @var MockRemoteTableClient $remote */
        [$remote, $db, $cow] = $this->createTestSetup();

        // Query with JOIN
        $results = $cow->query('
            SELECT wp_posts.post_title, wp_users.user_login
            FROM wp_posts
            JOIN wp_users ON wp_posts.post_author = wp_users.ID
            WHERE wp_posts.post_status = :status
        ', [':status' => 'publish']);

        $this->assert(count($results) === 1, "JOIN should return 1 result");
        $this->assert($results[0]['post_title'] === 'Hello World', "Should have correct post title");
        $this->assert($results[0]['user_login'] === 'admin', "Should have correct user login");

        // Both tables should be materialized
        $this->assert($cow->getTracker()->isMaterialized('wp_posts'), "wp_posts should be materialized");
        $this->assert($cow->getTracker()->isMaterialized('wp_users'), "wp_users should be materialized");

        // wp_options should still NOT be materialized
        $this->assert(!$cow->getTracker()->isMaterialized('wp_options'), "wp_options should NOT be materialized");
    }

    private function testChangeJournalAccuracyAfterMutations(): void
    {
        echo "  [Integration] Change journal accurately records all post-materialization mutations\n";
        /** @var MockRemoteTableClient $remote */
        [$remote, $db, $cow] = $this->createTestSetup();

        // Materialize
        $cow->query('SELECT * FROM wp_posts');

        // Perform mutations
        $cow->insert('wp_posts', [
            'post_title'   => 'Journal Test Post',
            'post_content' => 'Content',
            'post_status'  => 'publish',
            'post_author'  => 1,
        ]);
        $cow->update('wp_posts', ['post_title' => 'Updated Hello'], 'ID = :id', [':id' => 1]);
        $cow->delete('wp_posts', 'ID = :id', [':id' => 2]);

        $journal = $cow->getJournal();
        $entries = $journal->getJournalEntries('wp_posts');

        $this->assert(count($entries) === 3, "Should have 3 journal entries");

        // INSERT entry
        $this->assert($entries[0]['operation'] === 'INSERT', "First entry should be INSERT");
        $insertData = json_decode($entries[0]['row_data_json'], true);
        $this->assert($insertData['post_title'] === 'Journal Test Post', "INSERT should record new data");

        // UPDATE entry
        $this->assert($entries[1]['operation'] === 'UPDATE', "Second entry should be UPDATE");
        $updateNewData = json_decode($entries[1]['row_data_json'], true);
        $updateOldData = json_decode($entries[1]['old_data_json'], true);
        $this->assert($updateOldData['post_title'] === 'Hello World', "UPDATE should record old title");
        $this->assert($updateNewData['post_title'] === 'Updated Hello', "UPDATE should record new title");

        // DELETE entry
        $this->assert($entries[2]['operation'] === 'DELETE', "Third entry should be DELETE");
        $deleteOldData = json_decode($entries[2]['old_data_json'], true);
        $this->assert($deleteOldData['post_title'] === 'Second Post', "DELETE should record old data");
    }

    private function testStartupOnlyFetchesTableList(): void
    {
        echo "  [Integration] Startup only fetches the table list, no row data\n";
        /** @var MockRemoteTableClient $remote */
        $remote = new MockRemoteTableClient();

        $remote->addTable('wp_posts',
            "CREATE TABLE wp_posts (ID bigint(20) AUTO_INCREMENT, post_title text, PRIMARY KEY (ID))",
            [['ID' => 1, 'post_title' => 'Hello']]
        );
        $remote->addTable('wp_options',
            "CREATE TABLE wp_options (option_id bigint(20) AUTO_INCREMENT, option_name varchar(191), PRIMARY KEY (option_id))",
            [['option_id' => 1, 'option_name' => 'siteurl']]
        );

        $db = new \PDO('sqlite::memory:');
        $db->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);

        // Creating CowDatabase should NOT fetch any rows
        $cow = new CowDatabase($remote, $db);

        $this->assert($remote->fetchCount === 0, "No rows should be fetched at startup");
        $this->assert(!$cow->getTracker()->isMaterialized('wp_posts'), "No tables should be materialized at startup");
        $this->assert(!$cow->getTracker()->isMaterialized('wp_options'), "No tables should be materialized at startup");
    }

    private function testInsertBeforeAnySelectMaterializesTable(): void
    {
        echo "  [Integration] INSERT before any SELECT materializes the table and works correctly\n";
        /** @var MockRemoteTableClient $remote */
        [$remote, $db, $cow] = $this->createTestSetup();

        // No SELECT has been issued yet; table should not be materialized
        $this->assert(!$cow->getTracker()->isMaterialized('wp_posts'), "wp_posts should NOT be materialized before insert");

        // Insert directly without a prior SELECT
        $insertId = $cow->insert('wp_posts', [
            'post_title'   => 'Inserted Before Select',
            'post_content' => 'Edge case content',
            'post_status'  => 'publish',
            'post_author'  => 1,
        ]);

        $this->assert($insertId > 0, "Should return a valid insert ID");
        $this->assert($cow->getTracker()->isMaterialized('wp_posts'), "wp_posts should be materialized after insert");

        // The original rows plus the new one should all be present
        $results = $cow->query('SELECT * FROM wp_posts');
        $this->assert(count($results) === 3, "Should have 2 original rows + 1 inserted row");

        // The new row should be queryable
        $newRow = $cow->query('SELECT * FROM wp_posts WHERE post_title = :title', [':title' => 'Inserted Before Select']);
        $this->assert(count($newRow) === 1, "Newly inserted row should be queryable");
        $this->assert($newRow[0]['post_content'] === 'Edge case content', "Inserted row content should match");

        // Other tables should remain unmaterialized
        $this->assert(!$cow->getTracker()->isMaterialized('wp_options'), "wp_options should NOT be materialized");
        $this->assert(!$cow->getTracker()->isMaterialized('wp_users'), "wp_users should NOT be materialized");
    }

    private function testUpdateBeforeAnySelectMaterializesTable(): void
    {
        echo "  [Integration] UPDATE before any SELECT materializes the table\n";
        /** @var MockRemoteTableClient $remote */
        [$remote, $db, $cow] = $this->createTestSetup();

        // No SELECT has been issued yet
        $this->assert(!$cow->getTracker()->isMaterialized('wp_posts'), "wp_posts should NOT be materialized before update");

        // Update directly without a prior SELECT
        $affected = $cow->update('wp_posts',
            ['post_title' => 'Updated Without Select'],
            'ID = :id',
            [':id' => 1]
        );

        $this->assert($affected === 1, "Should affect 1 row");
        $this->assert($cow->getTracker()->isMaterialized('wp_posts'), "wp_posts should be materialized after update");

        // Verify the update took effect
        $results = $cow->query('SELECT * FROM wp_posts WHERE ID = 1');
        $this->assert($results[0]['post_title'] === 'Updated Without Select', "Title should be updated");

        // The other row should be untouched
        $results2 = $cow->query('SELECT * FROM wp_posts WHERE ID = 2');
        $this->assert($results2[0]['post_title'] === 'Second Post', "Other row should be unchanged");

        // Journal should have recorded the update
        $entries = $cow->getJournal()->getJournalEntries('wp_posts');
        $this->assert(count($entries) === 1, "Should have 1 journal entry");
        $this->assert($entries[0]['operation'] === 'UPDATE', "Journal entry should be UPDATE");
    }

    private function testMultipleMutationsThenVerify(): void
    {
        echo "  [Integration] Multiple mutations (INSERT, UPDATE, DELETE) then verify all changes\n";
        /** @var MockRemoteTableClient $remote */
        [$remote, $db, $cow] = $this->createTestSetup();

        // Start with 2 original rows (ID=1 Hello World, ID=2 Second Post)

        // INSERT a new row
        $newId = $cow->insert('wp_posts', [
            'post_title'   => 'Third Post',
            'post_content' => 'Third content',
            'post_status'  => 'publish',
            'post_author'  => 1,
        ]);
        $this->assert($newId > 0, "INSERT should return valid ID");

        // UPDATE the first row
        $affected = $cow->update('wp_posts',
            ['post_title' => 'Hello World Updated', 'post_status' => 'draft'],
            'ID = :id',
            [':id' => 1]
        );
        $this->assert($affected === 1, "UPDATE should affect 1 row");

        // DELETE the second row
        $deleted = $cow->delete('wp_posts', 'ID = :id', [':id' => 2]);
        $this->assert($deleted === 1, "DELETE should affect 1 row");

        // Verify final state
        $allRows = $cow->query('SELECT * FROM wp_posts ORDER BY ID ASC');
        $this->assert(count($allRows) === 2, "Should have 2 rows remaining (1 original updated + 1 inserted)");

        // First row should be updated
        $this->assert($allRows[0]['post_title'] === 'Hello World Updated', "First row title should be updated");
        $this->assert($allRows[0]['post_status'] === 'draft', "First row status should be draft");

        // Second row should be the inserted one
        $this->assert($allRows[1]['post_title'] === 'Third Post', "Second row should be the inserted post");

        // Deleted row should not exist
        $deletedRows = $cow->query('SELECT * FROM wp_posts WHERE ID = :id', [':id' => 2]);
        $this->assert(count($deletedRows) === 0, "Deleted row should not be found");

        // Journal should have 3 entries in order
        $entries = $cow->getJournal()->getJournalEntries('wp_posts');
        $this->assert(count($entries) === 3, "Journal should have 3 entries");
        $this->assert($entries[0]['operation'] === 'INSERT', "First journal entry should be INSERT");
        $this->assert($entries[1]['operation'] === 'UPDATE', "Second journal entry should be UPDATE");
        $this->assert($entries[2]['operation'] === 'DELETE', "Third journal entry should be DELETE");
    }

    private function testWriteToOneTableDoesNotMaterializeOthers(): void
    {
        echo "  [Integration] Writing to wp_posts does not materialize wp_options or wp_users\n";
        /** @var MockRemoteTableClient $remote */
        [$remote, $db, $cow] = $this->createTestSetup();

        // Perform various writes to wp_posts only
        $cow->insert('wp_posts', [
            'post_title'   => 'New Post',
            'post_content' => 'Content',
            'post_status'  => 'publish',
            'post_author'  => 1,
        ]);
        $cow->update('wp_posts', ['post_title' => 'Changed'], 'ID = :id', [':id' => 1]);
        $cow->delete('wp_posts', 'ID = :id', [':id' => 2]);

        // wp_posts should be materialized
        $this->assert($cow->getTracker()->isMaterialized('wp_posts'), "wp_posts should be materialized");

        // wp_options and wp_users must NOT be materialized
        $this->assert(!$cow->getTracker()->isMaterialized('wp_options'), "wp_options should NOT be materialized after writing to wp_posts");
        $this->assert(!$cow->getTracker()->isMaterialized('wp_users'), "wp_users should NOT be materialized after writing to wp_posts");

        // Verify remote fetch counts: only wp_posts should have been fetched
        $this->assert(
            isset($remote->tableFetchCounts['wp_posts']) && $remote->tableFetchCounts['wp_posts'] === 1,
            "wp_posts should have been fetched exactly once"
        );
        $this->assert(
            !isset($remote->tableFetchCounts['wp_options']),
            "wp_options should never have been fetched from remote"
        );
        $this->assert(
            !isset($remote->tableFetchCounts['wp_users']),
            "wp_users should never have been fetched from remote"
        );

        // Now read from wp_options to confirm it materializes independently
        $options = $cow->query('SELECT * FROM wp_options');
        $this->assert(count($options) === 2, "wp_options should have 2 rows after its own materialization");
        $this->assert($cow->getTracker()->isMaterialized('wp_options'), "wp_options should now be materialized");

        // wp_users should still not be materialized
        $this->assert(!$cow->getTracker()->isMaterialized('wp_users'), "wp_users should still NOT be materialized");
    }

    private function testEmptyTableMaterialization(): void
    {
        echo "  [Integration] Empty table materializes correctly and supports INSERT after\n";

        $remote = new MockRemoteTableClient();

        // Add an empty table (no rows)
        $remote->addTable('wp_comments',
            "CREATE TABLE wp_comments (
                comment_ID bigint(20) unsigned NOT NULL AUTO_INCREMENT,
                comment_content text NOT NULL,
                comment_author varchar(100) NOT NULL DEFAULT '',
                PRIMARY KEY (comment_ID)
            )",
            [] // no rows
        );

        // Also add a non-empty table to confirm isolation
        $remote->addTable('wp_posts',
            "CREATE TABLE wp_posts (
                ID bigint(20) unsigned NOT NULL AUTO_INCREMENT,
                post_title text NOT NULL,
                PRIMARY KEY (ID)
            )",
            [['ID' => 1, 'post_title' => 'Hello']]
        );

        $db = new \PDO('sqlite::memory:');
        $db->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $cow = new CowDatabase($remote, $db);

        // Query the empty table
        $results = $cow->query('SELECT * FROM wp_comments');
        $this->assert(count($results) === 0, "Empty table should return 0 rows");
        $this->assert($cow->getTracker()->isMaterialized('wp_comments'), "Empty table should be marked as materialized");

        // INSERT into the empty table should work
        $insertId = $cow->insert('wp_comments', [
            'comment_content' => 'First comment',
            'comment_author'  => 'tester',
        ]);
        $this->assert($insertId > 0, "INSERT into previously empty table should return valid ID");

        // Verify the row is there
        $results = $cow->query('SELECT * FROM wp_comments');
        $this->assert(count($results) === 1, "Should have 1 row after INSERT");
        $this->assert($results[0]['comment_content'] === 'First comment', "Inserted content should match");
        $this->assert($results[0]['comment_author'] === 'tester', "Inserted author should match");

        // wp_posts should not have been materialized
        $this->assert(!$cow->getTracker()->isMaterialized('wp_posts'), "wp_posts should NOT be materialized by wp_comments operations");
    }
}
