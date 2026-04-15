<?php

declare(strict_types=1);

namespace CowClone\BlockLevel;

/**
 * Reads SQLite B-tree structures directly from page data.
 * Navigates interior and leaf pages, decodes records.
 * Only fetches pages that are actually needed (lazy).
 *
 * SQLite page types:
 *   0x02 = interior index b-tree
 *   0x05 = interior table b-tree
 *   0x0a = leaf index b-tree
 *   0x0d = leaf table b-tree
 */
class BTreeReader
{
    private PageProvider $pages;
    private int $pageSize;

    public function __construct(PageProvider $pages)
    {
        $this->pages = $pages;
        $this->pageSize = $pages->getPageSize();
    }

    /**
     * Read sqlite_master table (always rooted at page 1).
     * Returns array of [type, name, tbl_name, rootpage, sql].
     */
    public function readSqliteMaster(): array
    {
        return $this->scanTable(1);
    }

    /**
     * Read all rows from a table B-tree rooted at the given page.
     * Returns array of rows, each row is an associative array
     * if $columns is provided, otherwise a numeric array.
     *
     * @param int $rootPage 1-based page number
     * @param string[]|null $columns Column names to use as keys
     * @return array[]
     */
    public function scanTable(int $rootPage, ?array $columns = null): array
    {
        $rows = [];
        $this->walkTable($rootPage, function (int $rowid, array $values) use (&$rows, $columns) {
            if ($columns !== null) {
                $row = [];
                foreach ($columns as $i => $col) {
                    $row[$col] = $values[$i] ?? null;
                }
                $row['_rowid'] = $rowid;
                $rows[] = $row;
            } else {
                $rows[] = ['_rowid' => $rowid, '_values' => $values];
            }
        });
        return $rows;
    }

    /**
     * Find a row by rowid in a table B-tree (binary search on interior pages).
     *
     * @param int $rootPage 1-based page number
     * @param int $targetRowid
     * @param string[]|null $columns
     * @return array|null
     */
    public function findRow(int $rootPage, int $targetRowid, ?array $columns = null): ?array
    {
        return $this->searchBTree($rootPage, $targetRowid, $columns);
    }

    /**
     * Get list of page numbers in a table's B-tree (for tracking which pages are needed).
     *
     * @param int $rootPage 1-based page number
     * @return int[] List of 1-based page numbers
     */
    public function getTablePages(int $rootPage): array
    {
        $pages = [];
        $this->collectPages($rootPage, $pages);
        return $pages;
    }

    // ---- Internal B-tree navigation ----

    private function walkTable(int $pageNum, callable $callback): void
    {
        $pageData = $this->pages->readPage($pageNum);
        $headerOffset = ($pageNum === 1) ? 100 : 0;
        $type = ord($pageData[$headerOffset]);

        if ($type === 0x0d) {
            // Leaf table b-tree page
            $this->readLeafTablePage($pageData, $headerOffset, $callback);
        } elseif ($type === 0x05) {
            // Interior table b-tree page
            $this->readInteriorTablePage($pageData, $headerOffset, $pageNum, $callback);
        }
    }

    private function readLeafTablePage(string $pageData, int $headerOffset, callable $callback): void
    {
        $cellCount = unpack('n', substr($pageData, $headerOffset + 3, 2))[1];
        $ptrOffset = $headerOffset + 8;

        for ($i = 0; $i < $cellCount; $i++) {
            $cellOffset = unpack('n', substr($pageData, $ptrOffset + $i * 2, 2))[1];
            [$payloadSize, $bytesRead] = $this->readVarint($pageData, $cellOffset);
            $cellOffset += $bytesRead;
            [$rowid, $bytesRead] = $this->readVarint($pageData, $cellOffset);
            $cellOffset += $bytesRead;

            $payload = $this->readPayload($pageData, $cellOffset, $payloadSize);
            $values = $this->decodeRecord($payload);
            $callback($rowid, $values);
        }
    }

