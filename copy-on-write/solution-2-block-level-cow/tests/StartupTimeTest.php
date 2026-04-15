<?php

declare(strict_types=1);

require_once __DIR__ . '/../src/PageProvider.php';
require_once __DIR__ . '/../src/FilePageProvider.php';
require_once __DIR__ . '/../src/CowPageProvider.php';
require_once __DIR__ . '/../src/BTreeReader.php';
require_once __DIR__ . '/../src/CowDatabase.php';

use CowClone\BlockLevel\CowDatabase;

class StartupTimeTest
{
    private int $passed = 0;
    private int $failed = 0;

    public function run(): void
    {
        $this->testStartupTimeSmallDatabase();
        $this->testStartupTimeLargeDatabase();
        $this->testStartupDoesNotReadAllPages();
        $this->testFirstReadIsLazy();
    }

    private function testStartupTimeSmallDatabase(): void
    {
        $dbPath = $this->createDatabase(10);

        $t0 = microtime(true);
        $db = new CowDatabase($dbPath);
        $ms = (microtime(true) - $t0) * 1000;

        $msStr = number_format($ms, 2);
        echo "  Small DB startup: {$msStr} ms\n";
        $this->assert($ms < 50, "startup < 50ms for small DB (was {$msStr}ms)");

        unlink($dbPath);
    }

    private function testStartupTimeLargeDatabase(): void
    {
        // Create a database with many rows across many pages
        $dbPath = $this->createDatabase(5000);
        $fileSize = filesize($dbPath);
        $fileSizeMB = number_format($fileSize / 1024 / 1024, 1);

        $t0 = microtime(true);
        $db = new CowDatabase($dbPath);
        $ms = (microtime(true) - $t0) * 1000;
        $msStr = number_format($ms, 2);
        $totalPages = $db->getRemoteTotalPageCount();

        echo "  Large DB ({$fileSizeMB} MB, {$totalPages} pages) startup: {$msStr} ms\n";
        $this->assert($ms < 50, "startup < 50ms for large DB (was {$msStr}ms)");

        // Startup should NOT have read any pages yet
        $pagesAccessed = $db->getRemoteAccessedPageCount();
        echo "  Pages accessed at startup: {$pagesAccessed}\n";
        $this->assert($pagesAccessed === 0, "zero pages accessed at startup");

        unlink($dbPath);
    }

    private function testStartupDoesNotReadAllPages(): void
    {
        $dbPath = $this->createDatabase(2000);
        $db = new CowDatabase($dbPath);

        // Get table names — reads only page 1 (sqlite_master)
        $db->resetAccessTracking();
        $tables = $db->getTableNames();
        $pagesForSchema = $db->getRemoteAccessedPageCount();
        $totalPages = $db->getRemoteTotalPageCount();

        echo "  Schema discovery: {$pagesForSchema} of {$totalPages} pages\n";
        $this->assert(
            $pagesForSchema < $totalPages,
            "schema discovery reads fewer pages than total ({$pagesForSchema} < {$totalPages})"
        );

        unlink($dbPath);
    }

    private function testFirstReadIsLazy(): void
    {
        $dbPath = $this->createDatabase(2000);
        $db = new CowDatabase($dbPath);

        // Read one table — should not read all pages
        $db->resetAccessTracking();
        $rows = $db->readTable('data');
        $pagesForRead = $db->getRemoteAccessedPageCount();
        $totalPages = $db->getRemoteTotalPageCount();

        echo "  Full table read: {$pagesForRead} of {$totalPages} pages for " . count($rows) . " rows\n";
        // It will read most pages for the 'data' table, but not pages for indexes or other structures
        $this->assert(count($rows) === 2000, 'read all 2000 rows');

        unlink($dbPath);
    }

    private function createDatabase(int $rowCount): string
    {
        $dbPath = tempnam(sys_get_temp_dir(), 'cow_startup_') . '.sqlite';
        $pdo = new PDO("sqlite:{$dbPath}");
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->exec('PRAGMA page_size = 4096');
        $pdo->exec('CREATE TABLE data (id INTEGER PRIMARY KEY, name TEXT, value TEXT, extra TEXT)');
        $pdo->beginTransaction();
        $stmt = $pdo->prepare('INSERT INTO data (name, value, extra) VALUES (?, ?, ?)');
        for ($i = 0; $i < $rowCount; $i++) {
            $stmt->execute([
                "name_{$i}",
                "value_{$i}_" . str_repeat('x', 100),
                "extra_{$i}_" . str_repeat('y', 100),
            ]);
        }
        $pdo->commit();
        $pdo = null;
        return $dbPath;
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
