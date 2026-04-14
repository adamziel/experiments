<?php
declare(strict_types=1);

namespace WpSync\Tree;

use WpSync\Encoding\CanonicalEncoder;
use WpSync\Store\Chunk;

/**
 * Internal node: sorted list of [separator_key, child_hash] pairs.
 * The separator_key is the MINIMUM key of the subtree rooted at child_hash.
 *
 * Encoded CBOR body:
 *   { "type": "internal", "children": [[sep_key, hash], ...] }
 */
final class InternalNode
{
    /** @param array<int,array{0:string,1:string}> $children */
    public function __construct(public readonly array $children) {}

    public function toChunk(): Chunk
    {
        $payload = [
            'type' => 'internal',
            'children' => array_map(
                static fn(array $c) => [
                    CanonicalEncoder::bytes($c[0]),
                    $c[1], // child hash is hex text
                ],
                $this->children,
            ),
        ];
        $bytes = CanonicalEncoder::encode($payload);
        $refs = array_map(static fn(array $c) => $c[1], $this->children);
        return Chunk::of($bytes, $refs);
    }

    public static function fromDecoded(array $decoded): self
    {
        if (($decoded['type'] ?? null) !== 'internal') {
            throw new \RuntimeException('not an internal node');
        }
        $children = [];
        foreach ($decoded['children'] as $pair) {
            $children[] = [$pair[0], $pair[1]];
        }
        return new self($children);
    }
}
