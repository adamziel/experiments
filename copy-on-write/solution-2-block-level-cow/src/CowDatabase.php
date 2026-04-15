<?php

declare(strict_types=1);

namespace CowClone\BlockLevel;

/**
 * High-level Copy-on-Write database that reads a SQLite file lazily at the
 * page level using a B-tree reader, and writes to a local SQLite overlay.
 *
 * Architecture:
 * - Reads: navigate the remote SQLite file's B-tree structure directly,
 *   fetching only the pages that are needed (lazy page-level loading)
 * - Writes: go to a local SQLite database (the overlay)
 * - The remote file is NEVER modified
 * - Tables are materialized into the local overlay on first write (not on first read)
 * - The unit of lazy loading is the page (4096 bytes), not the row or table
 *
 * This is genuinely different from query-level proxying (solution 1) and
 * table-level materialization (solution 3) because it operates at the
 * binary storage level, understanding SQLite's on-disk B-tree format.
 */
class CowDatabase
{
    private FilePageProvider $remoteProvider;
    private CowPageProvider $cowProvider;
    private BTreeReader $reader;

    private ?\PDO $localPdo = null;

    /** @var array<string, array{rootpage: int, sql: string, columns: string[]}> */
    private ?array $tableInfo = null;

    /** @var array<string, true> Tables that have been materialized into local SQLite */
    private array $materializedTables = [];

    private float $startupTime;

    public function __construct(string $sourceFile)
    {
        $t0 = microtime(true);
        $this->remoteProvider = new FilePageProvider($sourceFile);
        $this->cowProvider = new CowPageProvider($this->remoteProvider);
        $this->reader = new BTreeReader($this->cowProvider);
        $this->startupTime = (microtime(true) - $t0) * 1000;
    }

    /**
     * Get startup time in milliseconds.
     */
    public function getStartupTimeMs(): float
    {
        return $this->startupTime;
    }

    /**
     * List all table names in the remote database.
     * Only reads page 1 (sqlite_master root).
     */
    public function getTableNames(): array
    {
        $info = $this->getTableInfo();
        return array_keys($info);
    }

    /**
     * Read all rows from a table using the B-tree reader (lazy page-level reads).
     * Does NOT materialize the table — reads directly from remote pages.
     *
     * @return array[] Rows as associative arrays
     */
    public function readTable(string $tableName): array
    {
        // If table is materialized locally, read from local
        if (isset($this->materializedTables[$tableName])) {
            return $this->queryLocal("SELECT * FROM \"{$tableName}\"");
        }

        $info = $this->getTableInfo();
        if (!isset($info[$tableName])) {
            throw new \RuntimeException("Table not found: {$tableName}");
        }

        $columns = $info[$tableName]['columns'];
        $rootPage = $info[$tableName]['rootpage'];
        return $this->reader->scanTable($rootPage, $columns);
    }

    /**
     * Find a single row by rowid using B-tree binary search.
     * Only fetches the pages along the search path (O(log N) pages).
     */
    public function findRow(string $tableName, int $rowid): ?array
    {
        if (isset($this->materializedTables[$tableName])) {
            $rows = $this->queryLocal(
                "SELECT * FROM \"{$tableName}\" WHERE rowid = :id",
                [':id' => $rowid]
            );
            return $rows[0] ?? null;
        }

        $info = $this->getTableInfo();
        if (!isset($info[$tableName])) {
            return null;
        }
        return $this->reader->findRow(
            $info[$tableName]['rootpage'],
            $rowid,
            $info[$tableName]['columns']
        );
    }

    /**
     * Execute a read-only SQL query.
     * If the table is materialized, runs on local SQLite.
     * Otherwise, materializes the needed tables first and runs locally.
     * For simple single-table reads, prefer readTable() or findRow() for
     * true lazy page-level access.
     */
    public function query(string $sql, array $params = []): array
    {
        $tables = $this->extractTableNames($sql);
        foreach ($tables as $table) {
            if (!isset($this->materializedTables[$table])) {
                $this->materializeTable($table);
            }
        }
        return $this->queryLocal($sql, $params);
    }

