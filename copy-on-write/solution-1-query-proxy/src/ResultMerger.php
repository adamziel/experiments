<?php

namespace CowClone;

/**
 * Merges remote query results with local overlay state.
 *
 * For simple SELECT queries:
 * 1. Filters out rows deleted locally
 * 2. Replaces rows updated locally with local versions
 * 3. Appends rows inserted locally
 *
 * Known limitation: Complex aggregates (COUNT, SUM, etc.) and JOINs
 * across remote + local data are not fully supported. The merger works
 * at the row level for single-table queries.
 */
class ResultMerger
{
    /**
     * Merge remote results with local overlay changes.
     *
     * @param string $table The table being queried
     * @param array $remoteResults Rows from the remote database
     * @param LocalOverlayStore $overlay The local overlay store
     * @param string $pkColumn Primary key column name
     * @param array|null $whereFilter Optional simple WHERE conditions to filter local inserts
     * @return array Merged result set
     */
    public function merge(
        string $table,
        array $remoteResults,
        LocalOverlayStore $overlay,
        string $pkColumn = 'id',
        ?array $whereFilter = null
    ): array {
        $merged = [];

        // Step 1 & 2: Process remote results - filter deletes, apply updates
        $deletedKeys = $overlay->getDeletedKeys($table);
        $updatedRows = $overlay->getUpdatedRows($table);

        foreach ($remoteResults as $row) {
            $rowKey = isset($row[$pkColumn]) ? (string)$row[$pkColumn] : null;

            // Skip deleted rows
            if ($rowKey !== null && in_array($rowKey, $deletedKeys, true)) {
                continue;
            }

            // Apply updates if this row was modified locally
            if ($rowKey !== null && isset($updatedRows[$rowKey])) {
                $row = array_merge($row, $updatedRows[$rowKey]);
            }

            $merged[] = $row;
        }

        // Step 3: Append locally inserted rows
        $insertedRows = $overlay->getInsertedRows($table);
        foreach ($insertedRows as $insertedRow) {
            // Apply where filter if provided
            if ($whereFilter !== null) {
                $matches = true;
                foreach ($whereFilter as $col => $val) {
                    if (!isset($insertedRow[$col]) || (string)$insertedRow[$col] !== (string)$val) {
                        $matches = false;
                        break;
                    }
                }
                if (!$matches) {
                    continue;
                }
            }
            $merged[] = $insertedRow;
        }

        return $merged;
    }
}
