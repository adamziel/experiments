<?php

namespace CowClone;

/**
 * Translates MySQL CREATE TABLE statements to SQLite-compatible DDL.
 */
class SchemaTranslator
{
    /**
     * MySQL-to-SQLite type mappings.
     */
    private const TYPE_MAP = [
        'BIGINT'      => 'INTEGER',
        'MEDIUMINT'   => 'INTEGER',
        'SMALLINT'    => 'INTEGER',
        'TINYINT'     => 'INTEGER',
        'INT'         => 'INTEGER',
        'INTEGER'     => 'INTEGER',
        'FLOAT'       => 'REAL',
        'DOUBLE'      => 'REAL',
        'DECIMAL'     => 'REAL',
        'NUMERIC'     => 'REAL',
        'VARCHAR'     => 'TEXT',
        'CHAR'        => 'TEXT',
        'TINYTEXT'    => 'TEXT',
        'TEXT'        => 'TEXT',
        'MEDIUMTEXT'  => 'TEXT',
        'LONGTEXT'    => 'TEXT',
        'ENUM'        => 'TEXT',
        'SET'         => 'TEXT',
        'DATE'        => 'TEXT',
        'DATETIME'    => 'TEXT',
        'TIMESTAMP'   => 'TEXT',
        'TIME'        => 'TEXT',
        'YEAR'        => 'INTEGER',
        'TINYBLOB'    => 'BLOB',
        'BLOB'        => 'BLOB',
        'MEDIUMBLOB'  => 'BLOB',
        'LONGBLOB'    => 'BLOB',
        'BINARY'      => 'BLOB',
        'VARBINARY'   => 'BLOB',
        'BOOLEAN'     => 'INTEGER',
        'BOOL'        => 'INTEGER',
        'JSON'        => 'TEXT',
    ];

    /**
     * Translate a MySQL CREATE TABLE statement to SQLite.
     */
    public function translateCreateTable(string $mysqlDDL): string
    {
        // Normalize whitespace
        $ddl = trim($mysqlDDL);
        $ddl = preg_replace('/\s+/', ' ', $ddl);

        // Extract table name
        if (!preg_match('/CREATE\s+TABLE\s+(?:IF\s+NOT\s+EXISTS\s+)?[`"]?(\w+)[`"]?\s*\(/i', $ddl, $m)) {
            throw new \InvalidArgumentException("Cannot parse CREATE TABLE statement");
        }
        $tableName = $m[1];

        // Extract the body between the outer parentheses
        $body = $this->extractBody($ddl);

        // Split body into column/constraint definitions
        $definitions = $this->splitDefinitions($body);

        $columns = [];
        $constraints = [];
        $primaryKeyColumns = [];
        $autoIncrementColumn = null;

        foreach ($definitions as $def) {
            $def = trim($def);
            if ($def === '') continue;

            // Check if this is a constraint (PRIMARY KEY, UNIQUE, INDEX, KEY, FULLTEXT, SPATIAL, CONSTRAINT, CHECK)
            if ($this->isConstraint($def)) {
                $constraint = $this->translateConstraint($def, $tableName);
                if ($constraint !== null) {
                    // Check if it's a PRIMARY KEY constraint — extract columns
                    if (preg_match('/^\s*PRIMARY\s+KEY/i', $def)) {
                        if (preg_match('/\(([^)]+)\)/i', $def, $pkm)) {
                            $primaryKeyColumns = array_map(function ($c) {
                                return trim(preg_replace('/[`"\s]/', '', preg_replace('/\(\d+\)/', '', $c)));
                            }, explode(',', $pkm[1]));
                        }
                    }
                    if ($constraint !== '') {
                        $constraints[] = $constraint;
                    }
                }
                continue;
            }

            // Parse column definition
            $colResult = $this->translateColumn($def);
            if ($colResult !== null) {
                $columns[] = $colResult;
                if ($colResult['auto_increment']) {
                    $autoIncrementColumn = $colResult['name'];
                }
            }
        }

        // Build the SQLite CREATE TABLE
        $sqliteCols = [];
        foreach ($columns as $col) {
            $colDef = '"' . $col['name'] . '" ' . $col['type'];

            // If this column is the auto_increment column and is the sole primary key
            if ($col['auto_increment'] && (
                count($primaryKeyColumns) === 0 ||
                (count($primaryKeyColumns) === 1 && strtolower($primaryKeyColumns[0]) === strtolower($col['name']))
            )) {
                $colDef .= ' PRIMARY KEY AUTOINCREMENT';
                // Remove PRIMARY KEY from constraints since it's inline
                $constraints = array_filter($constraints, function ($c) {
                    return !preg_match('/^\s*PRIMARY\s+KEY/i', $c);
                });
                $primaryKeyColumns = []; // Already handled
            } else {
                if ($col['not_null']) {
                    $colDef .= ' NOT NULL';
                }
                if ($col['default'] !== null) {
                    $colDef .= ' DEFAULT ' . $col['default'];
                }
            }

            $sqliteCols[] = $colDef;
        }

        // Add primary key constraint if not handled inline
        if (!empty($primaryKeyColumns) && $autoIncrementColumn === null) {
            $pkCols = implode(', ', array_map(function ($c) { return '"' . $c . '"'; }, $primaryKeyColumns));
            $sqliteCols[] = 'PRIMARY KEY (' . $pkCols . ')';
        }

        // Add UNIQUE constraints inline
        foreach ($constraints as $c) {
            if (preg_match('/^UNIQUE/i', trim($c))) {
                // keep it
                $sqliteCols[] = $c;
            }
        }

        $result = 'CREATE TABLE IF NOT EXISTS "' . $tableName . '" (' . "\n";
        $result .= '  ' . implode(",\n  ", $sqliteCols) . "\n";
        $result .= ')';

        // Collect CREATE INDEX statements for non-unique, non-primary indexes
        $indexStatements = [];
        foreach ($constraints as $c) {
            if (preg_match('/^CREATE\s+(UNIQUE\s+)?INDEX/i', $c)) {
                $indexStatements[] = $c;
            }
        }

        if (!empty($indexStatements)) {
            $result .= ";\n" . implode(";\n", $indexStatements);
        }

        return $result;
    }