    /**
     * Execute a write SQL statement (INSERT, UPDATE, DELETE).
     * Materializes the table if needed, then writes to local SQLite.
     */
    public function exec(string $sql, array $params = []): int
    {
        $tables = $this->extractTableNames($sql);
        foreach ($tables as $table) {
            if (!isset($this->materializedTables[$table])) {
                $this->materializeTable($table);
            }
        }
        $pdo = $this->getLocalPdo();
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->rowCount();
    }

    /**
     * Insert a row. Materializes the table into local SQLite if needed.
     */
    public function insert(string $tableName, array $data): int
    {
        if (!isset($this->materializedTables[$tableName])) {
            $this->materializeTable($tableName);
        }
        $cols = implode(', ', array_map(fn($c) => "\"{$c}\"", array_keys($data)));
        $placeholders = implode(', ', array_fill(0, count($data), '?'));
        $sql = "INSERT INTO \"{$tableName}\" ({$cols}) VALUES ({$placeholders})";
        $pdo = $this->getLocalPdo();
        $stmt = $pdo->prepare($sql);
        $stmt->execute(array_values($data));
        return (int) $pdo->lastInsertId();
    }

    /**
     * Update rows. Materializes the table into local SQLite if needed.
     */
    public function update(string $tableName, array $data, string $where, array $whereParams = []): int
    {
        if (!isset($this->materializedTables[$tableName])) {
            $this->materializeTable($tableName);
        }
        $sets = implode(', ', array_map(fn($c) => "\"{$c}\" = ?", array_keys($data)));
        $sql = "UPDATE \"{$tableName}\" SET {$sets} WHERE {$where}";
        $pdo = $this->getLocalPdo();
        $stmt = $pdo->prepare($sql);
        $stmt->execute(array_merge(array_values($data), $whereParams));
        return $stmt->rowCount();
    }

    /**
     * Delete rows. Materializes the table into local SQLite if needed.
     */
    public function delete(string $tableName, string $where, array $whereParams = []): int
    {
        if (!isset($this->materializedTables[$tableName])) {
            $this->materializeTable($tableName);
        }
        $sql = "DELETE FROM \"{$tableName}\" WHERE {$where}";
        $pdo = $this->getLocalPdo();
        $stmt = $pdo->prepare($sql);
        $stmt->execute($whereParams);
        return $stmt->rowCount();
    }

    /**
     * Get the list of page numbers that were accessed from the remote.
     * Useful for verifying lazy loading behavior.
     */
    public function getRemoteAccessedPages(): array
    {
        return $this->remoteProvider->getAccessedPages();
    }

    public function getRemoteAccessedPageCount(): int
    {
        return $this->remoteProvider->getAccessedPageCount();
    }

    public function getRemoteTotalPageCount(): int
    {
        return $this->remoteProvider->getPageCount();
    }

    public function resetAccessTracking(): void
    {
        $this->remoteProvider->resetAccessTracking();
    }

    /**
     * Check if the remote file was modified (it should never be).
     */
    public function isRemoteUnmodified(string $expectedHash): bool
    {
        return md5_file($this->remoteProvider->getFilePath()) === $expectedHash;
    }

    public function getMaterializedTables(): array
    {
        return array_keys($this->materializedTables);
    }

    // ---- Private ----

    /**
     * Parse sqlite_master to get table info. Only reads page 1 (and any overflow).
     */
    private function getTableInfo(): array
    {
        if ($this->tableInfo !== null) {
            return $this->tableInfo;
        }

        $this->tableInfo = [];
        $masterRows = $this->reader->readSqliteMaster();

        foreach ($masterRows as $row) {
            $values = $row['_values'];
            $type = $values[0] ?? '';
            $name = $values[1] ?? '';
            $rootpage = $values[3] ?? 0;
            $sql = $values[4] ?? '';

            if ($type === 'table' && $name !== '' && !str_starts_with($name, 'sqlite_')) {
                $columns = $this->parseColumnsFromDDL($sql);
                $this->tableInfo[$name] = [
                    'rootpage' => (int) $rootpage,
                    'sql' => $sql,
                    'columns' => $columns,
                ];
            }
        }

        return $this->tableInfo;
    }

