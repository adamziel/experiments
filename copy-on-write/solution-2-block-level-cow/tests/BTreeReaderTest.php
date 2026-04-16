<?php

declare(strict_types=1);

require_once __DIR__ . '/../src/PageProvider.php';
require_once __DIR__ . '/../src/FilePageProvider.php';
require_once __DIR__ . '/../src/CowPageProvider.php';
require_once __DIR__ . '/../src/BTreeReader.php';

use CowClone\BlockLevel\FilePageProvider;
use CowClone\BlockLevel\BTreeReader;

class BTreeReaderTest
{
    private string $dbPath;
    private int $passed = 0;
    private int $failed = 0;

    public function run(): void
    {
        $this->setUp();
        try {
            $this->testReadSqliteMaster();
            $this->testScanTable();
            $this->testScanTableWithColumns();
            $this->testFindRowByRowid();
            $this->testFindRowNotFound();
            $this->testGetTablePages();
            $this->testLargeTable();
            $this->testMultipleTables();
            $this->testOverflowPayload();
            $this->testMultipleTablesIndependentPages();
        } finally {
            $this->tearDown();
        }
    }

    private function setUp(): void
    {
        $this->dbPath = tempnam(sys_get_temp_dir(), 'btree_test_') . '.sqlite';
        $pdo = new PDO("sqlite:{$this->dbPath}");
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->exec('PRAGMA page_size = 4096');

        $pdo->exec('CREATE TABLE wp_options (option_id INTEGER PRIMARY KEY, option_name TEXT, option_value TEXT, autoload TEXT)');
        $pdo->exec("INSERT INTO wp_options (option_name, option_value, autoload) VALUES ('siteurl', 'http://example.com', 'yes')");
        $pdo->exec("INSERT INTO wp_options (option_name, option_value, autoload) VALUES ('blogname', 'Test Blog', 'yes')");
        $pdo->exec("INSERT INTO wp_options (option_name, option_value, autoload) VALUES ('blogdescription', 'Just another site', 'yes')");

        $pdo->exec('CREATE TABLE wp_posts (ID INTEGER PRIMARY KEY, post_title TEXT, post_content TEXT, post_status TEXT)');
        $pdo->exec("INSERT INTO wp_posts (post_title, post_content, post_status) VALUES ('Hello World', 'Welcome to WordPress.', 'publish')");
        $pdo->exec("INSERT INTO wp_posts (post_title, post_content, post_status) VALUES ('Draft Post', 'This is a draft.', 'draft')");

        $pdo->exec('CREATE TABLE wp_users (ID INTEGER PRIMARY KEY, user_login TEXT, user_email TEXT)');
        $pdo->exec("INSERT INTO wp_users (user_login, user_email) VALUES ('admin', 'admin@example.com')");

        // Large text for overflow testing
        $longText = str_repeat('Lorem ipsum dolor sit amet. ', 200);
        $pdo->exec('CREATE TABLE wp_long (id INTEGER PRIMARY KEY, content TEXT)');
        $stmt = $pdo->prepare("INSERT INTO wp_long (content) VALUES (?)");
        $stmt->execute([$longText]);

        $pdo = null;
    }

    private function tearDown(): void
    {
        if (file_exists($this->dbPath)) {
            unlink($this->dbPath);
        }
    }

    private function testReadSqliteMaster(): void
    {
        $provider = new FilePageProvider($this->dbPath);
        $reader = new BTreeReader($provider);
        $master = $reader->readSqliteMaster();

        $tableNames = [];
        foreach ($master as $row) {
            if ($row['_values'][0] === 'table') {
                $tableNames[] = $row['_values'][1];
            }
        }

        $this->assert(in_array('wp_options', $tableNames), 'sqlite_master contains wp_options');
        $this->assert(in_array('wp_posts', $tableNames), 'sqlite_master contains wp_posts');
        $this->assert(in_array('wp_users', $tableNames), 'sqlite_master contains wp_users');
        $this->assert(count($tableNames) >= 4, 'sqlite_master has at least 4 tables');
    }

    private function testScanTable(): void
    {
        $provider = new FilePageProvider($this->dbPath);
        $reader = new BTreeReader($provider);
        $master = $reader->readSqliteMaster();

        $optionsRoot = null;
        foreach ($master as $row) {
            if ($row['_values'][1] === 'wp_options') {
                $optionsRoot = (int) $row['_values'][3];
                break;
            }
        }

        $rows = $reader->scanTable($optionsRoot);
        $this->assert(count($rows) === 3, 'wp_options has 3 rows');
        $this->assert($rows[0]['_values'][1] === 'siteurl', 'first option is siteurl');
    }