    /**
     * Extract the body content between the outermost parentheses.
     */
    private function extractBody(string $ddl): string
    {
        $start = strpos($ddl, '(');
        if ($start === false) {
            throw new \InvalidArgumentException("No opening parenthesis found");
        }

        $depth = 0;
        $end = $start;
        for ($i = $start; $i < strlen($ddl); $i++) {
            if ($ddl[$i] === '(') $depth++;
            if ($ddl[$i] === ')') $depth--;
            if ($depth === 0) {
                $end = $i;
                break;
            }
        }

        return substr($ddl, $start + 1, $end - $start - 1);
    }

    /**
     * Split definitions by commas, respecting parentheses depth.
     */
    private function splitDefinitions(string $body): array
    {
        $parts = [];
        $current = '';
        $depth = 0;

        for ($i = 0; $i < strlen($body); $i++) {
            $ch = $body[$i];
            if ($ch === '(') $depth++;
            if ($ch === ')') $depth--;
            if ($ch === ',' && $depth === 0) {
                $parts[] = trim($current);
                $current = '';
                continue;
            }
            $current .= $ch;
        }
        if (trim($current) !== '') {
            $parts[] = trim($current);
        }

        return $parts;
    }

    /**
     * Check if a definition is a constraint rather than a column.
     */
    private function isConstraint(string $def): bool
    {
        $upper = strtoupper(trim($def));
        return preg_match('/^(PRIMARY\s+KEY|UNIQUE(\s+KEY|\s+INDEX)?|INDEX|KEY|FULLTEXT|SPATIAL|CONSTRAINT|CHECK)\b/i', $upper) === 1;
    }

    /**
     * Translate a MySQL constraint to SQLite.
     */
    private function translateConstraint(string $def, string $tableName): ?string
    {
        $trimmed = trim($def);

        // PRIMARY KEY (cols)
        if (preg_match('/^PRIMARY\s+KEY\s*\(([^)]+)\)/i', $trimmed, $m)) {
            $cols = $this->cleanColumnList($m[1]);
            return 'PRIMARY KEY (' . $cols . ')';
        }

        // UNIQUE KEY/INDEX name (cols)
        if (preg_match('/^UNIQUE\s+(?:KEY|INDEX)\s+[`"]?(\w+)[`"]?\s*\(([^)]+)\)/i', $trimmed, $m)) {
            $cols = $this->cleanColumnList($m[2]);
            return 'UNIQUE (' . $cols . ')';
        }

        // UNIQUE (cols) — no name
        if (preg_match('/^UNIQUE\s*\(([^)]+)\)/i', $trimmed, $m)) {
            $cols = $this->cleanColumnList($m[1]);
            return 'UNIQUE (' . $cols . ')';
        }

        // INDEX/KEY name (cols) — convert to CREATE INDEX
        if (preg_match('/^(?:INDEX|KEY)\s+[`"]?(\w+)[`"]?\s*\(([^)]+)\)/i', $trimmed, $m)) {
            $indexName = $m[1];
            $cols = $this->cleanColumnList($m[2]);
            return 'CREATE INDEX IF NOT EXISTS "idx_' . $tableName . '_' . $indexName . '" ON "' . $tableName . '" (' . $cols . ')';
        }

        // FULLTEXT KEY/INDEX — skip (not supported in SQLite)
        if (preg_match('/^FULLTEXT/i', $trimmed)) {
            return '';
        }

        // CONSTRAINT ... — skip complex constraints
        if (preg_match('/^CONSTRAINT/i', $trimmed)) {
            return '';
        }

        // CHECK — skip
        if (preg_match('/^CHECK/i', $trimmed)) {
            return '';
        }

        return '';
    }

