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

class StartupTimeTest
{
    private int $passed = 0;
    private int $failed = 0;

    public function run(): array
    {
        $this->testStartupTimeWith500Tables();
        $this->testStartupTimeWithLargeVirtualTables();

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

    private function testStartupTimeWith500Tables(): void
    {
        echo "  [StartupTime] Creating CowDatabase with 500 tables takes < 50ms\n";

        $remote = new MockRemoteTableClient();

        // Add 500 tables with varying schemas
        for ($i = 0; $i < 500; $i++) {
            $remote->addVirtualTable(
                "wp_table_{$i}",
                "CREATE TABLE wp_table_{$i} (
                    id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
                    col_a varchar(255) NOT NULL DEFAULT '',
                    col_b text NOT NULL,
                    col_c datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
                    PRIMARY KEY (id)
                )",
                rand(100, 100000) // Virtual row counts
            );
        }

        $db = new \PDO('sqlite::memory:');
        $db->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);

        $startTime = microtime(true);
        $cow = new CowDatabase($remote, $db);
        $elapsed = (microtime(true) - $startTime) * 1000; // Convert to ms

        $this->assert($elapsed < 50, "Startup should take < 50ms, took {$elapsed}ms");
        $this->assert($remote->fetchCount === 0, "No rows should be fetched at startup");

        echo "    Startup time with 500 tables: " . round($elapsed, 2) . "ms\n";
    }

    private function testStartupTimeWithLargeVirtualTables(): void
    {
        echo "  [StartupTime] 500 tables with millions of virtual rows, startup still fast\n";

        $remote = new MockRemoteTableClient();

        // Add 500 tables each claiming to have millions of rows
        for ($i = 0; $i < 500; $i++) {
            $remote->addVirtualTable(
                "wp_large_{$i}",
                "CREATE TABLE wp_large_{$i} (
                    id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
                    data longtext NOT NULL,
                    PRIMARY KEY (id)
                )",
                1000000 + $i // Each table claims 1M+ rows
            );
        }

        $db = new \PDO('sqlite::memory:');
        $db->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);

        $startTime = microtime(true);
        $cow = new CowDatabase($remote, $db);
        $elapsed = (microtime(true) - $startTime) * 1000;

        $this->assert($elapsed < 50, "Startup should take < 50ms even with large virtual tables, took {$elapsed}ms");
        $this->assert($remote->fetchCount === 0, "No rows should be fetched during startup");

        // Verify the remote reports huge row counts but we never fetched them
        $totalVirtualRows = 0;
        foreach ($remote->listTables() as $t) {
            $totalVirtualRows += $remote->getTableRowCount($t);
        }
        $this->assert($totalVirtualRows > 500000000, "Virtual tables claim > 500M total rows");

        echo "    Startup time with 500 large tables (" . number_format($totalVirtualRows) . " virtual rows): " . round($elapsed, 2) . "ms\n";
    }
}
