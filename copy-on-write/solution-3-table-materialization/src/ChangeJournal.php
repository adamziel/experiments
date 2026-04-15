<?php

namespace CowClone;

/**
 * Write-ahead log recording all mutations after table materialization.
 */
class ChangeJournal
{
    private \PDO $db;

    public function __construct(\PDO $db)
    {
        $this->db = $db;
        $this->initialize();
    }

    /**
     * Create the journal table if it doesn't exist.
     */
    private function initialize(): void
    {
        $this->db->exec('
            CREATE TABLE IF NOT EXISTS "_cow_journal" (
                "id" INTEGER PRIMARY KEY AUTOINCREMENT,
                "timestamp" TEXT NOT NULL DEFAULT (datetime(\'now\')),
                "operation" TEXT NOT NULL,
                "table_name" TEXT NOT NULL,
                "row_data_json" TEXT,
                "old_data_json" TEXT
            )
        ');
    }

    /**
     * Record an INSERT operation.
     *
     * @param string $table
     * @param array<string, mixed> $newData
     */
    public function recordInsert(string $table, array $newData): void
    {
        $stmt = $this->db->prepare('
            INSERT INTO "_cow_journal" ("timestamp", "operation", "table_name", "row_data_json", "old_data_json")
            VALUES (datetime(\'now\'), \'INSERT\', :table, :row_data, NULL)
        ');
        $stmt->execute([
            ':table'    => $table,
            ':row_data' => json_encode($newData),
        ]);
    }

    /**
     * Record an UPDATE operation.
     *
     * @param string $table
     * @param array<string, mixed> $oldData
     * @param array<string, mixed> $newData
     */
    public function recordUpdate(string $table, array $oldData, array $newData): void
    {
        $stmt = $this->db->prepare('
            INSERT INTO "_cow_journal" ("timestamp", "operation", "table_name", "row_data_json", "old_data_json")
            VALUES (datetime(\'now\'), \'UPDATE\', :table, :row_data, :old_data)
        ');
        $stmt->execute([
            ':table'    => $table,
            ':row_data' => json_encode($newData),
            ':old_data' => json_encode($oldData),
        ]);
    }

    /**
     * Record a DELETE operation.
     *
     * @param string $table
     * @param array<string, mixed> $oldData
     */
    public function recordDelete(string $table, array $oldData): void
    {
        $stmt = $this->db->prepare('
            INSERT INTO "_cow_journal" ("timestamp", "operation", "table_name", "row_data_json", "old_data_json")
            VALUES (datetime(\'now\'), \'DELETE\', :table, NULL, :old_data)
        ');
        $stmt->execute([
            ':table'    => $table,
            ':old_data' => json_encode($oldData),
        ]);
    }

    /**
     * Get journal entries, optionally filtered by table name.
     *
     * @param string|null $table
     * @return array<int, array{id: int, timestamp: string, operation: string, table_name: string, row_data_json: ?string, old_data_json: ?string}>
     */
    public function getJournalEntries(?string $table = null): array
    {
        if ($table !== null) {
            $stmt = $this->db->prepare('
                SELECT * FROM "_cow_journal" WHERE "table_name" = :table ORDER BY "id" ASC
            ');
            $stmt->execute([':table' => $table]);
        } else {
            $stmt = $this->db->query('SELECT * FROM "_cow_journal" ORDER BY "id" ASC');
        }
        return $stmt->fetchAll(\PDO::FETCH_ASSOC);
    }

    /**
     * Get all changes since a given timestamp.
     *
     * @param string $timestamp ISO 8601 datetime string
     * @return array<int, array>
     */
    public function getChangesSince(string $timestamp): array
    {
        $stmt = $this->db->prepare('
            SELECT * FROM "_cow_journal" WHERE "timestamp" >= :ts ORDER BY "id" ASC
        ');
        $stmt->execute([':ts' => $timestamp]);
        return $stmt->fetchAll(\PDO::FETCH_ASSOC);
    }
}
