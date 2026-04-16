<?php

namespace CowClone;

use PDO;

class LocalOverlayStore
{
    private PDO $db;

    /** @var array<string, bool> Tables that have been initialized in overlay */
    private array $initializedTables = [];

    /** @var array<string, bool> Tables created entirely locally (not on remote) */
    private array $localOnlyTables = [];

    /** @var int Counter for auto-generated negative IDs */
    private int $nextLocalId = -1;

    public function __construct(?PDO $db = null)
    {
        if ($db === null) {
            $this->db = new PDO('sqlite::memory:');
        } else {
            $this->db = $db;
        }
        $this->db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->initMetaTables();
    }

    /**
     * Initialize the metadata tables that track overlay operations.
     */
    private function initMetaTables(): void
    {
        $this->db->exec("
            CREATE TABLE IF NOT EXISTS _cow_inserts (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                table_name TEXT NOT NULL,
                local_id INTEGER NOT NULL,
                row_data TEXT NOT NULL,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP
            )
        ");

        $this->db->exec("
            CREATE TABLE IF NOT EXISTS _cow_updates (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                table_name TEXT NOT NULL,
                row_key TEXT NOT NULL,
                pk_column TEXT NOT NULL DEFAULT 'id',
                row_data TEXT NOT NULL,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                UNIQUE(table_name, row_key, pk_column)
            )
        ");

        $this->db->exec("
            CREATE TABLE IF NOT EXISTS _cow_deletes (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                table_name TEXT NOT NULL,
                row_key TEXT NOT NULL,
                pk_column TEXT NOT NULL DEFAULT 'id',
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                UNIQUE(table_name, row_key, pk_column)
            )
        ");

        $this->db->exec("
            CREATE TABLE IF NOT EXISTS _cow_local_tables (
                table_name TEXT PRIMARY KEY,
                schema_sql TEXT NOT NULL,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP
            )
        ");
    }

    /**
     * Record a row insertion.
     *
     * @param string $table
     * @param array $data Column => value pairs
     * @param string $pkColumn Primary key column name
     * @return int The local (negative) ID assigned
     */
    public function recordInsert(string $table, array $data, string $pkColumn = 'id'): int
    {
        $localId = $this->nextLocalId--;

        // Assign the local negative ID if the pk column is not set
        if (!isset($data[$pkColumn])) {
            $data[$pkColumn] = $localId;
        } else {
            $localId = (int)$data[$pkColumn];
        }

        $stmt = $this->db->prepare(
            "INSERT INTO _cow_inserts (table_name, local_id, row_data) VALUES (?, ?, ?)"
        );
        $stmt->execute([$table, $localId, json_encode($data)]);

        return $localId;
    }

    /**
     * Record a row update.
     *
     * @param string $table
     * @param mixed $rowKey The primary key value of the row being updated
     * @param array $data Column => value pairs (only changed columns)
     * @param string $pkColumn Primary key column name
     */
    public function recordUpdate(string $table, $rowKey, array $data, string $pkColumn = 'id'): void
    {
        $rowKeyStr = (string)$rowKey;

        // Check if this is a locally inserted row
        $existing = $this->getInsertedRowByKey($table, $rowKeyStr);
        if ($existing !== null) {
            // Merge updates into the insert record
            $merged = array_merge($existing, $data);
            $stmt = $this->db->prepare(
                "UPDATE _cow_inserts SET row_data = ? WHERE table_name = ? AND local_id = ?"
            );
            $stmt->execute([json_encode($merged), $table, (int)$rowKeyStr]);
            return;
        }

        // Check if there's already an update record
        $stmt = $this->db->prepare(
            "SELECT row_data FROM _cow_updates WHERE table_name = ? AND row_key = ? AND pk_column = ?"
        );
        $stmt->execute([$table, $rowKeyStr, $pkColumn]);
        $existingUpdate = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($existingUpdate) {
            $merged = array_merge(json_decode($existingUpdate['row_data'], true), $data);
            $stmt = $this->db->prepare(
                "UPDATE _cow_updates SET row_data = ? WHERE table_name = ? AND row_key = ? AND pk_column = ?"
            );
            $stmt->execute([json_encode($merged), $table, $rowKeyStr, $pkColumn]);
        } else {
            $stmt = $this->db->prepare(
                "INSERT INTO _cow_updates (table_name, row_key, pk_column, row_data) VALUES (?, ?, ?, ?)"
            );
            $stmt->execute([$table, $rowKeyStr, $pkColumn, json_encode($data)]);
        }
    }

    /**
     * Record a row deletion.
     *
     * @param string $table
     * @param mixed $rowKey The primary key value
     * @param string $pkColumn Primary key column name
     */
    public function recordDelete(string $table, $rowKey, string $pkColumn = 'id'): void
    {
        $rowKeyStr = (string)$rowKey;

        // If it was a locally inserted row, just remove the insert
        $stmt = $this->db->prepare(
            "DELETE FROM _cow_inserts WHERE table_name = ? AND local_id = ?"
        );
        $stmt->execute([$table, (int)$rowKeyStr]);
        if ($stmt->rowCount() > 0) {
            // Was a local insert, no need to record a delete from remote
            return;
        }

        // Remove any update records for this row
        $stmt = $this->db->prepare(
            "DELETE FROM _cow_updates WHERE table_name = ? AND row_key = ? AND pk_column = ?"
        );
        $stmt->execute([$table, $rowKeyStr, $pkColumn]);

        // Record the delete (for remote rows)
        $stmt = $this->db->prepare(
            "INSERT OR IGNORE INTO _cow_deletes (table_name, row_key, pk_column) VALUES (?, ?, ?)"
        );
        $stmt->execute([$table, $rowKeyStr, $pkColumn]);
    }

    /**
     * Get all locally inserted rows for a table.
     *
     * @param string $table
     * @return array Array of row data arrays
     */
    public function getInsertedRows(string $table): array
    {
        $stmt = $this->db->prepare(
            "SELECT row_data FROM _cow_inserts WHERE table_name = ? ORDER BY id"
        );
        $stmt->execute([$table]);
        $rows = [];
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $rows[] = json_decode($row['row_data'], true);
        }
        return $rows;
    }

    /**
     * Get all locally updated rows for a table.
     *
     * @param string $table
     * @return array Keyed by row_key => updated data
     */
    public function getUpdatedRows(string $table): array
    {
        $stmt = $this->db->prepare(
            "SELECT row_key, row_data FROM _cow_updates WHERE table_name = ?"
        );
        $stmt->execute([$table]);
        $rows = [];
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $rows[$row['row_key']] = json_decode($row['row_data'], true);
        }
        return $rows;
    }

    /**
     * Get all deleted row keys for a table.
     *
     * @param string $table
     * @return array Array of row key strings
     */
    public function getDeletedKeys(string $table): array
    {
        $stmt = $this->db->prepare(
            "SELECT row_key FROM _cow_deletes WHERE table_name = ?"
        );
        $stmt->execute([$table]);
        return $stmt->fetchAll(PDO::FETCH_COLUMN);
    }

    /**
     * Check if a specific row has been deleted locally.
     *
     * @param string $table
     * @param mixed $rowKey
     * @param string $pkColumn
     * @return bool
     */
    public function isRowDeleted(string $table, $rowKey, string $pkColumn = 'id'): bool
    {
        $stmt = $this->db->prepare(
            "SELECT COUNT(*) FROM _cow_deletes WHERE table_name = ? AND row_key = ? AND pk_column = ?"
        );
        $stmt->execute([$table, (string)$rowKey, $pkColumn]);
        return (int)$stmt->fetchColumn() > 0;
    }

    /**
     * Check if a table exists only locally.
     */
    public function hasLocalTable(string $table): bool
    {
        $stmt = $this->db->prepare(
            "SELECT COUNT(*) FROM _cow_local_tables WHERE table_name = ?"
        );
        $stmt->execute([$table]);
        return (int)$stmt->fetchColumn() > 0;
    }

    /**
     * Create a new local-only table.
     *
     * @param string $table
     * @param string $schemaSql The CREATE TABLE SQL
     */
    public function createLocalTable(string $table, string $schemaSql): void
    {
        $stmt = $this->db->prepare(
            "INSERT OR REPLACE INTO _cow_local_tables (table_name, schema_sql) VALUES (?, ?)"
        );
        $stmt->execute([$table, $schemaSql]);
        $this->localOnlyTables[$table] = true;
    }

    /**
     * Query a local-only table. Returns rows matching a simple WHERE condition.
     * For local-only tables, all data is in the inserts table.
     *
     * @param string $table
     * @param array|null $where Simple key=>value conditions (AND)
     * @return array
     */
    public function queryLocalTable(string $table, ?array $where = null): array
    {
        $rows = $this->getInsertedRows($table);

        if ($where === null) {
            return $rows;
        }

        return array_values(array_filter($rows, function ($row) use ($where) {
            foreach ($where as $col => $val) {
                if (!isset($row[$col]) || $row[$col] != $val) {
                    return false;
                }
            }
            return true;
        }));
    }

    /**
     * Get a single inserted row by its key value.
     */
    private function getInsertedRowByKey(string $table, string $key): ?array
    {
        $stmt = $this->db->prepare(
            "SELECT row_data FROM _cow_inserts WHERE table_name = ? AND local_id = ?"
        );
        $stmt->execute([$table, (int)$key]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row) {
            return json_decode($row['row_data'], true);
        }
        return null;
    }

    /**
     * Get the underlying PDO connection (for testing).
     */
    public function getPdo(): PDO
    {
        return $this->db;
    }
}
