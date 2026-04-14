<?php
declare(strict_types=1);

namespace WpSync\Tree;

use WpSync\Encoding\CanonicalEncoder;
use WpSync\Store\Chunk;

/**
 * Leaf node: sorted list of [key,value] byte-string pairs.
 *
 * Encoded CBOR body:
 *   { "type": "leaf", "entries": [[key,value], [key,value], ...] }
 *
 * Both key and value encode as CBOR byte strings (not text).
 */
final class LeafNode
{
    /** @param array<int,array{0:string,1:string}> $entries */
    public function __construct(public readonly array $entries) {}

    public function toChunk(): Chunk
    {
        $payload = [
            'type' => 'leaf',
            'entries' => array_map(
                static fn(array $e) => [
                    CanonicalEncoder::bytes($e[0]),
                    CanonicalEncoder::bytes($e[1]),
                ],
                $this->entries,
            ),
        ];
        $bytes = CanonicalEncoder::encode($payload);
        return Chunk::of($bytes, []);
    }

    /** Inverse of toChunk: parse a decoded payload back into a LeafNode. */
    public static function fromDecoded(array $decoded): self
    {
        if (($decoded['type'] ?? null) !== 'leaf') {
            throw new \RuntimeException('not a leaf node');
        }
        $entries = [];
        foreach ($decoded['entries'] as $pair) {
            $entries[] = [$pair[0], $pair[1]];
        }
        return new self($entries);
    }
}
