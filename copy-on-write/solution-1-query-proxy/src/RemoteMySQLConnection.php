<?php

namespace CowClone;

/**
 * Interface for connecting to a remote MySQL server.
 */
interface RemoteMySQLConnectionInterface
{
    /**
     * Execute a query against the remote database.
     *
     * @param string $sql
     * @return array Array of associative arrays (rows)
     */
    public function query(string $sql): array;

    /**
     * Get the schema for a table.
     *
     * @param string $table
     * @return array Array with 'columns' (name=>type) and 'primary_key' keys
     */
    public function getTableSchema(string $table): ?array;

    /**
     * Check if a table exists on the remote.
     */
    public function tableExists(string $table): bool;
}

/**
 * Mock implementation of remote MySQL connection for testing.
 * Stores data in memory. Tracks whether any write queries were received.
 */
class MockRemoteConnection implements RemoteMySQLConnectionInterface
{
    /** @var array<string, array> Table data: tableName => [rows] */
    private array $tables = [];

    /** @var array<string, array> Table schemas: tableName => ['columns' => [...], 'primary_key' => '...'] */
    private array $schemas = [];

    /** @var array Write queries received (should be empty in COW mode) */
    private array $writeQueries = [];

    /** @var int Simulated row count for performance testing */
    private int $simulatedRowCount = 0;

    /** @var QueryClassifier */
    private QueryClassifier $classifier;

    public function __construct()
    {
        $this->classifier = new QueryClassifier();
    }

    /**
     * Add a table with data to the mock.
     *
     * @param string $table Table name
     * @param array $columns Column definitions: ['id' => 'INTEGER', 'name' => 'VARCHAR(255)']
     * @param array $rows Array of associative arrays
     * @param string $primaryKey Primary key column
     */
    public function addTable(string $table, array $columns, array $rows, string $primaryKey = 'id'): void
    {
        $this->schemas[$table] = [
            'columns' => $columns,
            'primary_key' => $primaryKey,
        ];
        $this->tables[$table] = $rows;
    }

    /**
     * Set simulated row count for performance testing.
     * This doesn't actually add rows but makes tableExists() and schema
     * lookups work as if the table had this many rows.
     */
    public function setSimulatedRowCount(int $count): void
    {
        $this->simulatedRowCount = $count;
    }

    /**
     * Execute a query against the mock database.
     */
    public function query(string $sql): array
    {
        $type = $this->classifier->classify($sql);

        if ($type === QueryClassifier::TYPE_WRITE || $type === QueryClassifier::TYPE_DDL) {
            $this->writeQueries[] = $sql;
            return [];
        }

        $table = $this->classifier->extractTableName($sql);
        if ($table === null || !isset($this->tables[$table])) {
            return [];
        }

        $rows = $this->tables[$table];

        // Simple WHERE clause parsing for basic testing
        if (preg_match('/WHERE\s+(.+?)(?:\s+ORDER|\s+LIMIT|\s+GROUP|\s*$)/i', $sql, $m)) {
            $rows = $this->applyWhereClause($rows, trim($m[1]));
        }

        // Simple LIMIT parsing
        if (preg_match('/LIMIT\s+(\d+)/i', $sql, $m)) {
            $rows = array_slice($rows, 0, (int)$m[1]);
        }

        return $rows;
    }

    /**
     * Get schema for a table.
     */
    public function getTableSchema(string $table): ?array
    {
        return $this->schemas[$table] ?? null;
    }

    /**
     * Check if a table exists.
     */
    public function tableExists(string $table): bool
    {
        return isset($this->tables[$table]);
    }

    /**
     * Get list of write queries received (for test assertions).
     */
    public function getWriteQueries(): array
    {
        return $this->writeQueries;
    }

    /**
     * Get the current data for a table (for test assertions).
     */
    public function getTableData(string $table): array
    {
        return $this->tables[$table] ?? [];
    }

    /**
     * Very simple WHERE clause evaluator for testing.
     * Supports: column = value, column = 'value', AND conditions.
     */
    private function applyWhereClause(array $rows, string $whereClause): array
    {
        // Split on AND
        $conditions = preg_split('/\s+AND\s+/i', $whereClause);

        return array_values(array_filter($rows, function ($row) use ($conditions) {
            foreach ($conditions as $condition) {
                $condition = trim($condition);

                // column != value
                if (preg_match('/^`?(\w+)`?\s*!=\s*[\'"]?(.+?)[\'"]?\s*$/i', $condition, $m)) {
                    $col = $m[1];
                    $val = $m[2];
                    if (isset($row[$col]) && (string)$row[$col] === $val) {
                        return false;
                    }
                    continue;
                }

                // column = value
                if (preg_match('/^`?(\w+)`?\s*=\s*[\'"]?(.+?)[\'"]?\s*$/i', $condition, $m)) {
                    $col = $m[1];
                    $val = $m[2];
                    if (!isset($row[$col]) || (string)$row[$col] !== $val) {
                        return false;
                    }
                }
            }
            return true;
        }));
    }
}
