<?php

declare(strict_types=1);

namespace CowClone\BlockLevel;

/**
 * Reads pages from a SQLite file on disk.
 * In production, this would be replaced with an HTTP range-request backend.
 * Tracks which pages have been accessed for verifying lazy loading.
 */
class FilePageProvider implements PageProvider
{
    private string $filePath;
    private int $pageSize;
    private int $pageCount;
    /** @var array<int, true> Pages that have been read */
    private array $accessedPages = [];

    public function __construct(string $filePath)
    {
        if (!file_exists($filePath)) {
            throw new \RuntimeException("File not found: {$filePath}");
        }
        $this->filePath = $filePath;

        $header = file_get_contents($filePath, false, null, 0, 100);
        if ($header === false || strlen($header) < 100) {
            throw new \RuntimeException("Cannot read SQLite header");
        }

        $pageSizeRaw = unpack('n', substr($header, 16, 2))[1];
        $this->pageSize = $pageSizeRaw === 1 ? 65536 : $pageSizeRaw;

        $dbSizeInPages = unpack('N', substr($header, 28, 4))[1];
        if ($dbSizeInPages === 0) {
            $fileSize = filesize($filePath);
            $dbSizeInPages = (int) ceil($fileSize / $this->pageSize);
        }
        $this->pageCount = $dbSizeInPages;
    }

    public function readPage(int $pageNumber): string
    {
        if ($pageNumber < 1 || $pageNumber > $this->pageCount) {
            throw new \OutOfRangeException("Page {$pageNumber} out of range [1, {$this->pageCount}]");
        }

        $this->accessedPages[$pageNumber] = true;

        $offset = ($pageNumber - 1) * $this->pageSize;
        $data = file_get_contents($this->filePath, false, null, $offset, $this->pageSize);
        if ($data === false) {
            throw new \RuntimeException("Failed to read page {$pageNumber}");
        }
        if (strlen($data) < $this->pageSize) {
            $data = str_pad($data, $this->pageSize, "\0");
        }
        return $data;
    }

    public function getPageSize(): int
    {
        return $this->pageSize;
    }

    public function getPageCount(): int
    {
        return $this->pageCount;
    }

    /** @return int[] List of 1-based page numbers that were read */
    public function getAccessedPages(): array
    {
        return array_keys($this->accessedPages);
    }

    public function getAccessedPageCount(): int
    {
        return count($this->accessedPages);
    }

    public function getFilePath(): string
    {
        return $this->filePath;
    }

    public function resetAccessTracking(): void
    {
        $this->accessedPages = [];
    }
}
