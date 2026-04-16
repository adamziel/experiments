<?php

namespace CowClone;

/**
 * Handles fetching a table from the remote MySQL and importing it into local SQLite.
 */
class TableMaterializer
{
    private RemoteTableClient $remote;
    private \PDO $db;
    private SchemaTranslator $translator;
    private MaterializationTracker $tracker;

    public function __construct(
        RemoteTableClient $remote,
        \PDO $db,
        SchemaTranslator $translator,
        MaterializationTracker $tracker
    ) {
        $this->remote = $remote;
        $this->db = $db;
        $this->translator = $translator;
        $this->tracker = $tracker;
    }

    /**
     * Materialize a single table: fetch schema and data from remote,
     * create local SQLite table, import all rows.
     *
     * @return int Number of rows imported
     */
    public function materializeTable(string $tableName): int
    {
        if ($this->tracker->isMaterialized($tableName)) {
            return 0; // Already materialized
        }

        // Get MySQL schema from remote
        $mysqlSchema = $this->remote->getTableSchema($tableName);

        // Translate to SQLite
        $sqliteSchema = $this->translator->translateCreateTable($mysqlSchema);

        // Execute schema creation (may contain multiple statements separated by ;)
        $statements = array_filter(array_map('trim', explode(';', $sqliteSchema)));
        foreach ($statements as $stmt) {
            if ($stmt !== '') {
                $this->db->exec($stmt);
            }
        }

        // Fetch rows from remote
        $rows = $this->remote->getTableRows($tableName);

        if (empty($rows)) {
            $this->tracker->markMaterialized($tableName, 0);
            return 0;
        }

        // Batch insert all rows in a transaction
        $this->db->beginTransaction();
        try {
            $columns = array_keys($rows[0]);
            $placeholders = implode(', ', array_fill(0, count($columns), '?'));
            $quotedCols = implode(', ', array_map(function ($c) { return '"' . $c . '"'; }, $columns));

            $insertSql = 'INSERT INTO "' . $tableName . '" (' . $quotedCols . ') VALUES (' . $placeholders . ')';
            $stmt = $this->db->prepare($insertSql);

            foreach ($rows as $row) {
                $values = [];
                foreach ($columns as $col) {
                    $values[] = $row[$col] ?? null;
                }
                $stmt->execute($values);
            }

            $this->db->commit();
        } catch (\Exception $e) {
            $this->db->rollBack();
            throw $e;
        }

        $rowCount = count($rows);
        $this->tracker->markMaterialized($tableName, $rowCount);

        return $rowCount;
    }
}
