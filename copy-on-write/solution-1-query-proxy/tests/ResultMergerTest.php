<?php

namespace CowClone\Tests;

require_once __DIR__ . '/../src/LocalOverlayStore.php';
require_once __DIR__ . '/../src/ResultMerger.php';

use CowClone\LocalOverlayStore;
use CowClone\ResultMerger;

class ResultMergerTest
{
    private int $passed = 0;
    private int $failed = 0;
    private array $failures = [];

    public function run(): array
    {
        $this->testNoLocalChanges();
        $this->testDeletedRowsExcluded();
        $this->testUpdatedRowsShowLocalValues();
        $this->testInsertedRowsAppended();
        $this->testCombinedOperations();
        $this->testWhereFilterOnInserts();
        $this->testDeleteThenInsert();
        $this->testUpdateMultipleColumns();

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

    private function testNoLocalChanges(): void
    {
        $overlay = new LocalOverlayStore();
        $merger = new ResultMerger();

        $remote = [
            ['id' => '1', 'title' => 'Post 1'],
            ['id' => '2', 'title' => 'Post 2'],
        ];

        $result = $merger->merge('wp_posts', $remote, $overlay);
        $this->assertEqual(2, count($result), "No local changes: should return all remote rows");
        $this->assertEqual('Post 1', $result[0]['title'], "No local changes: first row title");
        $this->assertEqual('Post 2', $result[1]['title'], "No local changes: second row title");
    }

    private function testDeletedRowsExcluded(): void
    {
        $overlay = new LocalOverlayStore();
        $merger = new ResultMerger();

        $overlay->recordDelete('wp_posts', 2);

        $remote = [
            ['id' => '1', 'title' => 'Post 1'],
            ['id' => '2', 'title' => 'Post 2'],
            ['id' => '3', 'title' => 'Post 3'],
        ];

        $result = $merger->merge('wp_posts', $remote, $overlay);
        $this->assertEqual(2, count($result), "Deleted row excluded: should return 2 rows");
        $this->assertEqual('1', $result[0]['id'], "First remaining row should be id=1");
        $this->assertEqual('3', $result[1]['id'], "Second remaining row should be id=3");
    }

    private function testUpdatedRowsShowLocalValues(): void
    {
        $overlay = new LocalOverlayStore();
        $merger = new ResultMerger();

        $overlay->recordUpdate('wp_posts', 1, ['title' => 'Updated Post 1']);

        $remote = [
            ['id' => '1', 'title' => 'Post 1', 'status' => 'publish'],
            ['id' => '2', 'title' => 'Post 2', 'status' => 'draft'],
        ];

        $result = $merger->merge('wp_posts', $remote, $overlay);
        $this->assertEqual(2, count($result), "Updated rows: should return all rows");
        $this->assertEqual('Updated Post 1', $result[0]['title'], "Updated row should show local title");
        $this->assertEqual('publish', $result[0]['status'], "Non-updated columns should keep remote value");
        $this->assertEqual('Post 2', $result[1]['title'], "Non-updated row should keep remote title");
    }

    private function testInsertedRowsAppended(): void
    {
        $overlay = new LocalOverlayStore();
        $merger = new ResultMerger();

        $overlay->recordInsert('wp_posts', ['title' => 'New Post', 'status' => 'draft']);

        $remote = [
            ['id' => '1', 'title' => 'Post 1'],
        ];

        $result = $merger->merge('wp_posts', $remote, $overlay);
        $this->assertEqual(2, count($result), "Inserted rows: should return remote + local");
        $this->assertEqual('Post 1', $result[0]['title'], "Remote row still present");
        $this->assertEqual('New Post', $result[1]['title'], "Inserted row appended");
        $this->assert($result[1]['id'] < 0, "Inserted row should have negative ID, got: " . $result[1]['id']);
    }

    private function testCombinedOperations(): void
    {
        $overlay = new LocalOverlayStore();
        $merger = new ResultMerger();

        // Delete row 2, update row 1, insert new row
        $overlay->recordDelete('wp_posts', 2);
        $overlay->recordUpdate('wp_posts', 1, ['title' => 'Updated']);
        $overlay->recordInsert('wp_posts', ['title' => 'Brand New']);

        $remote = [
            ['id' => '1', 'title' => 'Post 1'],
            ['id' => '2', 'title' => 'Post 2'],
            ['id' => '3', 'title' => 'Post 3'],
        ];

        $result = $merger->merge('wp_posts', $remote, $overlay);
        $this->assertEqual(3, count($result), "Combined: 3 remote - 1 deleted + 1 inserted = 3");
        $this->assertEqual('Updated', $result[0]['title'], "Row 1 should be updated");
        $this->assertEqual('3', $result[1]['id'], "Row 3 should survive");
        $this->assertEqual('Brand New', $result[2]['title'], "New row should be appended");
    }

    private function testWhereFilterOnInserts(): void
    {
        $overlay = new LocalOverlayStore();
        $merger = new ResultMerger();

        $overlay->recordInsert('wp_posts', ['title' => 'Draft', 'status' => 'draft']);
        $overlay->recordInsert('wp_posts', ['title' => 'Published', 'status' => 'publish']);

        $remote = [];

        // Filter for status = publish
        $result = $merger->merge('wp_posts', $remote, $overlay, 'id', ['status' => 'publish']);
        $this->assertEqual(1, count($result), "WHERE filter should only include matching inserts");
        $this->assertEqual('Published', $result[0]['title'], "Should be the published post");
    }

    private function testDeleteThenInsert(): void
    {
        $overlay = new LocalOverlayStore();
        $merger = new ResultMerger();

        // Delete all remote rows, insert a new one
        $overlay->recordDelete('wp_posts', 1);
        $overlay->recordInsert('wp_posts', ['title' => 'Replacement']);

        $remote = [
            ['id' => '1', 'title' => 'Original'],
        ];

        $result = $merger->merge('wp_posts', $remote, $overlay);
        $this->assertEqual(1, count($result), "Delete + insert should result in 1 row");
        $this->assertEqual('Replacement', $result[0]['title'], "Should see the new row");
    }

    private function testUpdateMultipleColumns(): void
    {
        $overlay = new LocalOverlayStore();
        $merger = new ResultMerger();

        $overlay->recordUpdate('wp_posts', 1, ['title' => 'New Title', 'status' => 'trash']);

        $remote = [
            ['id' => '1', 'title' => 'Old Title', 'status' => 'publish', 'content' => 'Hello'],
        ];

        $result = $merger->merge('wp_posts', $remote, $overlay);
        $this->assertEqual('New Title', $result[0]['title'], "Title should be updated");
        $this->assertEqual('trash', $result[0]['status'], "Status should be updated");
        $this->assertEqual('Hello', $result[0]['content'], "Content should remain from remote");
    }
}
