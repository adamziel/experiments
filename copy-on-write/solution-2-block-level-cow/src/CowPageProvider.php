<?php

declare(strict_types=1);

namespace CowClone\BlockLevel;

/**
 * Copy-on-write layer over a PageProvider.
 * Reads check local overlay first, then fall through to backend.
 * Writes always go to the local overlay.
 * The backend is never modified.
 */
class CowPageProvider implements PageProvider
{
    private PageProvider $backend;
    /** @var array<int, string> page number => page data */
    private array $overlay = [];
    private int $pageCount;

    public function __construct(PageProvider $backend)
    {
        $this->backend = $backend;
        $this->pageCount = $backend->getPageCount();
    }

    public function readPage(int $pageNumber): string
    {
        if (isset($this->overlay[$pageNumber])) {
            return $this->overlay[$pageNumber];
        }
        if ($pageNumber >= 1 && $pageNumber <= $this->backend->getPageCount()) {
            return $this->backend->readPage($pageNumber);
        }
        return str_repeat("\0", $this->getPageSize());
    }

    public function writePage(int $pageNumber, string $data): void
    {
        $this->overlay[$pageNumber] = $data;
        if ($pageNumber > $this->pageCount) {
            $this->pageCount = $pageNumber;
        }
    }

    public function getPageSize(): int
    {
        return $this->backend->getPageSize();
    }

    public function getPageCount(): int
    {
        return $this->pageCount;
    }

    public function isPageLocal(int $pageNumber): bool
    {
        return isset($this->overlay[$pageNumber]);
    }

    /** @return int[] */
    public function getLocalPages(): array
    {
        return array_keys($this->overlay);
    }

    public function getBackend(): PageProvider
    {
        return $this->backend;
    }
}
