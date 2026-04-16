<?php

namespace CowClone;

class QueryClassifier
{
    const TYPE_READ = 'READ';
    const TYPE_WRITE = 'WRITE';
    const TYPE_DDL = 'DDL';
    const TYPE_OTHER = 'OTHER';

    const QUERY_SELECT = 'SELECT';
    const QUERY_INSERT = 'INSERT';
    const QUERY_UPDATE = 'UPDATE';
    const QUERY_DELETE = 'DELETE';
    const QUERY_CREATE_TABLE = 'CREATE_TABLE';
    const QUERY_ALTER_TABLE = 'ALTER_TABLE';
    const QUERY_DROP_TABLE = 'DROP_TABLE';
    const QUERY_SHOW = 'SHOW';
    const QUERY_DESCRIBE = 'DESCRIBE';
    const QUERY_SET = 'SET';
    const QUERY_REPLACE = 'REPLACE';
    const QUERY_TRUNCATE = 'TRUNCATE';
    const QUERY_UNKNOWN = 'UNKNOWN';

    /**
     * Classify a SQL query into READ, WRITE, DDL, or OTHER.
     *
     * @param string $sql
     * @return string One of TYPE_READ, TYPE_WRITE, TYPE_DDL, TYPE_OTHER
     */
    public function classify(string $sql): string
    {
        $queryType = $this->getQueryType($sql);

        switch ($queryType) {
            case self::QUERY_SELECT:
            case self::QUERY_SHOW:
            case self::QUERY_DESCRIBE:
                return self::TYPE_READ;

            case self::QUERY_INSERT:
            case self::QUERY_UPDATE:
            case self::QUERY_DELETE:
            case self::QUERY_REPLACE:
            case self::QUERY_TRUNCATE:
                return self::TYPE_WRITE;

            case self::QUERY_CREATE_TABLE:
            case self::QUERY_ALTER_TABLE:
            case self::QUERY_DROP_TABLE:
                return self::TYPE_DDL;

            case self::QUERY_SET:
            default:
                return self::TYPE_OTHER;
        }
    }

    /**
     * Determine the specific query type.
     *
     * @param string $sql
     * @return string One of the QUERY_* constants
     */
    public function getQueryType(string $sql): string
    {
        $normalized = $this->normalize($sql);

        if (preg_match('/^SELECT\b/i', $normalized)) {
            return self::QUERY_SELECT;
        }
        if (preg_match('/^INSERT\b/i', $normalized)) {
            return self::QUERY_INSERT;
        }
        if (preg_match('/^UPDATE\b/i', $normalized)) {
            return self::QUERY_UPDATE;
        }
        if (preg_match('/^DELETE\b/i', $normalized)) {
            return self::QUERY_DELETE;
        }
        if (preg_match('/^CREATE\s+TABLE\b/i', $normalized)) {
            return self::QUERY_CREATE_TABLE;
        }
        if (preg_match('/^ALTER\s+TABLE\b/i', $normalized)) {
            return self::QUERY_ALTER_TABLE;
        }
        if (preg_match('/^DROP\s+TABLE\b/i', $normalized)) {
            return self::QUERY_DROP_TABLE;
        }
        if (preg_match('/^SHOW\b/i', $normalized)) {
            return self::QUERY_SHOW;
        }
        if (preg_match('/^(DESCRIBE|DESC|EXPLAIN)\b/i', $normalized)) {
            return self::QUERY_DESCRIBE;
        }
        if (preg_match('/^SET\b/i', $normalized)) {
            return self::QUERY_SET;
        }
        if (preg_match('/^REPLACE\b/i', $normalized)) {
            return self::QUERY_REPLACE;
        }
        if (preg_match('/^TRUNCATE\b/i', $normalized)) {
            return self::QUERY_TRUNCATE;
        }

        return self::QUERY_UNKNOWN;
    }

    /**
     * Extract the primary table name from a SQL query.
     *
     * @param string $sql
     * @return string|null Table name or null if not determinable
     */
    public function extractTableName(string $sql): ?string
    {
        $normalized = $this->normalize($sql);

        // SELECT ... FROM table
        if (preg_match('/^SELECT\b.*?\bFROM\s+`?(\w+)`?/i', $normalized, $m)) {
            return $m[1];
        }

        // INSERT INTO table
        if (preg_match('/^INSERT\s+(?:INTO\s+)?`?(\w+)`?/i', $normalized, $m)) {
            return $m[1];
        }

        // UPDATE table
        if (preg_match('/^UPDATE\s+`?(\w+)`?/i', $normalized, $m)) {
            return $m[1];
        }

        // DELETE FROM table
        if (preg_match('/^DELETE\s+FROM\s+`?(\w+)`?/i', $normalized, $m)) {
            return $m[1];
        }

        // CREATE TABLE table
        if (preg_match('/^CREATE\s+TABLE\s+(?:IF\s+NOT\s+EXISTS\s+)?`?(\w+)`?/i', $normalized, $m)) {
            return $m[1];
        }

        // ALTER TABLE table
        if (preg_match('/^ALTER\s+TABLE\s+`?(\w+)`?/i', $normalized, $m)) {
            return $m[1];
        }

        // DROP TABLE table
        if (preg_match('/^DROP\s+TABLE\s+(?:IF\s+EXISTS\s+)?`?(\w+)`?/i', $normalized, $m)) {
            return $m[1];
        }

        // REPLACE INTO table
        if (preg_match('/^REPLACE\s+(?:INTO\s+)?`?(\w+)`?/i', $normalized, $m)) {
            return $m[1];
        }

        // TRUNCATE TABLE table
        if (preg_match('/^TRUNCATE\s+(?:TABLE\s+)?`?(\w+)`?/i', $normalized, $m)) {
            return $m[1];
        }

        // DESCRIBE table
        if (preg_match('/^(?:DESCRIBE|DESC|EXPLAIN)\s+`?(\w+)`?/i', $normalized, $m)) {
            return $m[1];
        }

        return null;
    }

    /**
     * Extract all table names from a query (for JOINs, subqueries, etc.)
     *
     * @param string $sql
     * @return array
     */
    public function extractAllTableNames(string $sql): array
    {
        $tables = [];
        $normalized = $this->normalize($sql);

        // FROM table
        if (preg_match_all('/\bFROM\s+`?(\w+)`?/i', $normalized, $matches)) {
            $tables = array_merge($tables, $matches[1]);
        }

        // JOIN table
        if (preg_match_all('/\bJOIN\s+`?(\w+)`?/i', $normalized, $matches)) {
            $tables = array_merge($tables, $matches[1]);
        }

        // INSERT INTO table
        if (preg_match('/^INSERT\s+(?:INTO\s+)?`?(\w+)`?/i', $normalized, $m)) {
            $tables[] = $m[1];
        }

        // UPDATE table
        if (preg_match('/^UPDATE\s+`?(\w+)`?/i', $normalized, $m)) {
            $tables[] = $m[1];
        }

        return array_unique($tables);
    }

    /**
     * Normalize SQL by trimming and collapsing whitespace.
     */
    private function normalize(string $sql): string
    {
        return preg_replace('/\s+/', ' ', trim($sql));
    }
}
