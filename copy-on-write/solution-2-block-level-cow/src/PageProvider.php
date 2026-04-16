<?php

declare(strict_types=1);

namespace CowClone\BlockLevel;

interface PageProvider
{
    /**
     * Read a page by its 1-based page number (SQLite convention).
     * Returns exactly getPageSize() bytes.
     */
    public function readPage(int $pageNumber): string;

    public function getPageSize(): int;

    public function getPageCount(): int;
}