    /**
     * Clean a column list: remove backticks, optional length specs like (191).
     */
    private function cleanColumnList(string $cols): string
    {
        $parts = explode(',', $cols);
        $cleaned = [];
        foreach ($parts as $part) {
            $part = trim($part);
            $part = preg_replace('/[`"]/', '', $part);
            $part = preg_replace('/\(\d+\)/', '', $part); // remove prefix lengths
            $part = trim($part);
            $cleaned[] = '"' . $part . '"';
        }
        return implode(', ', $cleaned);
    }

    /**
     * Translate a MySQL column definition to SQLite.
     * Returns an associative array with column info.
     */
    private function translateColumn(string $def): ?array
    {
        // Match column name and type
        if (!preg_match('/^[`"]?(\w+)[`"]?\s+(.+)$/is', trim($def), $m)) {
            return null;
        }

        $name = $m[1];
        $rest = trim($m[2]);

        // Extract the MySQL type
        $sqliteType = 'TEXT';
        $autoIncrement = false;
        $notNull = false;
        $default = null;

        // Match the type (possibly with params like VARCHAR(255) or DECIMAL(10,2) or ENUM(...))
        if (preg_match('/^(ENUM|SET)\s*\([^)]*\)/i', $rest, $tm)) {
            $sqliteType = 'TEXT';
            $rest = trim(substr($rest, strlen($tm[0])));
        } elseif (preg_match('/^(\w+)\s*(?:\(([^)]*)\))?/i', $rest, $tm)) {
            $mysqlType = strtoupper($tm[1]);
            if (isset(self::TYPE_MAP[$mysqlType])) {
                $sqliteType = self::TYPE_MAP[$mysqlType];
            }
            $rest = trim(substr($rest, strlen($tm[0])));
        }

        // Check for UNSIGNED (ignore for SQLite)
        $rest = preg_replace('/\bUNSIGNED\b/i', '', $rest);
        $rest = preg_replace('/\bSIGNED\b/i', '', $rest);

        // Check for CHARACTER SET / COLLATE (ignore)
        $rest = preg_replace('/\bCHARACTER\s+SET\s+\w+/i', '', $rest);
        $rest = preg_replace('/\bCOLLATE\s+\w+/i', '', $rest);

        // Check for NOT NULL
        if (preg_match('/\bNOT\s+NULL\b/i', $rest)) {
            $notNull = true;
            $rest = preg_replace('/\bNOT\s+NULL\b/i', '', $rest);
        }

        // Check for NULL (explicit)
        $rest = preg_replace('/\bNULL\b/i', '', $rest);

        // Check for AUTO_INCREMENT
        if (preg_match('/\bAUTO_INCREMENT\b/i', $rest)) {
            $autoIncrement = true;
            $rest = preg_replace('/\bAUTO_INCREMENT\b/i', '', $rest);
        }

        // Check for DEFAULT value
        if (preg_match('/\bDEFAULT\s+(\'[^\']*\'|"[^"]*"|\d+[\d.]*|NULL|CURRENT_TIMESTAMP|TRUE|FALSE)/i', $rest, $dm)) {
            $default = $dm[1];
            // Map CURRENT_TIMESTAMP for SQLite
            if (strtoupper($default) === 'CURRENT_TIMESTAMP') {
                $default = "CURRENT_TIMESTAMP";
            }
        }

        // Check for ON UPDATE (ignore for SQLite)
        // Check for COMMENT (ignore for SQLite)

        return [
            'name'           => $name,
            'type'           => $sqliteType,
            'not_null'       => $notNull,
            'default'        => $default,
            'auto_increment' => $autoIncrement,
        ];
    }
}
