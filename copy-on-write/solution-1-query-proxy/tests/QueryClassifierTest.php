<?php

namespace CowClone\Tests;

require_once __DIR__ . '/../src/QueryClassifier.php';

use CowClone\QueryClassifier;

class QueryClassifierTest
{
    private QueryClassifier $classifier;
    private int $passed = 0;
    private int $failed = 0;
    private array $failures = [];

    public function __construct()
    {
        $this->classifier = new QueryClassifier();
    }

    public function run(): array
    {
        $this->testSelectClassification();
        $this->testInsertClassification();
        $this->testUpdateClassification();
        $this->testDeleteClassification();
        $this->testCreateTableClassification();
        $this->testShowClassification();
        $this->testDescribeClassification();
        $this->testComplexJoins();
        $this->testSubqueries();
        $this->testTableNameExtraction();
        $this->testAllTableNamesExtraction();
        $this->testMiscQueries();

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

    private function testSelectClassification(): void
    {
        $this->assertEqual(
            QueryClassifier::TYPE_READ,
            $this->classifier->classify("SELECT * FROM wp_posts"),
            "Simple SELECT should be READ"
        );

        $this->assertEqual(
            QueryClassifier::TYPE_READ,
            $this->classifier->classify("select id, title from posts where id = 1"),
            "Lowercase SELECT should be READ"
        );

        $this->assertEqual(
            QueryClassifier::TYPE_READ,
            $this->classifier->classify("  SELECT * FROM wp_posts WHERE post_status = 'publish'  "),
            "SELECT with whitespace should be READ"
        );

        $this->assertEqual(
            QueryClassifier::QUERY_SELECT,
            $this->classifier->getQueryType("SELECT COUNT(*) FROM wp_posts"),
            "SELECT COUNT should be SELECT type"
        );
    }

    private function testInsertClassification(): void
    {
        $this->assertEqual(
            QueryClassifier::TYPE_WRITE,
            $this->classifier->classify("INSERT INTO wp_posts (title) VALUES ('test')"),
            "INSERT should be WRITE"
        );

        $this->assertEqual(
            QueryClassifier::QUERY_INSERT,
            $this->classifier->getQueryType("INSERT INTO wp_posts (title) VALUES ('test')"),
            "INSERT should have INSERT query type"
        );
    }

    private function testUpdateClassification(): void
    {
        $this->assertEqual(
            QueryClassifier::TYPE_WRITE,
            $this->classifier->classify("UPDATE wp_posts SET title = 'new' WHERE id = 1"),
            "UPDATE should be WRITE"
        );

        $this->assertEqual(
            QueryClassifier::QUERY_UPDATE,
            $this->classifier->getQueryType("UPDATE wp_posts SET title = 'new' WHERE id = 1"),
            "UPDATE should have UPDATE query type"
        );
    }

    private function testDeleteClassification(): void
    {
        $this->assertEqual(
            QueryClassifier::TYPE_WRITE,
            $this->classifier->classify("DELETE FROM wp_posts WHERE id = 1"),
            "DELETE should be WRITE"
        );

        $this->assertEqual(
            QueryClassifier::QUERY_DELETE,
            $this->classifier->getQueryType("DELETE FROM wp_posts WHERE id = 1"),
            "DELETE should have DELETE query type"
        );
    }

    private function testCreateTableClassification(): void
    {
        $this->assertEqual(
            QueryClassifier::TYPE_DDL,
            $this->classifier->classify("CREATE TABLE wp_custom (id INT PRIMARY KEY)"),
            "CREATE TABLE should be DDL"
        );

        $this->assertEqual(
            QueryClassifier::TYPE_DDL,
            $this->classifier->classify("CREATE TABLE IF NOT EXISTS wp_custom (id INT)"),
            "CREATE TABLE IF NOT EXISTS should be DDL"
        );

        $this->assertEqual(
            QueryClassifier::QUERY_CREATE_TABLE,
            $this->classifier->getQueryType("CREATE TABLE wp_custom (id INT)"),
            "CREATE TABLE should have CREATE_TABLE query type"
        );
    }

    private function testShowClassification(): void
    {
        $this->assertEqual(
            QueryClassifier::TYPE_READ,
            $this->classifier->classify("SHOW TABLES"),
            "SHOW TABLES should be READ"
        );

        $this->assertEqual(
            QueryClassifier::TYPE_READ,
            $this->classifier->classify("SHOW COLUMNS FROM wp_posts"),
            "SHOW COLUMNS should be READ"
        );
    }

    private function testDescribeClassification(): void
    {
        $this->assertEqual(
            QueryClassifier::TYPE_READ,
            $this->classifier->classify("DESCRIBE wp_posts"),
            "DESCRIBE should be READ"
        );

        $this->assertEqual(
            QueryClassifier::TYPE_READ,
            $this->classifier->classify("DESC wp_posts"),
            "DESC should be READ"
        );

        $this->assertEqual(
            QueryClassifier::TYPE_READ,
            $this->classifier->classify("EXPLAIN SELECT * FROM wp_posts"),
            "EXPLAIN should be READ"
        );
    }

    private function testComplexJoins(): void
    {
        $sql = "SELECT p.*, u.name FROM wp_posts p
                INNER JOIN wp_users u ON p.author_id = u.id
                WHERE p.status = 'publish'";

        $this->assertEqual(
            QueryClassifier::TYPE_READ,
            $this->classifier->classify($sql),
            "SELECT with JOIN should be READ"
        );

        $this->assertEqual(
            'wp_posts',
            $this->classifier->extractTableName($sql),
            "Should extract primary table from JOIN query"
        );

        $tables = $this->classifier->extractAllTableNames($sql);
        $this->assert(
            in_array('wp_posts', $tables) && in_array('wp_users', $tables),
            "Should extract all tables from JOIN: got " . implode(', ', $tables)
        );
    }

    private function testSubqueries(): void
    {
        $sql = "SELECT * FROM wp_posts WHERE author_id IN (SELECT id FROM wp_users WHERE role = 'admin')";

        $this->assertEqual(
            QueryClassifier::TYPE_READ,
            $this->classifier->classify($sql),
            "SELECT with subquery should be READ"
        );

        $this->assertEqual(
            'wp_posts',
            $this->classifier->extractTableName($sql),
            "Should extract primary table from subquery"
        );
    }

    private function testTableNameExtraction(): void
    {
        $this->assertEqual(
            'wp_posts',
            $this->classifier->extractTableName("SELECT * FROM wp_posts"),
            "Extract table from SELECT"
        );

        $this->assertEqual(
            'wp_posts',
            $this->classifier->extractTableName("INSERT INTO wp_posts (title) VALUES ('test')"),
            "Extract table from INSERT"
        );

        $this->assertEqual(
            'wp_posts',
            $this->classifier->extractTableName("UPDATE wp_posts SET title = 'new'"),
            "Extract table from UPDATE"
        );

        $this->assertEqual(
            'wp_posts',
            $this->classifier->extractTableName("DELETE FROM wp_posts WHERE id = 1"),
            "Extract table from DELETE"
        );

        $this->assertEqual(
            'wp_custom',
            $this->classifier->extractTableName("CREATE TABLE IF NOT EXISTS wp_custom (id INT)"),
            "Extract table from CREATE TABLE IF NOT EXISTS"
        );

        $this->assertEqual(
            'wp_posts',
            $this->classifier->extractTableName("SELECT * FROM `wp_posts` WHERE id = 1"),
            "Extract backtick-quoted table name"
        );

        $this->assertEqual(
            'wp_posts',
            $this->classifier->extractTableName("DESCRIBE wp_posts"),
            "Extract table from DESCRIBE"
        );

        $this->assertEqual(
            'wp_posts',
            $this->classifier->extractTableName("TRUNCATE TABLE wp_posts"),
            "Extract table from TRUNCATE"
        );
    }

    private function testAllTableNamesExtraction(): void
    {
        $sql = "SELECT * FROM wp_posts p
                LEFT JOIN wp_postmeta pm ON p.id = pm.post_id
                INNER JOIN wp_users u ON p.author = u.id";

        $tables = $this->classifier->extractAllTableNames($sql);
        $this->assert(count($tables) === 3, "Should find 3 tables in multi-JOIN, found " . count($tables));
        $this->assert(in_array('wp_posts', $tables), "Should find wp_posts");
        $this->assert(in_array('wp_postmeta', $tables), "Should find wp_postmeta");
        $this->assert(in_array('wp_users', $tables), "Should find wp_users");
    }

    private function testMiscQueries(): void
    {
        $this->assertEqual(
            QueryClassifier::TYPE_OTHER,
            $this->classifier->classify("SET NAMES utf8mb4"),
            "SET should be OTHER"
        );

        $this->assertEqual(
            QueryClassifier::TYPE_WRITE,
            $this->classifier->classify("REPLACE INTO wp_options (option_name, option_value) VALUES ('foo', 'bar')"),
            "REPLACE should be WRITE"
        );

        $this->assertEqual(
            QueryClassifier::TYPE_WRITE,
            $this->classifier->classify("TRUNCATE TABLE wp_posts"),
            "TRUNCATE should be WRITE"
        );
    }
}
