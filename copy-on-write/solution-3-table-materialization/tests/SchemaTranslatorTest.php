<?php

namespace CowClone\Tests;

require_once __DIR__ . '/../src/SchemaTranslator.php';

use CowClone\SchemaTranslator;

class SchemaTranslatorTest
{
    private SchemaTranslator $translator;
    private int $passed = 0;
    private int $failed = 0;

    public function __construct()
    {
        $this->translator = new SchemaTranslator();
    }

    public function run(): array
    {
        $this->testSimpleTable();
        $this->testVariousColumnTypes();
        $this->testPrimaryKey();
        $this->testUniqueIndex();
        $this->testRegularIndex();
        $this->testAutoIncrement();
        $this->testDefaultValues();
        $this->testEnumTypes();
        $this->testWpPostsSchema();
        $this->testWpOptionsSchema();

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

    private function testSimpleTable(): void
    {
        echo "  [SchemaTranslator] Simple CREATE TABLE\n";
        $mysql = "CREATE TABLE test (id INT, name VARCHAR(255))";
        $result = $this->translator->translateCreateTable($mysql);

        $this->assert(strpos($result, 'CREATE TABLE IF NOT EXISTS "test"') !== false, "Should have table name 'test'");
        $this->assert(strpos($result, '"id" INTEGER') !== false, "Should translate INT to INTEGER");
        $this->assert(strpos($result, '"name" TEXT') !== false, "Should translate VARCHAR to TEXT");
    }

    private function testVariousColumnTypes(): void
    {
        echo "  [SchemaTranslator] Various column types\n";
        $mysql = "CREATE TABLE types_test (
            a BIGINT,
            b SMALLINT,
            c TINYINT,
            d FLOAT,
            e DOUBLE,
            f DECIMAL(10,2),
            g TEXT,
            h MEDIUMTEXT,
            i LONGTEXT,
            j BLOB,
            k DATETIME,
            l TIMESTAMP,
            m DATE,
            n BOOLEAN
        )";
        $result = $this->translator->translateCreateTable($mysql);

        $this->assert(strpos($result, '"a" INTEGER') !== false, "BIGINT -> INTEGER");
        $this->assert(strpos($result, '"b" INTEGER') !== false, "SMALLINT -> INTEGER");
        $this->assert(strpos($result, '"c" INTEGER') !== false, "TINYINT -> INTEGER");
        $this->assert(strpos($result, '"d" REAL') !== false, "FLOAT -> REAL");
        $this->assert(strpos($result, '"e" REAL') !== false, "DOUBLE -> REAL");
        $this->assert(strpos($result, '"f" REAL') !== false, "DECIMAL -> REAL");
        $this->assert(strpos($result, '"g" TEXT') !== false, "TEXT -> TEXT");
        $this->assert(strpos($result, '"h" TEXT') !== false, "MEDIUMTEXT -> TEXT");
        $this->assert(strpos($result, '"i" TEXT') !== false, "LONGTEXT -> TEXT");
        $this->assert(strpos($result, '"j" BLOB') !== false, "BLOB -> BLOB");
        $this->assert(strpos($result, '"k" TEXT') !== false, "DATETIME -> TEXT");
        $this->assert(strpos($result, '"l" TEXT') !== false, "TIMESTAMP -> TEXT");
        $this->assert(strpos($result, '"m" TEXT') !== false, "DATE -> TEXT");
        $this->assert(strpos($result, '"n" INTEGER') !== false, "BOOLEAN -> INTEGER");
    }

    private function testPrimaryKey(): void
    {
        echo "  [SchemaTranslator] PRIMARY KEY\n";
        $mysql = "CREATE TABLE pk_test (id INT, name VARCHAR(100), PRIMARY KEY (id))";
        $result = $this->translator->translateCreateTable($mysql);

        $this->assert(strpos($result, 'PRIMARY KEY ("id")') !== false, "Should have PRIMARY KEY constraint");
    }

    private function testUniqueIndex(): void
    {
        echo "  [SchemaTranslator] UNIQUE INDEX\n";
        $mysql = "CREATE TABLE uq_test (id INT, email VARCHAR(255), UNIQUE KEY idx_email (email))";
        $result = $this->translator->translateCreateTable($mysql);

        $this->assert(strpos($result, 'UNIQUE ("email")') !== false, "Should have UNIQUE constraint");
    }

    private function testRegularIndex(): void
    {
        echo "  [SchemaTranslator] Regular INDEX\n";
        $mysql = "CREATE TABLE idx_test (id INT, status VARCHAR(20), KEY idx_status (status))";
        $result = $this->translator->translateCreateTable($mysql);

        $this->assert(strpos($result, 'CREATE INDEX') !== false, "Should create a separate CREATE INDEX");
        $this->assert(strpos($result, '"status"') !== false, "Index should reference the status column");
    }