    private function readInteriorTablePage(string $pageData, int $headerOffset, int $pageNum, callable $callback): void
    {
        $cellCount = unpack('n', substr($pageData, $headerOffset + 3, 2))[1];
        $rightChild = unpack('N', substr($pageData, $headerOffset + 8, 4))[1];
        $ptrOffset = $headerOffset + 12;

        for ($i = 0; $i < $cellCount; $i++) {
            $cellOffset = unpack('n', substr($pageData, $ptrOffset + $i * 2, 2))[1];
            $leftChild = unpack('N', substr($pageData, $cellOffset, 4))[1];
            $this->walkTable($leftChild, $callback);
        }

        $this->walkTable($rightChild, $callback);
    }

    private function searchBTree(int $pageNum, int $targetRowid, ?array $columns): ?array
    {
        $pageData = $this->pages->readPage($pageNum);
        $headerOffset = ($pageNum === 1) ? 100 : 0;
        $type = ord($pageData[$headerOffset]);

        if ($type === 0x0d) {
            return $this->searchLeafPage($pageData, $headerOffset, $targetRowid, $columns);
        } elseif ($type === 0x05) {
            return $this->searchInteriorPage($pageData, $headerOffset, $targetRowid, $columns);
        }
        return null;
    }

    private function searchLeafPage(string $pageData, int $headerOffset, int $targetRowid, ?array $columns): ?array
    {
        $cellCount = unpack('n', substr($pageData, $headerOffset + 3, 2))[1];
        $ptrOffset = $headerOffset + 8;

        for ($i = 0; $i < $cellCount; $i++) {
            $cellOffset = unpack('n', substr($pageData, $ptrOffset + $i * 2, 2))[1];
            [$payloadSize, $bytesRead] = $this->readVarint($pageData, $cellOffset);
            $cellOffset += $bytesRead;
            [$rowid, $bytesRead] = $this->readVarint($pageData, $cellOffset);
            $cellOffset += $bytesRead;

            if ($rowid === $targetRowid) {
                $payload = $this->readPayload($pageData, $cellOffset, $payloadSize);
                $values = $this->decodeRecord($payload);
                if ($columns !== null) {
                    $row = [];
                    foreach ($columns as $idx => $col) {
                        $row[$col] = $values[$idx] ?? null;
                    }
                    $row['_rowid'] = $rowid;
                    return $row;
                }
                return ['_rowid' => $rowid, '_values' => $values];
            }
        }
        return null;
    }

    private function searchInteriorPage(string $pageData, int $headerOffset, int $targetRowid, ?array $columns): ?array
    {
        $cellCount = unpack('n', substr($pageData, $headerOffset + 3, 2))[1];
        $rightChild = unpack('N', substr($pageData, $headerOffset + 8, 4))[1];
        $ptrOffset = $headerOffset + 12;

        for ($i = 0; $i < $cellCount; $i++) {
            $cellOffset = unpack('n', substr($pageData, $ptrOffset + $i * 2, 2))[1];
            $leftChild = unpack('N', substr($pageData, $cellOffset, 4))[1];
            [$key, ] = $this->readVarint($pageData, $cellOffset + 4);

            if ($targetRowid <= $key) {
                return $this->searchBTree($leftChild, $targetRowid, $columns);
            }
        }

        return $this->searchBTree($rightChild, $targetRowid, $columns);
    }

    private function collectPages(int $pageNum, array &$collected): void
    {
        $collected[] = $pageNum;
        $pageData = $this->pages->readPage($pageNum);
        $headerOffset = ($pageNum === 1) ? 100 : 0;
        $type = ord($pageData[$headerOffset]);

        if ($type === 0x05) {
            $cellCount = unpack('n', substr($pageData, $headerOffset + 3, 2))[1];
            $rightChild = unpack('N', substr($pageData, $headerOffset + 8, 4))[1];
            $ptrOffset = $headerOffset + 12;

            for ($i = 0; $i < $cellCount; $i++) {
                $cellOffset = unpack('n', substr($pageData, $ptrOffset + $i * 2, 2))[1];
                $leftChild = unpack('N', substr($pageData, $cellOffset, 4))[1];
                $this->collectPages($leftChild, $collected);
            }
            $this->collectPages($rightChild, $collected);
        }
    }

    // ---- Payload reading (handles inline only, no overflow for simplicity) ----

