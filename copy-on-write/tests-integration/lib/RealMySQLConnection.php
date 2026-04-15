<?php
/**
 * Real PDO-backed MySQL remote connection for Solution 1.
 *
 * Replaces the in-process MockRemoteConnection. Implements
 * RemoteMySQLConnectionInterface from solution 1.
 *
 * Behavior notes:
 * - query() executes verbatim via PDO and returns rows for SELECT, or an
 *   empty array for writes. (The COW layer never routes writes here, but we
 *   still accept them for safety and count them via the query log below.)
 * - A query log and byte-in counter is exposed for test assertions.
 * - getTableSchema() uses INFORMATION_SCHEMA; all results are cached once.
 * - tableExists() is cached too.
 */

namespace CowClone\IntegrationHarness;

use CowClone\RemoteMySQLConnectionInterface;
use PDO;
use PDOException;

class RealMySQLConnection implements RemoteMySQLConnectionInterface
{
    private PDO $pdo;
    private string $dbName;

    /** @var array<string, array|null> */
    private array $schemaCache = [];

    /** @var array<string, bool> */
    private array $tableExistsCache = [];

    /** @var string[] Every SQL that reached the remote. */
    private array $queryLog = [];

    /** @var int Approximate bytes received from server (serialized row payload). */
    private int $bytesReceived = 0;

    public function __construct(string $host, int $port, string $user, string $password, string $dbName)
    {
        $dsn = "mysql:host={$host};port={$port};dbname={$dbName};charset=utf8mb4";
        $this->pdo = new PDO($dsn, $user, $password, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_EMULATE_PREPARES => true,
        ]);
        $this->dbName = $dbName;
    }

    public function query(string $sql): array
    {
        $this->queryLog[] = $sql;
        try {
            $stmt = $this->pdo->query($sql);
        } catch (PDOException $e) {
            // Writes and DDL are filtered by CowDatabase; propagate hard errors.
            throw $e;
        }
        if ($stmt === false) {
            return [];
        }
        // Detect SELECT (or SHOW/DESC): those have a result set.
        if ($stmt->columnCount() === 0) {
            return [];
        }
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        // Byte-approx: sum of strlen() across all scalar values in the rows.
        foreach ($rows as $row) {
            foreach ($row as $v) {
                $this->bytesReceived += is_string($v) ? strlen($v) : (is_scalar($v) ? strlen((string) $v) : 0);
            }
        }
        return $rows;
    }

    public function getTableSchema(string $table): ?array
    {
        if (array_key_exists($table, $this->schemaCache)) {
            return $this->schemaCache[$table];
        }
        $stmt = $this->pdo->prepare(
            "SELECT COLUMN_NAME, COLUMN_TYPE, COLUMN_KEY
             FROM INFORMATION_SCHEMA.COLUMNS
             WHERE TABLE_SCHEMA = :db AND TABLE_NAME = :t
             ORDER BY ORDINAL_POSITION"
        );
        $stmt->execute([':db' => $this->dbName, ':t' => $table]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        if (empty($rows)) {
            $this->schemaCache[$table] = null;
            return null;
        }
        $columns = [];
        $pk = null;
        foreach ($rows as $r) {
            $columns[$r['COLUMN_NAME']] = $r['COLUMN_TYPE'];
            if ($r['COLUMN_KEY'] === 'PRI' && $pk === null) {
                $pk = $r['COLUMN_NAME'];
            }
        }
        $this->schemaCache[$table] = [
            'columns'     => $columns,
            'primary_key' => $pk ?? 'ID',
        ];
        return $this->schemaCache[$table];
    }

    public function tableExists(string $table): bool
    {
        if (isset($this->tableExistsCache[$table])) {
            return $this->tableExistsCache[$table];
        }
        $stmt = $this->pdo->prepare(
            "SELECT 1 FROM INFORMATION_SCHEMA.TABLES
             WHERE TABLE_SCHEMA = :db AND TABLE_NAME = :t LIMIT 1"
        );
        $stmt->execute([':db' => $this->dbName, ':t' => $table]);
        return $this->tableExistsCache[$table] = (bool) $stmt->fetchColumn();
    }

    // ---- Test-only accessors ----

    public function getQueryLog(): array           { return $this->queryLog; }
    public function getQueryCount(): int           { return count($this->queryLog); }
    public function getBytesReceived(): int        { return $this->bytesReceived; }
    public function resetCounters(): void
    {
        $this->queryLog = [];
        $this->bytesReceived = 0;
    }
    public function getPdo(): PDO                  { return $this->pdo; }
}
