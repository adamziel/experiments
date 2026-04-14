<?php
declare(strict_types=1);

namespace WpSync\Store;

final class Chunk
{
    public function __construct(
        public readonly string $hash,
        public readonly string $bytes,
        public readonly array $refs = [],
    ) {}

    /** Compute hash and build a chunk. */
    public static function of(string $bytes, array $refs = []): self
    {
        return new self(hash('sha256', $bytes), $bytes, array_values($refs));
    }

    /** Verify hash matches content. */
    public function verify(): void
    {
        $actual = hash('sha256', $this->bytes);
        if (!hash_equals($this->hash, $actual)) {
            throw new \RuntimeException("Chunk hash mismatch: declared={$this->hash} actual={$actual}");
        }
        foreach ($this->refs as $r) {
            if (!is_string($r) || !preg_match('/^[0-9a-f]{64}$/', $r)) {
                throw new \RuntimeException('Chunk has invalid ref: ' . var_export($r, true));
            }
        }
    }
}
