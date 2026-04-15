<?php

namespace CowClone;

/**
 * Copy-on-Write Database - Main orchestrator.
 *
 * Routes queries:
 * - READ queries go to remote, results merged with local overlay
 * - WRITE queries go to local overlay only
 * - DDL queries are stored in overlay (new tables created locally)
 *
 * This is a standalone class for testability, not a WordPress db.php drop-in.
 */
class CowDatabase
{
    private RemoteMySQLConnectionInterface $remote;
    private LocalOverlayStore $overlay;
    private SchemaCache $schemaCache;
    private QueryClassifier $classifier;
    private ResultMerger $merger;

    /** @var int Number of queries executed */
    private int $queryCount = 0;

    public function __construct(
        RemoteMySQLConnectionInterface $remote,
        LocalOverlayStore $overlay,
        ?SchemaCache $schemaCache = null
    ) {
        $this->remote = $remote;
        $this->overlay = $overlay;
        $this->schemaCache = $schemaCache ?? new SchemaCache($remote);
        $this->classifier = new QueryClassifier();
        $this->merger = new ResultMerger();
    }

    /**
     * Execute a SQL query through the COW layer.
     *
     * @param string $sql The SQL query
     * @return array|int Array of rows for SELECT, affected row count for writes
     */
    public function query(string $sql)
    {
        $this->queryCount++;

        $type = $this->classifier->classify($sql);
        $queryType = $this->classifier->getQueryType($sql);
        $table = $this->classifier->extractTableName($sql);

        switch ($type) {
            case QueryClassifier::TYPE_READ:
                return $this->handleRead($sql, $table);

            case QueryClassifier::TYPE_WRITE:
                return $this->handleWrite($sql, $queryType, $table);

            case QueryClassifier::TYPE_DDL:
                return $this->handleDDL($sql, $queryType, $table);

            default:
                // Pass through other queries (SET, etc.)
                return [];
        }
    }

    /**
     * Handle a READ query.
     */
    private function handleRead(string $sql, ?string $table): array
    {
        // If this is a local-only table, query overlay directly
        if ($table !== null && $this->overlay->hasLocalTable($table)) {
            $where = $this->extractSimpleWhere($sql);
            return $this->overlay->queryLocalTable($table, $where);
        }

        // Query remote
        $remoteResults = $this->remote->query($sql);

        // If we can't determine the table, return remote results as-is
        if ($table === null) {
            return $remoteResults;
        }

        // Get the primary key for this table
        $pkColumn = $this->schemaCache->getPrimaryKey($table);

        // Extract simple WHERE for filtering local inserts
        $whereFilter = $this->extractSimpleWhere($sql);

        // Merge with local overlay
        return $this->merger->merge($table, $remoteResults, $this->overlay, $pkColumn, $whereFilter);
    }

    /**
     * Handle a WRITE query (INSERT, UPDATE, DELETE).
     */
    private function handleWrite(string $sql, string $queryType, ?string $table): int
    {
        if ($table === null) {
            return 0;
        }

        $pkColumn = $this->schemaCache->getPrimaryKey($table);

        switch ($queryType) {
            case QueryClassifier::QUERY_INSERT:
                return $this->handleInsert($sql, $table, $pkColumn);

            case QueryClassifier::QUERY_UPDATE:
                return $this->handleUpdate($sql, $table, $pkColumn);

            case QueryClassifier::QUERY_DELETE:
                return $this->handleDelete($sql, $table, $pkColumn);

            case QueryClassifier::QUERY_REPLACE:
                return $this->handleInsert($sql, $table, $pkColumn);

            default:
                return 0;
        }
    }

    /**
     * Handle a DDL query (CREATE TABLE, ALTER TABLE, DROP TABLE).
     */
    private function handleDDL(string $sql, string $queryType, ?string $table): int
    {
        if ($table === null) {
            return 0;
        }

        if ($queryType === QueryClassifier::QUERY_CREATE_TABLE) {
            $this->overlay->createLocalTable($table, $sql);
            // Register schema if we can parse columns
            $columns = $this->parseCreateTableColumns($sql);
            if (!empty($columns)) {
                $this->schemaCache->setSchema($table, [
                    'columns' => $columns,
                    'primary_key' => 'id',
                ]);
            }
            return 1;
        }

        return 0;
    }

