<?php

declare(strict_types=1);

require_once __DIR__ . '/../src/PageProvider.php';
require_once __DIR__ . '/../src/FilePageProvider.php';
require_once __DIR__ . '/../src/CowPageProvider.php';

use CowClone\BlockLevel\FilePageProvider;
use CowClone\BlockLevel\CowPageProvider;

class CowPageProviderTest
{
    private string $dbPath;
    private int $passed = 0;
    private int $failed = 0;

    public function run(): void
    {
        $this->setUp();
        try {
            $this->testReadThrough();
            $this->testWriteIsolation();
            $this->testWriteThenRead();
            $this->testMultiplePages();
            $this->testPageTracking();
            $this->testSourceNeverModified();
        } finally {
            $this->tearDown();
        }
    }

    private function setUp(): void
    {
        $this->dbPath = tempnam(sys_get_temp_dir(), 'cow_page_test_') . '.sqlite';
        $pdo = new PDO("sqlite:{$this->dbPath}");
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->exec('CREATE TABLE test (id INTEGER PRIMARY KEY, val TEXT)');
        $pdo->exec("INSERT INTO test (val) VALUES ('hello')");
        $pdo->exec("INSERT INTO test (val) VALUES ('world')");
        $pdo = null;
    }

    private function tearDown(): void
    {
        if (file_exists($this->dbPath)) {
            unlink($this->dbPath);
        }
    }

    private function testReadThrough(): void
    {
        $backend = new FilePageProvider($this->dbPath);
        $cow = new CowPageProvider($backend);

        $page1FromBackend = $backend->readPage(1);
        $page1FromCow = $cow->readPage(1);
        $this->assert($page1FromCow === $page1FromBackend, 'COW reads through to backend');
    }

    private function testWriteIsolation(): void
    {
        $backend = new FilePageProvider($this->dbPath);
        $cow = new CowPageProvider($backend);

        $originalPage = $backend->readPage(1);
        $modified = str_repeat('X', $backend->getPageSize());
        $cow->writePage(1, $modified);

        $fromBackend = $backend->readPage(1);
        $this->assert($fromBackend === $originalPage, 'backend page unchanged after COW write');
    }

    private function testWriteThenRead(): void
    {
        $backend = new FilePageProvider($this->dbPath);
        $cow = new CowPageProvider($backend);

        $modified = str_repeat('Y', $backend->getPageSize());
        $cow->writePage(1, $modified);
        $fromCow = $cow->readPage(1);
        $this->assert($fromCow === $modified, 'COW read returns local write');
    }

    private function testMultiplePages(): void
    {
        $backend = new FilePageProvider($this->dbPath);
        $cow = new CowPageProvider($backend);

        $pageCount = $backend->getPageCount();
        $this->assert($pageCount >= 2, 'database has at least 2 pages');

        $modified = str_repeat('Z', $backend->getPageSize());
        $cow->writePage(1, $modified);

        // Page 1 returns local version
        $this->assert($cow->readPage(1) === $modified, 'page 1 is local');

        // Page 2 falls through to backend
        $page2Backend = $backend->readPage(2);
        $this->assert($cow->readPage(2) === $page2Backend, 'page 2 falls through');
    }

    private function testPageTracking(): void
    {
        $backend = new FilePageProvider($this->dbPath);
        $cow = new CowPageProvider($backend);

        $this->assert($cow->isPageLocal(1) === false, 'page 1 not local initially');
        $cow->writePage(1, str_repeat('A', $backend->getPageSize()));
        $this->assert($cow->isPageLocal(1) === true, 'page 1 local after write');
        $this->assert($cow->isPageLocal(2) === false, 'page 2 still not local');

        $localPages = $cow->getLocalPages();
        $this->assert(count($localPages) === 1, 'one local page');
        $this->assert($localPages[0] === 1, 'local page is page 1');
    }

    private function testSourceNeverModified(): void
    {
        $hashBefore = md5_file($this->dbPath);
        $backend = new FilePageProvider($this->dbPath);
        $cow = new CowPageProvider($backend);

        // Write to every page
        for ($i = 1; $i <= $backend->getPageCount(); $i++) {
            $cow->writePage($i, str_repeat(chr($i), $backend->getPageSize()));
        }

        $hashAfter = md5_file($this->dbPath);
        $this->assert($hashBefore === $hashAfter, 'source file hash unchanged after writes');
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