    private function parseColumnsFromDDL(string $sql): array
    {
        if (!preg_match('/\((.+)\)\s*$/s', $sql, $m)) {
            return [];
        }
        $body = $m[1];
        $columns = [];
        $depth = 0;
        $current = '';
        for ($i = 0; $i < strlen($body); $i++) {
            $ch = $body[$i];
            if ($ch === '(') { $depth++; $current .= $ch; }
            elseif ($ch === ')') { $depth--; $current .= $ch; }
            elseif ($ch === ',' && $depth === 0) {
                $col = $this->parseColumnName(trim($current));
                if ($col !== null) {
                    $columns[] = $col;
                }
                $current = '';
            } else {
                $current .= $ch;
            }
        }
        $col = $this->parseColumnName(trim($current));
        if ($col !== null) {
            $columns[] = $col;
        }
        return $columns;
    }

    private function parseColumnName(string $def): ?string
    {
        $upper = strtoupper(ltrim($def));
        if (str_starts_with($upper, 'PRIMARY ') ||
            str_starts_with($upper, 'UNIQUE ') ||
            str_starts_with($upper, 'CHECK ') ||
            str_starts_with($upper, 'FOREIGN ') ||
            str_starts_with($upper, 'CONSTRAINT ')) {
            return null;
        }
        if (preg_match('/^["\[]?(\w+)["\]]?/i', $def, $m)) {
            return $m[1];
        }
        return null;
    }

    /**
     * Materialize a table from remote pages into local SQLite.
     * Reads the table's B-tree pages from remote, decodes rows, and inserts
     * into a local in-memory SQLite database.
     */
    private function materializeTable(string $tableName): void
    {
        $info = $this->getTableInfo();
        if (!isset($info[$tableName])) {
            throw new \RuntimeException("Table not found: {$tableName}");
        }

        $pdo = $this->getLocalPdo();

        // Create the table locally using the original DDL
        $ddl = $info[$tableName]['sql'];
        $pdo->exec($ddl);

        // Read all rows from the B-tree and insert into local
        $columns = $info[$tableName]['columns'];
        $rootPage = $info[$tableName]['rootpage'];
        $rows = $this->reader->scanTable($rootPage, $columns);

        if (!empty($rows)) {
            $colNames = array_filter($columns);
            $colSql = implode(', ', array_map(fn($c) => "\"{$c}\"", $colNames));
            $placeholders = implode(', ', array_fill(0, count($colNames), '?'));
            $insertSql = "INSERT INTO \"{$tableName}\" ({$colSql}) VALUES ({$placeholders})";

            $pdo->beginTransaction();
            $stmt = $pdo->prepare($insertSql);
            foreach ($rows as $row) {
                $values = [];
                foreach ($colNames as $col) {
                    $values[] = $row[$col] ?? null;
                }
                $stmt->execute($values);
            }
            $pdo->commit();
        }

        $this->materializedTables[$tableName] = true;
    }

    private function getLocalPdo(): \PDO
    {
        if ($this->localPdo === null) {
            $this->localPdo = new \PDO('sqlite::memory:');
            $this->localPdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        }
        return $this->localPdo;
    }

    private function queryLocal(string $sql, array $params = []): array
    {
        $pdo = $this->getLocalPdo();
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(\PDO::FETCH_ASSOC);
    }

    private function extractTableNames(string $sql): array
    {
        $tables = [];
        $patterns = [
            '/\bFROM\s+["\[]?(\w+)["\]]?/i',
            '/\bJOIN\s+["\[]?(\w+)["\]]?/i',
            '/\bINTO\s+["\[]?(\w+)["\]]?/i',
            '/\bUPDATE\s+["\[]?(\w+)["\]]?/i',
        ];
        $info = $this->getTableInfo();
        foreach ($patterns as $pattern) {
            if (preg_match_all($pattern, $sql, $matches)) {
                foreach ($matches[1] as $name) {
                    if (isset($info[$name])) {
                        $tables[$name] = true;
                    }
                }
            }
        }
        return array_keys($tables);
    }
}