    private function testAutoIncrement(): void
    {
        echo "  [SchemaTranslator] AUTO_INCREMENT\n";
        $mysql = "CREATE TABLE ai_test (id INT AUTO_INCREMENT, name VARCHAR(255), PRIMARY KEY (id))";
        $result = $this->translator->translateCreateTable($mysql);

        $this->assert(strpos($result, 'PRIMARY KEY AUTOINCREMENT') !== false, "Should use AUTOINCREMENT");
        // Should NOT have a separate PRIMARY KEY constraint
        $this->assert(substr_count($result, 'PRIMARY KEY') === 1, "Should have exactly one PRIMARY KEY");
    }

    private function testDefaultValues(): void
    {
        echo "  [SchemaTranslator] DEFAULT values\n";
        $mysql = "CREATE TABLE def_test (
            id INT,
            status VARCHAR(20) DEFAULT 'active',
            count INT DEFAULT 0,
            created DATETIME DEFAULT CURRENT_TIMESTAMP
        )";
        $result = $this->translator->translateCreateTable($mysql);

        $this->assert(strpos($result, "DEFAULT 'active'") !== false, "Should preserve string default");
        $this->assert(strpos($result, "DEFAULT 0") !== false, "Should preserve numeric default");
        $this->assert(strpos($result, "DEFAULT CURRENT_TIMESTAMP") !== false, "Should preserve CURRENT_TIMESTAMP");
    }

    private function testEnumTypes(): void
    {
        echo "  [SchemaTranslator] ENUM types\n";
        $mysql = "CREATE TABLE enum_test (id INT, status ENUM('draft','publish','private') NOT NULL DEFAULT 'draft')";
        $result = $this->translator->translateCreateTable($mysql);

        $this->assert(strpos($result, '"status" TEXT') !== false, "ENUM should map to TEXT");
        $this->assert(strpos($result, 'NOT NULL') !== false, "Should preserve NOT NULL");
        $this->assert(strpos($result, "DEFAULT 'draft'") !== false, "Should preserve DEFAULT for ENUM");
    }

    private function testWpPostsSchema(): void
    {
        echo "  [SchemaTranslator] WordPress wp_posts schema\n";
        $mysql = "CREATE TABLE wp_posts (
            ID bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            post_author bigint(20) unsigned NOT NULL DEFAULT 0,
            post_date datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
            post_content longtext NOT NULL,
            post_title text NOT NULL,
            post_status varchar(20) NOT NULL DEFAULT 'publish',
            post_type varchar(20) NOT NULL DEFAULT 'post',
            PRIMARY KEY (ID),
            KEY post_author (post_author),
            KEY type_status_date (post_type, post_status, post_date, ID)
        )";
        $result = $this->translator->translateCreateTable($mysql);

        $this->assert(strpos($result, '"wp_posts"') !== false, "Table name should be wp_posts");
        $this->assert(strpos($result, '"ID" INTEGER PRIMARY KEY AUTOINCREMENT') !== false, "ID should be INTEGER PRIMARY KEY AUTOINCREMENT");
        $this->assert(strpos($result, '"post_content" TEXT') !== false, "longtext -> TEXT");
        $this->assert(strpos($result, '"post_status" TEXT') !== false, "varchar -> TEXT");

        // Verify it produces valid SQLite
        $db = new \PDO('sqlite::memory:');
        $db->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $statements = array_filter(array_map('trim', explode(';', $result)));
        foreach ($statements as $stmt) {
            if ($stmt !== '') {
                $db->exec($stmt);
            }
        }
        $this->assert(true, "Generated DDL is valid SQLite");
    }

    private function testWpOptionsSchema(): void
    {
        echo "  [SchemaTranslator] WordPress wp_options schema\n";
        $mysql = "CREATE TABLE wp_options (
            option_id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            option_name varchar(191) NOT NULL DEFAULT '',
            option_value longtext NOT NULL,
            autoload varchar(20) NOT NULL DEFAULT 'yes',
            PRIMARY KEY (option_id),
            UNIQUE KEY option_name (option_name),
            KEY autoload (autoload)
        )";
        $result = $this->translator->translateCreateTable($mysql);

        $this->assert(strpos($result, '"wp_options"') !== false, "Table name should be wp_options");
        $this->assert(strpos($result, 'AUTOINCREMENT') !== false, "Should have AUTOINCREMENT");
        $this->assert(strpos($result, 'UNIQUE ("option_name")') !== false, "Should have UNIQUE on option_name");

        // Verify it produces valid SQLite
        $db = new \PDO('sqlite::memory:');
        $db->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $statements = array_filter(array_map('trim', explode(';', $result)));
        foreach ($statements as $stmt) {
            if ($stmt !== '') {
                $db->exec($stmt);
            }
        }
        $this->assert(true, "Generated DDL is valid SQLite");
    }
}
