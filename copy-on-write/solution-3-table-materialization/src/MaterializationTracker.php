<?php

namespace CowClone;

/**
 * Tracks which tables have been materialized in the local SQLite database.
 */
class MaterializationTracker
{
    private \PDO $db;

    public function __construct(\PDO $db)
    {
        $this->db = $db;
        $this->initialize();
    }

    /**
     * Create the tracking table if it doesn't exist.
     */
    private function initialize(): void
    {
        $this->db->exec('
            CREATE TABLE IF NOT EXISTS "_cow_materialized" (
                "table_name" TEXT PRIMARY KEY,
                "row_count" INTEGER NOT NULL DEFAULT 0,
                "materialized_at" TEXT NOT NULL DEFAULT (datetime(\'now\'))
            )
        ');
    }

    /**
     * Mark a table as materialized.
     */
    public function markMaterialized(string $tableName, int $rowCount): void
    {
        $stmt = $this->db->prepare('
            INSERT OR REPLACE INTO "_cow_materialized" ("table_name", "row_count", "materialized_at")
            VALUES (:name, :count, datetime(\'now\'))
        ');
        $stmt->execute([':name' => $tableName, ':count' => $rowCount]);
    }

    /**
     * Check if a table has been materialized.
     */
    public function isMaterialized(string $tableName): bool
    {
        $stmt = $this->db->prepare('SELECT COUNT(*) FROM "_cow_materialized" WHERE "table_name" = :name');
        $stmt->execute([':name' => $tableName]);
        return (int) $stmt->fetchColumn() > 0;
    }

    /**
     * Get all materialized tables with their metadata.
     * @return array<int, array{table_name: string, row_count: int, materialized_at: string}>
     */
    public function getMaterializedTables(): array
    {
        $stmt = $this->db->query('SELECT "table_name", "row_count", "materialized_at" FROM "_cow_materialized" ORDER BY "table_name"');
        return $stmt->fetchAll(\PDO::FETCH_ASSOC);
    }
}
