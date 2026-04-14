<?php
declare(strict_types=1);

namespace WpSync\Store;

interface RefStore
{
    public function getRef(string $name): ?string;

    /** @return array<string,string> name => hash */
    public function listRefs(string $prefix = ''): array;

    /**
     * Atomic compare-and-swap.
     * $expectedOldHash === null means "ref must not exist".
     * Returns true on success, false on mismatch.
     */
    public function casRef(string $name, ?string $expectedOldHash, string $newHash): bool;
}
