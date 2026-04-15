<?php

namespace CowClone;

/**
 * Interface for accessing remote MySQL tables.
 */
interface RemoteTableClient
{
    /**
     * List all table names in the remote database.
     * @return string[]
     */
    public function listTables(): array;

    /**
     * Get the MySQL CREATE TABLE statement for a table.
     */
    public function getTableSchema(string $tableName): string;

    /**
     * Get all rows from a table as associative arrays.
     * @return array<int, array<string, mixed>>
     */
    public function getTableRows(string $tableName): array;

    /**
     * Get the row count for a table.
     */
    public function getTableRowCount(string $tableName): int;
}

/**
 * In-memory mock implementation for testing.
 */
class MockRemoteTableClient implements RemoteTableClient
{
    /** @var array<string, array{schema: string, rows: array}> */
    private array $tables = [];

    /** @var array<string, int> Virtual row counts for tables with no actual rows stored */
    private array $virtualRowCounts = [];

    /** @var int Counter tracking how many times getTableRows has been called */
    public int $fetchCount = 0;

    /** @var array<string, int> Per-table fetch counters */
    public array $tableFetchCounts = [];

    /**
     * Add a table with its MySQL schema and rows.
     *
     * @param string $tableName
     * @param string $mysqlSchema MySQL CREATE TABLE statement
     * @param array $rows Array of associative arrays representing rows
     */
    public function addTable(string $tableName, string $mysqlSchema, array $rows = []): void
    {
        $this->tables[$tableName] = [
            'schema' => $mysqlSchema,
            'rows'   => $rows,
        ];
    }

    /**
     * Add a virtual table that reports a large row count but has no actual data.
     * Useful for testing that startup doesn't fetch row data.
     */
    public function addVirtualTable(string $tableName, string $mysqlSchema, int $virtualRowCount): void
    {
        $this->tables[$tableName] = [
            'schema' => $mysqlSchema,
            'rows'   => [],
        ];
        $this->virtualRowCounts[$tableName] = $virtualRowCount;
    }

    public function listTables(): array
    {
        return array_keys($this->tables);
    }

    public function getTableSchema(string $tableName): string
    {
        if (!isset($this->tables[$tableName])) {
            throw new \RuntimeException("Table '{$tableName}' not found on remote");
        }
        return $this->tables[$tableName]['schema'];
    }

    public function getTableRows(string $tableName): array
    {
        if (!isset($this->tables[$tableName])) {
            throw new \RuntimeException("Table '{$tableName}' not found on remote");
        }
        $this->fetchCount++;
        if (!isset($this->tableFetchCounts[$tableName])) {
            $this->tableFetchCounts[$tableName] = 0;
        }
        $this->tableFetchCounts[$tableName]++;
        return $this->tables[$tableName]['rows'];
    }

    public function getTableRowCount(string $tableName): int
    {
        if (!isset($this->tables[$tableName])) {
            throw new \RuntimeException("Table '{$tableName}' not found on remote");
        }
        if (isset($this->virtualRowCounts[$tableName])) {
            return $this->virtualRowCounts[$tableName];
        }
        return count($this->tables[$tableName]['rows']);
    }
}