    /**
     * Handle INSERT query.
     */
    private function handleInsert(string $sql, string $table, string $pkColumn): int
    {
        $data = $this->parseInsertValues($sql, $table);
        if (empty($data)) {
            return 0;
        }

        $this->overlay->recordInsert($table, $data, $pkColumn);
        return 1;
    }

    /**
     * Handle UPDATE query.
     */
    private function handleUpdate(string $sql, string $table, string $pkColumn): int
    {
        $setData = $this->parseSetClause($sql);
        $whereData = $this->extractSimpleWhere($sql);

        if (empty($setData)) {
            return 0;
        }

        // If WHERE specifies the PK, update that specific row
        if ($whereData && isset($whereData[$pkColumn])) {
            $this->overlay->recordUpdate($table, $whereData[$pkColumn], $setData, $pkColumn);
            return 1;
        }

        // For non-PK WHERE clauses, we need to find matching rows
        // First check remote results, then apply update to each
        if ($whereData) {
            $selectSql = "SELECT * FROM {$table} WHERE " . $this->buildWhereString($whereData);
            $remoteRows = $this->remote->query($selectSql);
            $mergedRows = $this->merger->merge($table, $remoteRows, $this->overlay, $pkColumn, $whereData);

            $count = 0;
            foreach ($mergedRows as $row) {
                if (isset($row[$pkColumn])) {
                    $this->overlay->recordUpdate($table, $row[$pkColumn], $setData, $pkColumn);
                    $count++;
                }
            }
            return $count;
        }

        return 0;
    }

    /**
     * Handle DELETE query.
     */
    private function handleDelete(string $sql, string $table, string $pkColumn): int
    {
        $whereData = $this->extractSimpleWhere($sql);

        if ($whereData && isset($whereData[$pkColumn])) {
            $this->overlay->recordDelete($table, $whereData[$pkColumn], $pkColumn);
            return 1;
        }

        // For non-PK WHERE, find matching rows and delete each
        if ($whereData) {
            $selectSql = "SELECT * FROM {$table} WHERE " . $this->buildWhereString($whereData);
            $remoteRows = $this->remote->query($selectSql);
            $mergedRows = $this->merger->merge($table, $remoteRows, $this->overlay, $pkColumn, $whereData);

            $count = 0;
            foreach ($mergedRows as $row) {
                if (isset($row[$pkColumn])) {
                    $this->overlay->recordDelete($table, $row[$pkColumn], $pkColumn);
                    $count++;
                }
            }
            return $count;
        }

        return 0;
    }

    /**
     * Parse INSERT values from SQL.
     * Supports: INSERT INTO table (col1, col2) VALUES ('val1', 'val2')
     * Supports: INSERT INTO table (col1, col2) VALUES (val1, val2)
     */
    private function parseInsertValues(string $sql, string $table): array
    {
        $normalized = preg_replace('/\s+/', ' ', trim($sql));

        // Try: INSERT INTO table (columns) VALUES (values)
        if (preg_match(
            '/INSERT\s+INTO\s+`?\w+`?\s*\(([^)]+)\)\s*VALUES\s*\(([^)]+)\)/i',
            $normalized,
            $m
        )) {
            $columns = array_map(function ($c) {
                return trim(trim($c), '`\'" ');
            }, explode(',', $m[1]));

            $values = $this->parseValuesList($m[2]);

            if (count($columns) === count($values)) {
                return array_combine($columns, $values);
            }
        }

        // Try: INSERT INTO table SET col1 = val1, col2 = val2
        if (preg_match('/INSERT\s+INTO\s+`?\w+`?\s+SET\s+(.+)/i', $normalized, $m)) {
            return $this->parseSetClauseString($m[1]);
        }

        return [];
    }

    /**
     * Parse SET clause from UPDATE query.
     */
    private function parseSetClause(string $sql): array
    {
        $normalized = preg_replace('/\s+/', ' ', trim($sql));

        if (preg_match('/\bSET\s+(.+?)(?:\s+WHERE\b|$)/i', $normalized, $m)) {
            return $this->parseSetClauseString($m[1]);
        }

        return [];
    }

