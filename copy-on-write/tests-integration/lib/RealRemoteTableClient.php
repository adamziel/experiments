<?php
/**
 * Real PDO-backed RemoteTableClient for Solution 3.
 *
 * Replaces the in-memory MockRemoteTableClient. Goes over the wire to a real
 * MariaDB. getTableSchema() returns a MySQL CREATE TABLE statement (captured
 * via SHOW CREATE TABLE) that SchemaTranslator knows how to convert.
 */

namespace CowClone\IntegrationHarness;

use CowClone\RemoteTableClient;
use PDO;

class RealRemoteTableClient implements RemoteTableClient
{
    private PDO $pdo;
    private string $dbName;

    /** @var string[]|null */
    private ?array $tableListCache = null;

    /** @var array<string, string> */
    private array $schemaCache = [];

    /** @var array<string, int> */
    public array $fetchCount = [];

    public int $bytesReceived = 0;
    public int $queryCount = 0;

    public function __construct(string $host, int $port, string $user, string $password, string $dbName)
    {
        $dsn = "mysql:host={$host};port={$port};dbname={$dbName};charset=utf8mb4";
        $this->pdo = new PDO($dsn, $user, $password, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        ]);
        $this->dbName = $dbName;
    }

    public function listTables(): array
    {
        if ($this->tableListCache !== null) {
            return $this->tableListCache;
        }
        $this->queryCount++;
        $stmt = $this->pdo->prepare(
            "SELECT TABLE_NAME FROM INFORMATION_SCHEMA.TABLES
             WHERE TABLE_SCHEMA = :db ORDER BY TABLE_NAME"
        );
        $stmt->execute([':db' => $this->dbName]);
        $names = $stmt->fetchAll(PDO::FETCH_COLUMN);
        return $this->tableListCache = array_values($names);
    }

    public function getTableSchema(string $tableName): string
    {
        if (isset($this->schemaCache[$tableName])) {
            return $this->schemaCache[$tableName];
        }
        $this->queryCount++;
        $stmt = $this->pdo->query("SHOW CREATE TABLE `{$tableName}`");
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row || !isset($row['Create Table'])) {
            throw new \RuntimeException("Could not SHOW CREATE TABLE for {$tableName}");
        }
        $sql = (string) $row['Create Table'];
        $this->bytesReceived += strlen($sql);
        return $this->schemaCache[$tableName] = $sql;
    }

    public function getTableRows(string $tableName): array
    {
        $this->queryCount++;
        $this->fetchCount[$tableName] = ($this->fetchCount[$tableName] ?? 0) + 1;
        $stmt = $this->pdo->query("SELECT * FROM `{$tableName}`");
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as $r) {
            foreach ($r as $v) {
                $this->bytesReceived += is_string($v) ? strlen($v) : (is_scalar($v) ? strlen((string) $v) : 0);
            }
        }
        return $rows;
    }

    public function getTableRowCount(string $tableName): int
    {
        $this->queryCount++;
        return (int) $this->pdo->query("SELECT COUNT(*) FROM `{$tableName}`")->fetchColumn();
    }

    public function getPdo(): PDO { return $this->pdo; }

    public function resetCounters(): void
    {
        $this->queryCount    = 0;
        $this->bytesReceived = 0;
        $this->fetchCount    = [];
    }
}
