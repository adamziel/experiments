<?php

namespace CowClone;

/**
 * Main orchestrator for copy-on-write database operations.
 *
 * Parses SQL to find referenced tables, materializes them on first access,
 * then executes queries on local SQLite. Logs all mutations to the change journal.
 */
class CowDatabase
{
    private RemoteTableClient $remote;
    private \PDO $db;
    private TableMaterializer $materializer;
    private MaterializationTracker $tracker;
    private ChangeJournal $journal;

    /** @var string[] Cached list of remote table names */
    private array $remoteTables;

    public function __construct(RemoteTableClient $remote, \PDO $db)
    {
        $this->remote = $remote;
        $this->db = $db;
        $this->db->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);

        $this->tracker = new MaterializationTracker($db);
        $this->journal = new ChangeJournal($db);

        $translator = new SchemaTranslator();
        $this->materializer = new TableMaterializer($remote, $db, $translator, $this->tracker);

        // Cache the list of remote tables at startup (lightweight operation)
        $this->remoteTables = $remote->listTables();
    }

    /**
     * Execute a read query (SELECT). Returns result rows.
     *
     * @param string $sql
     * @param array $params
     * @return array<int, array<string, mixed>>
     */
    public function query(string $sql, array $params = []): array
    {
        $this->materializeReferencedTables($sql);

        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(\PDO::FETCH_ASSOC);
    }

    /**
     * Execute a write statement (INSERT/UPDATE/DELETE).
     * Materializes referenced tables and logs changes to the journal.
     *
     * @param string $sql
     * @param array $params
     * @return int Number of affected rows
     */
    public function exec(string $sql, array $params = []): int
    {
        $this->materializeReferencedTables($sql);

        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        return $stmt->rowCount();
    }

    /**
     * Convenience method for INSERT.
     *
     * @param string $table
     * @param array<string, mixed> $data Column => value pairs
     * @return int Last insert ID
     */
    public function insert(string $table, array $data): int
    {
        $this->materializeIfNeeded($table);

        $columns = array_keys($data);
        $quotedCols = implode(', ', array_map(function ($c) { return '"' . $c . '"'; }, $columns));
        $placeholders = implode(', ', array_map(function ($c) { return ':' . $c; }, $columns));

        $sql = 'INSERT INTO "' . $table . '" (' . $quotedCols . ') VALUES (' . $placeholders . ')';
        $params = [];
        foreach ($data as $col => $val) {
            $params[':' . $col] = $val;
        }

        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);

        $insertId = (int) $this->db->lastInsertId();

        // Record in journal
        $journalData = $data;
        if ($insertId > 0 && !isset($journalData['id'])) {
            // Try to capture the auto-generated ID if applicable
            $journalData['_insert_id'] = $insertId;
        }
        $this->journal->recordInsert($table, $journalData);

        return $insertId;
    }

    /**
     * Convenience method for UPDATE.
     *
     * @param string $table
     * @param array<string, mixed> $data Column => value pairs to set
     * @param string $where WHERE clause (without 'WHERE')
     * @param array $whereParams Parameters for the WHERE clause
     * @return int Number of affected rows
     */
    public function update(string $table, array $data, string $where, array $whereParams = []): int
    {
        $this->materializeIfNeeded($table);

        // Capture old data for journal
        $selectSql = 'SELECT * FROM "' . $table . '" WHERE ' . $where;
        $selectStmt = $this->db->prepare($selectSql);
        $selectStmt->execute($whereParams);
        $oldRows = $selectStmt->fetchAll(\PDO::FETCH_ASSOC);

        // Build UPDATE
        $setClauses = [];
        $params = [];
        foreach ($data as $col => $val) {
            $setClauses[] = '"' . $col . '" = :set_' . $col;
            $params[':set_' . $col] = $val;
        }
        $sql = 'UPDATE "' . $table . '" SET ' . implode(', ', $setClauses) . ' WHERE ' . $where;
        $params = array_merge($params, $whereParams);

        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        $affected = $stmt->rowCount();

        // Record in journal
        foreach ($oldRows as $oldRow) {
            $newRow = array_merge($oldRow, $data);
            $this->journal->recordUpdate($table, $oldRow, $newRow);
        }

        return $affected;
    }

    /**
     * Convenience method for DELETE.
     *
     * @param string $table
     * @param string $where WHERE clause (without 'WHERE')
     * @param array $whereParams Parameters for the WHERE clause
     * @return int Number of affected rows
     */
    public function delete(string $table, string $where, array $whereParams = []): int
    {
        $this->materializeIfNeeded($table);

        // Capture old data for journal
        $selectSql = 'SELECT * FROM "' . $table . '" WHERE ' . $where;
        $selectStmt = $this->db->prepare($selectSql);
        $selectStmt->execute($whereParams);
        $oldRows = $selectStmt->fetchAll(\PDO::FETCH_ASSOC);

        // Execute DELETE
        $sql = 'DELETE FROM "' . $table . '" WHERE ' . $where;
        $stmt = $this->db->prepare($sql);
        $stmt->execute($whereParams);
        $affected = $stmt->rowCount();

        // Record in journal
        foreach ($oldRows as $oldRow) {
            $this->journal->recordDelete($table, $oldRow);
        }

        return $affected;
    }

    /**
     * Get the change journal instance.
     */
    public function getJournal(): ChangeJournal
    {
        return $this->journal;
    }

    /**
     * Get the materialization tracker instance.
     */
    public function getTracker(): MaterializationTracker
    {
        return $this->tracker;
    }

    /**
     * Get the underlying PDO connection.
     */
    public function getPdo(): \PDO
    {
        return $this->db;
    }

    /**
     * Materialize all tables referenced in a SQL statement.
     */
    private function materializeReferencedTables(string $sql): void
    {
        $tables = $this->extractTableNames($sql);
        foreach ($tables as $table) {
            $this->materializeIfNeeded($table);
        }
    }

    /**
     * Materialize a table if it's a known remote table and not yet materialized.
     */
    private function materializeIfNeeded(string $table): void
    {
        if (in_array($table, $this->remoteTables, true) && !$this->tracker->isMaterialized($table)) {
            $this->materializer->materializeTable($table);
        }
    }

    /**
     * Extract table names from a SQL statement.
     *
     * Handles:
     * - SELECT ... FROM table
     * - SELECT ... FROM table AS alias
     * - INSERT INTO table
     * - UPDATE table SET ...
     * - DELETE FROM table
     * - JOIN table
     * - Subqueries in FROM clause
     */
    public function extractTableNames(string $sql): array
    {
        $tables = [];

        // Normalize whitespace
        $normalized = preg_replace('/\s+/', ' ', trim($sql));

        // FROM clause (handles multiple tables, aliases)
        if (preg_match_all('/\bFROM\s+[`"]?(\w+)[`"]?/i', $normalized, $matches)) {
            $tables = array_merge($tables, $matches[1]);
        }

        // JOIN clause
        if (preg_match_all('/\bJOIN\s+[`"]?(\w+)[`"]?/i', $normalized, $matches)) {
            $tables = array_merge($tables, $matches[1]);
        }

        // INSERT INTO
        if (preg_match_all('/\bINSERT\s+(?:OR\s+\w+\s+)?INTO\s+[`"]?(\w+)[`"]?/i', $normalized, $matches)) {
            $tables = array_merge($tables, $matches[1]);
        }

        // UPDATE
        if (preg_match_all('/\bUPDATE\s+[`"]?(\w+)[`"]?/i', $normalized, $matches)) {
            $tables = array_merge($tables, $matches[1]);
        }

        // Filter to only known remote tables (avoid matching internal tables, aliases, etc.)
        $tables = array_unique($tables);
        $tables = array_filter($tables, function ($t) {
            return in_array($t, $this->remoteTables, true);
        });

        return array_values($tables);
    }
}