    /**
     * Parse a SET clause string into key-value pairs.
     * Handles: col1 = 'val1', col2 = val2
     */
    private function parseSetClauseString(string $setStr): array
    {
        $data = [];
        // Split on commas that aren't inside quotes
        $parts = $this->splitOnCommas($setStr);

        foreach ($parts as $part) {
            $part = trim($part);
            if (preg_match('/^`?(\w+)`?\s*=\s*(.+)$/i', $part, $m)) {
                $col = $m[1];
                $val = trim($m[2]);
                $val = trim($val, "'\"");
                $data[$col] = $val;
            }
        }

        return $data;
    }

    /**
     * Extract simple WHERE conditions as key-value pairs.
     * Only handles: WHERE col1 = val1 AND col2 = val2
     */
    private function extractSimpleWhere(string $sql): ?array
    {
        $normalized = preg_replace('/\s+/', ' ', trim($sql));

        if (!preg_match('/\bWHERE\s+(.+?)(?:\s+ORDER\b|\s+LIMIT\b|\s+GROUP\b|\s*$)/i', $normalized, $m)) {
            return null;
        }

        $whereStr = trim($m[1]);
        $conditions = preg_split('/\s+AND\s+/i', $whereStr);
        $result = [];

        foreach ($conditions as $condition) {
            $condition = trim($condition);
            if (preg_match('/^`?(\w+)`?\s*=\s*[\'"]?([^\'"]*)[\'"]?$/i', $condition, $m)) {
                $result[$m[1]] = $m[2];
            }
        }

        return empty($result) ? null : $result;
    }

    /**
     * Build a WHERE string from key-value pairs.
     */
    private function buildWhereString(array $where): string
    {
        $parts = [];
        foreach ($where as $col => $val) {
            $parts[] = "`{$col}` = '{$val}'";
        }
        return implode(' AND ', $parts);
    }

    /**
     * Parse a comma-separated VALUES list, respecting quotes.
     */
    private function parseValuesList(string $valuesStr): array
    {
        $values = [];
        $parts = $this->splitOnCommas($valuesStr);
        foreach ($parts as $part) {
            $val = trim($part);
            $val = trim($val, "'\"");
            $values[] = $val;
        }
        return $values;
    }

    /**
     * Split a string on commas, respecting quoted strings.
     */
    private function splitOnCommas(string $str): array
    {
        $parts = [];
        $current = '';
        $inQuote = false;
        $quoteChar = '';

        for ($i = 0; $i < strlen($str); $i++) {
            $char = $str[$i];

            if ($inQuote) {
                if ($char === $quoteChar && ($i === 0 || $str[$i - 1] !== '\\')) {
                    $inQuote = false;
                }
                $current .= $char;
            } else {
                if ($char === '\'' || $char === '"') {
                    $inQuote = true;
                    $quoteChar = $char;
                    $current .= $char;
                } elseif ($char === ',') {
                    $parts[] = $current;
                    $current = '';
                } else {
                    $current .= $char;
                }
            }
        }

        if ($current !== '') {
            $parts[] = $current;
        }

        return $parts;
    }

    /**
     * Parse column definitions from CREATE TABLE SQL (very basic).
     */
    private function parseCreateTableColumns(string $sql): array
    {
        $columns = [];
        if (preg_match('/\((.+)\)/s', $sql, $m)) {
            $defs = $this->splitOnCommas($m[1]);
            foreach ($defs as $def) {
                $def = trim($def);
                if (preg_match('/^`?(\w+)`?\s+(\w+)/i', $def, $cm)) {
                    $name = $cm[1];
                    $type = strtoupper($cm[2]);
                    // Skip constraint keywords
                    if (in_array($type, ['PRIMARY', 'UNIQUE', 'INDEX', 'KEY', 'CONSTRAINT', 'FOREIGN', 'CHECK'])) {
                        continue;
                    }
                    $columns[$name] = $type;
                }
            }
        }
        return $columns;
    }

    /**
     * Get the total number of queries executed.
     */
    public function getQueryCount(): int
    {
        return $this->queryCount;
    }

    /**
     * Get the underlying overlay store.
     */
    public function getOverlay(): LocalOverlayStore
    {
        return $this->overlay;
    }

    /**
     * Get the schema cache.
     */
    public function getSchemaCache(): SchemaCache
    {
        return $this->schemaCache;
    }

    /**
     * Get the remote connection.
     */
    public function getRemote(): RemoteMySQLConnectionInterface
    {
        return $this->remote;
    }
}
