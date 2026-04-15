<?php

namespace CowClone\Tests;

require_once __DIR__ . '/../src/ChangeJournal.php';

use CowClone\ChangeJournal;

class ChangeJournalTest
{
    private int $passed = 0;
    private int $failed = 0;

    public function run(): array
    {
        $this->testInsertIsRecorded();
        $this->testUpdateRecordsOldAndNew();
        $this->testDeleteRecordsOldValues();
        $this->testQueryByTable();
        $this->testQueryByTimestamp();
        $this->testEntriesAreOrdered();
        $this->testMultipleDifferentTables();
        $this->testJournalEntriesPreserveOrder();

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

    private function createJournal(): ChangeJournal
    {
        $db = new \PDO('sqlite::memory:');
        $db->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        return new ChangeJournal($db);
    }

    private function testInsertIsRecorded(): void
    {
        echo "  [ChangeJournal] INSERT is recorded\n";
        $journal = $this->createJournal();

        $journal->recordInsert('wp_posts', ['ID' => 1, 'post_title' => 'Hello']);

        $entries = $journal->getJournalEntries();
        $this->assert(count($entries) === 1, "Should have 1 entry");
        $this->assert($entries[0]['operation'] === 'INSERT', "Operation should be INSERT");
        $this->assert($entries[0]['table_name'] === 'wp_posts', "Table should be wp_posts");

        $rowData = json_decode($entries[0]['row_data_json'], true);
        $this->assert($rowData['ID'] === 1, "Row data should contain ID=1");
        $this->assert($rowData['post_title'] === 'Hello', "Row data should contain title");
        $this->assert($entries[0]['old_data_json'] === null, "Old data should be null for INSERT");
    }

    private function testUpdateRecordsOldAndNew(): void
    {
        echo "  [ChangeJournal] UPDATE records old and new values\n";
        $journal = $this->createJournal();

        $oldData = ['ID' => 1, 'post_title' => 'Old Title'];
        $newData = ['ID' => 1, 'post_title' => 'New Title'];
        $journal->recordUpdate('wp_posts', $oldData, $newData);

        $entries = $journal->getJournalEntries();
        $this->assert(count($entries) === 1, "Should have 1 entry");
        $this->assert($entries[0]['operation'] === 'UPDATE', "Operation should be UPDATE");

        $rowData = json_decode($entries[0]['row_data_json'], true);
        $this->assert($rowData['post_title'] === 'New Title', "New data should have updated title");

        $oldDataDecoded = json_decode($entries[0]['old_data_json'], true);
        $this->assert($oldDataDecoded['post_title'] === 'Old Title', "Old data should have original title");
    }

    private function testDeleteRecordsOldValues(): void
    {
        echo "  [ChangeJournal] DELETE records old values\n";
        $journal = $this->createJournal();

        $journal->recordDelete('wp_posts', ['ID' => 5, 'post_title' => 'Deleted Post']);

        $entries = $journal->getJournalEntries();
        $this->assert(count($entries) === 1, "Should have 1 entry");
        $this->assert($entries[0]['operation'] === 'DELETE', "Operation should be DELETE");
        $this->assert($entries[0]['row_data_json'] === null, "Row data should be null for DELETE");

        $oldDataDecoded = json_decode($entries[0]['old_data_json'], true);
        $this->assert($oldDataDecoded['ID'] === 5, "Old data should contain ID");
        $this->assert($oldDataDecoded['post_title'] === 'Deleted Post', "Old data should contain title");
    }

    private function testQueryByTable(): void
    {
        echo "  [ChangeJournal] Query by table\n";
        $journal = $this->createJournal();

        $journal->recordInsert('wp_posts', ['ID' => 1]);
        $journal->recordInsert('wp_options', ['option_id' => 1]);
        $journal->recordInsert('wp_posts', ['ID' => 2]);
        $journal->recordInsert('wp_users', ['ID' => 1]);

        $allEntries = $journal->getJournalEntries();
        $this->assert(count($allEntries) === 4, "Should have 4 total entries");

        $postsEntries = $journal->getJournalEntries('wp_posts');
        $this->assert(count($postsEntries) === 2, "Should have 2 wp_posts entries");

        $optionsEntries = $journal->getJournalEntries('wp_options');
        $this->assert(count($optionsEntries) === 1, "Should have 1 wp_options entry");

        $usersEntries = $journal->getJournalEntries('wp_users');
        $this->assert(count($usersEntries) === 1, "Should have 1 wp_users entry");

        $emptyEntries = $journal->getJournalEntries('wp_comments');
        $this->assert(count($emptyEntries) === 0, "Should have 0 entries for non-existent table");
    }

    private function testQueryByTimestamp(): void
    {
        echo "  [ChangeJournal] Query by timestamp\n";
        $journal = $this->createJournal();

        $journal->recordInsert('wp_posts', ['ID' => 1]);

        // Get all changes since epoch
        $entries = $journal->getChangesSince('2000-01-01 00:00:00');
        $this->assert(count($entries) === 1, "Should find the entry since 2000");

        // Get changes since far future
        $entries = $journal->getChangesSince('2099-01-01 00:00:00');
        $this->assert(count($entries) === 0, "Should find no entries in the future");
    }

    private function testEntriesAreOrdered(): void
    {
        echo "  [ChangeJournal] Entries are ordered by ID\n";
        $journal = $this->createJournal();

        $journal->recordInsert('wp_posts', ['ID' => 10]);
        $journal->recordUpdate('wp_posts', ['ID' => 10, 'title' => 'old'], ['ID' => 10, 'title' => 'new']);
        $journal->recordDelete('wp_posts', ['ID' => 10]);

        $entries = $journal->getJournalEntries();
        $this->assert(count($entries) === 3, "Should have 3 entries");
        $this->assert((int)$entries[0]['id'] < (int)$entries[1]['id'], "First entry ID < second entry ID");
        $this->assert((int)$entries[1]['id'] < (int)$entries[2]['id'], "Second entry ID < third entry ID");
        $this->assert($entries[0]['operation'] === 'INSERT', "First should be INSERT");
        $this->assert($entries[1]['operation'] === 'UPDATE', "Second should be UPDATE");
        $this->assert($entries[2]['operation'] === 'DELETE', "Third should be DELETE");
    }

    private function testMultipleDifferentTables(): void
    {
        echo "  [ChangeJournal] Changes to multiple tables are isolated when queried by table name\n";
        $journal = $this->createJournal();

        // Record changes across three different tables
        $journal->recordInsert('wp_posts', ['ID' => 1, 'post_title' => 'Post 1']);
        $journal->recordInsert('wp_posts', ['ID' => 2, 'post_title' => 'Post 2']);
        $journal->recordInsert('wp_options', ['option_id' => 1, 'option_name' => 'siteurl']);
        $journal->recordUpdate('wp_users', ['ID' => 1, 'user_login' => 'old'], ['ID' => 1, 'user_login' => 'new']);
        $journal->recordDelete('wp_posts', ['ID' => 1, 'post_title' => 'Post 1']);
        $journal->recordInsert('wp_options', ['option_id' => 2, 'option_name' => 'blogname']);
        $journal->recordUpdate('wp_options', ['option_id' => 1, 'option_name' => 'siteurl'], ['option_id' => 1, 'option_name' => 'home']);

        // All entries
        $allEntries = $journal->getJournalEntries();
        $this->assert(count($allEntries) === 7, "Should have 7 total entries across all tables");

        // wp_posts entries: 2 inserts + 1 delete = 3
        $postsEntries = $journal->getJournalEntries('wp_posts');
        $this->assert(count($postsEntries) === 3, "wp_posts should have 3 entries");
        $this->assert($postsEntries[0]['operation'] === 'INSERT', "wp_posts entry 1 should be INSERT");
        $this->assert($postsEntries[1]['operation'] === 'INSERT', "wp_posts entry 2 should be INSERT");
        $this->assert($postsEntries[2]['operation'] === 'DELETE', "wp_posts entry 3 should be DELETE");

        // wp_options entries: 2 inserts + 1 update = 3
        $optionsEntries = $journal->getJournalEntries('wp_options');
        $this->assert(count($optionsEntries) === 3, "wp_options should have 3 entries");
        $this->assert($optionsEntries[0]['operation'] === 'INSERT', "wp_options entry 1 should be INSERT");
        $this->assert($optionsEntries[1]['operation'] === 'INSERT', "wp_options entry 2 should be INSERT");
        $this->assert($optionsEntries[2]['operation'] === 'UPDATE', "wp_options entry 3 should be UPDATE");

        // wp_users entries: 1 update = 1
        $usersEntries = $journal->getJournalEntries('wp_users');
        $this->assert(count($usersEntries) === 1, "wp_users should have 1 entry");
        $this->assert($usersEntries[0]['operation'] === 'UPDATE', "wp_users entry should be UPDATE");

        // Verify data isolation: wp_posts entries should not contain wp_options data
        foreach ($postsEntries as $entry) {
            $this->assert($entry['table_name'] === 'wp_posts', "All wp_posts filtered entries should have table_name=wp_posts");
        }
        foreach ($optionsEntries as $entry) {
            $this->assert($entry['table_name'] === 'wp_options', "All wp_options filtered entries should have table_name=wp_options");
        }

        // Non-existent table should return empty
        $noEntries = $journal->getJournalEntries('wp_comments');
        $this->assert(count($noEntries) === 0, "Non-existent table should return 0 entries");
    }

    private function testJournalEntriesPreserveOrder(): void
    {
        echo "  [ChangeJournal] INSERT, UPDATE, DELETE on same table preserves exact order\n";
        $journal = $this->createJournal();

        // Perform a sequence of mixed operations on the same table
        $journal->recordInsert('wp_posts', ['ID' => 100, 'post_title' => 'New Post']);
        $journal->recordUpdate('wp_posts',
            ['ID' => 100, 'post_title' => 'New Post'],
            ['ID' => 100, 'post_title' => 'Updated Post']
        );
        $journal->recordInsert('wp_posts', ['ID' => 101, 'post_title' => 'Another Post']);
        $journal->recordDelete('wp_posts', ['ID' => 100, 'post_title' => 'Updated Post']);
        $journal->recordUpdate('wp_posts',
            ['ID' => 101, 'post_title' => 'Another Post'],
            ['ID' => 101, 'post_title' => 'Final Post']
        );

        $entries = $journal->getJournalEntries('wp_posts');
        $this->assert(count($entries) === 5, "Should have 5 entries");

        // Verify exact operation order
        $expectedOps = ['INSERT', 'UPDATE', 'INSERT', 'DELETE', 'UPDATE'];
        for ($i = 0; $i < 5; $i++) {
            $this->assert(
                $entries[$i]['operation'] === $expectedOps[$i],
                "Entry {$i} should be {$expectedOps[$i]}, got {$entries[$i]['operation']}"
            );
        }

        // Verify IDs are strictly ascending (preserves insertion order)
        for ($i = 0; $i < 4; $i++) {
            $this->assert(
                (int)$entries[$i]['id'] < (int)$entries[$i + 1]['id'],
                "Entry {$i} ID should be less than entry " . ($i + 1) . " ID"
            );
        }

        // Verify specific data in each entry
        $insertData0 = json_decode($entries[0]['row_data_json'], true);
        $this->assert($insertData0['post_title'] === 'New Post', "First INSERT should have title 'New Post'");

        $updateNewData1 = json_decode($entries[1]['row_data_json'], true);
        $updateOldData1 = json_decode($entries[1]['old_data_json'], true);
        $this->assert($updateOldData1['post_title'] === 'New Post', "First UPDATE old data should be 'New Post'");
        $this->assert($updateNewData1['post_title'] === 'Updated Post', "First UPDATE new data should be 'Updated Post'");

        $deleteOldData3 = json_decode($entries[3]['old_data_json'], true);
        $this->assert($deleteOldData3['post_title'] === 'Updated Post', "DELETE old data should be 'Updated Post'");
        $this->assert($entries[3]['row_data_json'] === null, "DELETE row_data_json should be null");

        $updateNewData4 = json_decode($entries[4]['row_data_json'], true);
        $this->assert($updateNewData4['post_title'] === 'Final Post', "Last UPDATE new data should be 'Final Post'");
    }
}
