<?php
declare(strict_types=1);

namespace WpSync\Store;

interface ChunkStore
{
    /**
     * @param string[] $hashes
     * @return array<string, true> hash => true for each present hash
     */
    public function hasMany(array $hashes): array;

    /**
     * @param string[] $hashes
     * @return array<string, Chunk> hash => Chunk for each found; missing hashes are omitted
     */
    public function getMany(array $hashes): array;

    /**
     * @param Chunk[] $chunks
     * MUST verify hash matches content and reject bad chunks.
     */
    public function putMany(array $chunks): void;
}