    private function readPayload(string $pageData, int $offset, int $payloadSize): string
    {
        $usableSize = $this->pageSize - 0; // reserved = 0 for standard SQLite
        $maxInline = $usableSize - 35;

        if ($payloadSize <= $maxInline) {
            return substr($pageData, $offset, $payloadSize);
        }

        // Overflow: read inline portion then follow overflow chain
        $minLocal = $maxInline; // simplified
        $m = (($usableSize - 12) * 32 / 255) - 23;
        $k = (int)$m + (($payloadSize - (int)$m) % ($usableSize - 4));
        $localSize = ($k <= $maxInline) ? $k : (int)$m;

        $payload = substr($pageData, $offset, $localSize);
        $remaining = $payloadSize - $localSize;
        $overflowPageNum = unpack('N', substr($pageData, $offset + $localSize, 4))[1];

        while ($remaining > 0 && $overflowPageNum !== 0) {
            $overflowData = $this->pages->readPage($overflowPageNum);
            $nextPage = unpack('N', substr($overflowData, 0, 4))[1];
            $availableBytes = $this->pageSize - 4;
            $bytesToRead = min($remaining, $availableBytes);
            $payload .= substr($overflowData, 4, $bytesToRead);
            $remaining -= $bytesToRead;
            $overflowPageNum = $nextPage;
        }

        return $payload;
    }

    // ---- Record decoding ----

    /**
     * Decode a SQLite record (as stored in a B-tree cell payload).
     * @return array Column values
     */
    private function decodeRecord(string $payload): array
    {
        $offset = 0;
        [$headerSize, $bytesRead] = $this->readVarint($payload, $offset);
        $offset += $bytesRead;

        $serialTypes = [];
        $headerEnd = $headerSize;
        while ($offset < $headerEnd) {
            [$st, $bytesRead] = $this->readVarint($payload, $offset);
            $serialTypes[] = $st;
            $offset += $bytesRead;
        }

        $offset = $headerSize;
        $values = [];
        foreach ($serialTypes as $st) {
            [$value, $size] = $this->decodeValue($payload, $offset, $st);
            $values[] = $value;
            $offset += $size;
        }

        return $values;
    }

    private function decodeValue(string $data, int $offset, int $serialType): array
    {
        switch ($serialType) {
            case 0: return [null, 0];
            case 1: return [$this->readSignedBE($data, $offset, 1), 1];
            case 2: return [$this->readSignedBE($data, $offset, 2), 2];
            case 3: return [$this->readSignedBE($data, $offset, 3), 3];
            case 4: return [$this->readSignedBE($data, $offset, 4), 4];
            case 5: return [$this->readSignedBE($data, $offset, 6), 6];
            case 6: return [$this->readSignedBE($data, $offset, 8), 8];
            case 7:
                $raw = substr($data, $offset, 8);
                return [unpack('E', $raw)[1], 8]; // big-endian double
            case 8: return [0, 0];
            case 9: return [1, 0];
            default:
                if ($serialType >= 12 && $serialType % 2 === 0) {
                    $len = ($serialType - 12) / 2;
                    return [substr($data, $offset, $len), $len];
                }
                if ($serialType >= 13 && $serialType % 2 === 1) {
                    $len = ($serialType - 13) / 2;
                    return [substr($data, $offset, $len), $len];
                }
                return [null, 0];
        }
    }

    private function readSignedBE(string $data, int $offset, int $bytes): int
    {
        $val = 0;
        for ($i = 0; $i < $bytes; $i++) {
            $val = ($val << 8) | ord($data[$offset + $i]);
        }
        // Sign extend
        $signBit = 1 << ($bytes * 8 - 1);
        if ($val & $signBit) {
            $val -= (1 << ($bytes * 8));
        }
        return $val;
    }

    // ---- Varint ----

    /**
     * Read a SQLite varint starting at $offset.
     * Returns [$value, $bytesConsumed].
     */
    private function readVarint(string $data, int $offset): array
    {
        $value = 0;
        for ($i = 0; $i < 8; $i++) {
            $byte = ord($data[$offset + $i]);
            $value = ($value << 7) | ($byte & 0x7f);
            if (($byte & 0x80) === 0) {
                return [$value, $i + 1];
            }
        }
        // 9th byte uses all 8 bits
        $byte = ord($data[$offset + 8]);
        $value = ($value << 8) | $byte;
        return [$value, 9];
    }
}