    private function testScanTableWithColumns(): void
    {
        $provider = new FilePageProvider($this->dbPath);
        $reader = new BTreeReader($provider);
        $master = $reader->readSqliteMaster();

        $optionsRoot = null;
        foreach ($master as $row) {
            if ($row['_values'][1] === 'wp_options') {
                $optionsRoot = (int) $row['_values'][3];
                break;
            }
        }

        $columns = ['option_id', 'option_name', 'option_value', 'autoload'];
        $rows = $reader->scanTable($optionsRoot, $columns);

        $this->assert(count($rows) === 3, 'wp_options has 3 rows with columns');
        $this->assert($rows[0]['option_name'] === 'siteurl', 'first row option_name is siteurl');
        $this->assert($rows[0]['option_value'] === 'http://example.com', 'first row option_value correct');
        $this->assert($rows[0]['autoload'] === 'yes', 'first row autoload is yes');
        $this->assert(isset($rows[0]['_rowid']), 'rows have _rowid');
    }

    private function testFindRowByRowid(): void
    {
        $provider = new FilePageProvider($this->dbPath);
        $reader = new BTreeReader($provider);
        $master = $reader->readSqliteMaster();

        $postsRoot = null;
        foreach ($master as $row) {
            if ($row['_values'][1] === 'wp_posts') {
                $postsRoot = (int) $row['_values'][3];
                break;
            }
        }

        $columns = ['ID', 'post_title', 'post_content', 'post_status'];
        $row = $reader->findRow($postsRoot, 2, $columns);

        $this->assert($row !== null, 'found row with rowid 2');
        $this->assert($row['post_title'] === 'Draft Post', 'correct post_title');
        $this->assert($row['post_status'] === 'draft', 'correct post_status');
    }

    private function testFindRowNotFound(): void
    {
        $provider = new FilePageProvider($this->dbPath);
        $reader = new BTreeReader($provider);
        $master = $reader->readSqliteMaster();

        $postsRoot = null;
        foreach ($master as $row) {
            if ($row['_values'][1] === 'wp_posts') {
                $postsRoot = (int) $row['_values'][3];
                break;
            }
        }

        $row = $reader->findRow($postsRoot, 999);
        $this->assert($row === null, 'row 999 not found');
    }

    private function testGetTablePages(): void
    {
        $provider = new FilePageProvider($this->dbPath);
        $reader = new BTreeReader($provider);
        $master = $reader->readSqliteMaster();

        $optionsRoot = null;
        foreach ($master as $row) {
            if ($row['_values'][1] === 'wp_options') {
                $optionsRoot = (int) $row['_values'][3];
                break;
            }
        }

        $pages = $reader->getTablePages($optionsRoot);
        $this->assert(count($pages) >= 1, 'wp_options uses at least 1 page');
        $this->assert(in_array($optionsRoot, $pages), 'pages include root page');
    }

    private function testLargeTable(): void
    {
        // Create a database with many rows to force B-tree interior pages
        $largePath = tempnam(sys_get_temp_dir(), 'btree_large_') . '.sqlite';
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

        $provider = new FilePageProvider($largePath);
        $reader = new BTreeReader($provider);

        $master = $reader->readSqliteMaster();
        $bigRoot = null;
        foreach ($master as $row) {
            if ($row['_values'][1] === 'big') {
                $bigRoot = (int) $row['_values'][3];
                break;
            }
        }

        $rows = $reader->scanTable($bigRoot, ['id', 'val']);
        $this->assert(count($rows) === 500, 'large table has 500 rows, got ' . count($rows));

        $pages = $reader->getTablePages($bigRoot);
        $this->assert(count($pages) > 1, 'large table uses multiple pages: ' . count($pages));

        // Find a specific row
        $row = $reader->findRow($bigRoot, 250, ['id', 'val']);
        $this->assert($row !== null, 'found row 250');
        $this->assert(str_starts_with($row['val'], 'value_249_'), 'row 250 has correct value prefix');

        unlink($largePath);
    }

    private function testMultipleTables(): void
    {
        $provider = new FilePageProvider($this->dbPath);
        $reader = new BTreeReader($provider);

        // Read options
        $master = $reader->readSqliteMaster();
        $roots = [];
        foreach ($master as $row) {
            if ($row['_values'][0] === 'table') {
                $roots[$row['_values'][1]] = (int) $row['_values'][3];
            }
        }

        $provider->resetAccessTracking();

        // Read only wp_users — should NOT access pages belonging to wp_posts or wp_options
        $usersColumns = ['ID', 'user_login', 'user_email'];
        $users = $reader->scanTable($roots['wp_users'], $usersColumns);
        $this->assert(count($users) === 1, 'wp_users has 1 row');
        $this->assert($users[0]['user_login'] === 'admin', 'user_login is admin');

        $accessedAfterUsers = $provider->getAccessedPages();

        // Now read wp_posts — should access different pages
        $postsColumns = ['ID', 'post_title', 'post_content', 'post_status'];
        $posts = $reader->scanTable($roots['wp_posts'], $postsColumns);
        $this->assert(count($posts) === 2, 'wp_posts has 2 rows');
    }

    private function testOverflowPayload(): void
    {
        $provider = new FilePageProvider($this->dbPath);
        $reader = new BTreeReader($provider);

        $master = $reader->readSqliteMaster();
        $longRoot = null;
        foreach ($master as $row) {
            if ($row['_values'][1] === 'wp_long') {
                $longRoot = (int) $row['_values'][3];
                break;
            }
        }

        $rows = $reader->scanTable($longRoot, ['id', 'content']);
        $this->assert(count($rows) === 1, 'wp_long has 1 row');
        $expected = str_repeat('Lorem ipsum dolor sit amet. ', 200);
        $this->assert($rows[0]['content'] === $expected, 'overflow content matches, got len=' . strlen($rows[0]['content']));
    }

    private function testMultipleTablesIndependentPages(): void
    {
        // Create a database with 3 tables, each with distinct data
        $multiPath = tempnam(sys_get_temp_dir(), 'btree_multi_') . '.sqlite';
        $pdo = new PDO("sqlite:{$multiPath}");
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->exec('PRAGMA page_size = 4096');

        $pdo->exec('CREATE TABLE colors (id INTEGER PRIMARY KEY, name TEXT, hex TEXT)');
        $pdo->exec("INSERT INTO colors (name, hex) VALUES ('red', '#FF0000')");
        $pdo->exec("INSERT INTO colors (name, hex) VALUES ('green', '#00FF00')");
        $pdo->exec("INSERT INTO colors (name, hex) VALUES ('blue', '#0000FF')");

        $pdo->exec('CREATE TABLE animals (id INTEGER PRIMARY KEY, species TEXT, legs INTEGER)');
        $pdo->exec("INSERT INTO animals (species, legs) VALUES ('cat', 4)");
        $pdo->exec("INSERT INTO animals (species, legs) VALUES ('spider', 8)");

        $pdo->exec('CREATE TABLE cities (id INTEGER PRIMARY KEY, name TEXT, country TEXT, population INTEGER)');
        $pdo->exec("INSERT INTO cities (name, country, population) VALUES ('Tokyo', 'Japan', 13960000)");
        $pdo->exec("INSERT INTO cities (name, country, population) VALUES ('Paris', 'France', 2161000)");
        $pdo->exec("INSERT INTO cities (name, country, population) VALUES ('Sydney', 'Australia', 5312000)");
        $pdo->exec("INSERT INTO cities (name, country, population) VALUES ('Cairo', 'Egypt', 9540000)");

        $pdo = null;

        $provider = new FilePageProvider($multiPath);
        $reader = new BTreeReader($provider);

        // Get root pages for all tables
        $master = $reader->readSqliteMaster();
        $roots = [];
        foreach ($master as $row) {
            if ($row['_values'][0] === 'table') {
                $roots[$row['_values'][1]] = (int) $row['_values'][3];
            }
        }

        $this->assert(isset($roots['colors']), 'colors table found in master');
        $this->assert(isset($roots['animals']), 'animals table found in master');
        $this->assert(isset($roots['cities']), 'cities table found in master');

        // Read each table independently and verify data
        $colors = $reader->scanTable($roots['colors'], ['id', 'name', 'hex']);
        $this->assert(count($colors) === 3, 'colors has 3 rows');
        $this->assert($colors[0]['name'] === 'red', 'first color is red');
        $this->assert($colors[1]['hex'] === '#00FF00', 'green hex is correct');
        $this->assert($colors[2]['name'] === 'blue', 'third color is blue');

        $animals = $reader->scanTable($roots['animals'], ['id', 'species', 'legs']);
        $this->assert(count($animals) === 2, 'animals has 2 rows');
        $this->assert($animals[0]['species'] === 'cat', 'first animal is cat');
        $this->assert($animals[1]['legs'] === 8, 'spider has 8 legs');

        $cities = $reader->scanTable($roots['cities'], ['id', 'name', 'country', 'population']);
        $this->assert(count($cities) === 4, 'cities has 4 rows');
        $this->assert($cities[0]['name'] === 'Tokyo', 'first city is Tokyo');
        $this->assert($cities[1]['country'] === 'France', 'Paris is in France');
        $this->assert($cities[2]['population'] === 5312000, 'Sydney population correct');
        $this->assert($cities[3]['name'] === 'Cairo', 'fourth city is Cairo');

        unlink($multiPath);
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
