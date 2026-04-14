<?php
declare(strict_types=1);

namespace WpSync\Tree;

use WpSync\Encoding\CanonicalEncoder;
use WpSync\Store\Chunk;
use WpSync\Store\ChunkStore;

/**
 * Sorted B-tree over byte-string keys, all nodes are content-addressed chunks.
 * Built bottom-up from sorted entries; deterministic (same entries -> same root).
 */
final class TableTree
{
    public const FANOUT = 64;

    /**
     * @param array<int,array{0:string,1:string}> $entries Unordered [key,value] pairs.
     * @return string Root chunk hash.
     */
    public static function build(array $entries, ChunkStore $store, int $fanout = self::FANOUT): string
    {
        // Deduplicate by key (last wins) and sort by key bytes.
        $map = [];
        foreach ($entries as $e) {
            $map[$e[0]] = $e[1];
        }
        ksort($map, SORT_STRING);
        $sorted = [];
        foreach ($map as $k => $v) {
            $sorted[] = [(string)$k, (string)$v];
        }

        // Empty tree: single empty leaf.
        if ($sorted === []) {
            $leaf = new LeafNode([]);
            $chunk = $leaf->toChunk();
            $store->putMany([$chunk]);
            return $chunk->hash;
        }

        // Build leaves.
        $level = [];
        $pages = array_chunk($sorted, $fanout);
        $chunks = [];
        foreach ($pages as $page) {
            $leaf = new LeafNode($page);
            $chunk = $leaf->toChunk();
            $chunks[] = $chunk;
            $level[] = [$page[0][0], $chunk->hash]; // separator = min key in page
        }
        $store->putMany($chunks);

        // Build internal levels until single root.
        while (count($level) > 1) {
            $next = [];
            $levelChunks = [];
            foreach (array_chunk($level, $fanout) as $group) {
                $node = new InternalNode($group);
                $chunk = $node->toChunk();
                $levelChunks[] = $chunk;
                $next[] = [$group[0][0], $chunk->hash];
            }
            $store->putMany($levelChunks);
            $level = $next;
        }
        return $level[0][1];
    }

    public static function get(string $rootHash, string $key, ChunkStore $store): ?string
    {
        $nodeHash = $rootHash;
        while (true) {
            $decoded = self::loadNode($nodeHash, $store);
            if (($decoded['type'] ?? null) === 'leaf') {
                foreach ($decoded['entries'] as $pair) {
                    if ($pair[0] === $key) {
                        return $pair[1];
                    }
                }
                return null;
            }
            // internal: find rightmost child whose separator <= key
            $childHash = null;
            foreach ($decoded['children'] as $pair) {
                if (strcmp($pair[0], $key) <= 0) {
                    $childHash = $pair[1];
                } else {
                    break;
                }
            }
            if ($childHash === null) {
                return null; // key precedes first separator
            }
            $nodeHash = $childHash;
        }
    }

    /**
     * Iterate all entries in sorted order.
     * @return \Generator<int,array{0:string,1:string}>
     */
    public static function iterate(string $rootHash, ChunkStore $store): \Generator
    {
        yield from self::iterateNode($rootHash, $store);
    }

    private static function iterateNode(string $nodeHash, ChunkStore $store): \Generator
    {
        $decoded = self::loadNode($nodeHash, $store);
        if (($decoded['type'] ?? null) === 'leaf') {
            foreach ($decoded['entries'] as $pair) {
                yield [$pair[0], $pair[1]];
            }
            return;
        }
        foreach ($decoded['children'] as $c) {
            yield from self::iterateNode($c[1], $store);
        }
    }

    /**
     * Diff two trees. Yields triples [key, valueA|null, valueB|null]
     * for entries that differ. Entries present in only one side have null on the other.
     * Subtrees with identical hashes are skipped (O(log n) behavior for small diffs).
     *
     * @return \Generator<int,array{0:string,1:?string,2:?string}>
     */
    public static function diff(string $rootA, string $rootB, ChunkStore $store): \Generator
    {
        if ($rootA === $rootB) return;

        // Stack of [hashA|null, hashB|null, isLeafA, isLeafB]
        // We traverse in sync, descending where hashes differ.
        yield from self::diffNodes($rootA, $rootB, $store);
    }

    private static function diffNodes(?string $hashA, ?string $hashB, ChunkStore $store): \Generator
    {
        if ($hashA === $hashB) return;

        $a = $hashA !== null ? self::loadNode($hashA, $store) : null;
        $b = $hashB !== null ? self::loadNode($hashB, $store) : null;

        $aIsLeaf = $a !== null && ($a['type'] ?? null) === 'leaf';
        $bIsLeaf = $b !== null && ($b['type'] ?? null) === 'leaf';

        if (($a === null || $aIsLeaf) && ($b === null || $bIsLeaf)) {
            // Compare two leaves (or leaf vs missing) directly.
            $entriesA = $a ? $a['entries'] : [];
            $entriesB = $b ? $b['entries'] : [];
            yield from self::diffLeafEntries($entriesA, $entriesB);
            return;
        }

        // At least one side is internal. Flatten sides to a list of
        // (minKey, maxKey, childHash, isLeaf) to walk in sorted order.
        $aChildren = self::nodeToChildRanges($a, $hashA);
        $bChildren = self::nodeToChildRanges($b, $hashB);

        // Walk both child lists in parallel by key range.
        $i = 0;
        $j = 0;
        while ($i < count($aChildren) || $j < count($bChildren)) {
            $ca = $aChildren[$i] ?? null;
            $cb = $bChildren[$j] ?? null;
            if ($ca && $cb && $ca['min'] === $cb['min']) {
                // Same key range entry point — descend both.
                if ($ca['hash'] !== $cb['hash']) {
                    yield from self::diffNodes($ca['hash'], $cb['hash'], $store);
                }
                $i++; $j++;
                continue;
            }
            if ($cb === null || ($ca !== null && strcmp($ca['min'], $cb['min']) < 0)) {
                // only-in-A subtree
                yield from self::diffNodes($ca['hash'], null, $store);
                $i++;
            } else {
                yield from self::diffNodes(null, $cb['hash'], $store);
                $j++;
            }
        }
    }

    private static function nodeToChildRanges(?array $node, ?string $selfHash): array
    {
        // For internal nodes: list their children. For leaf nodes, treat as a single child
        // (so diffNodes can descend into diffLeafEntries on the next recursion).
        if ($node === null) return [];
        if (($node['type'] ?? null) === 'leaf') {
            if ($node['entries'] === []) {
                return [];
            }
            return [[
                'min' => $node['entries'][0][0],
                'hash' => $selfHash,
                'leaf' => true,
            ]];
        }
        $out = [];
        foreach ($node['children'] as $c) {
            $out[] = ['min' => $c[0], 'hash' => $c[1], 'leaf' => false];
        }
        return $out;
    }

    private static function diffLeafEntries(array $a, array $b): \Generator
    {
        $ai = 0; $bi = 0;
        while ($ai < count($a) || $bi < count($b)) {
            $ea = $a[$ai] ?? null;
            $eb = $b[$bi] ?? null;
            if ($ea && $eb && $ea[0] === $eb[0]) {
                if ($ea[1] !== $eb[1]) {
                    yield [$ea[0], $ea[1], $eb[1]];
                }
                $ai++; $bi++;
            } elseif ($eb === null || ($ea !== null && strcmp($ea[0], $eb[0]) < 0)) {
                yield [$ea[0], $ea[1], null];
                $ai++;
            } else {
                yield [$eb[0], null, $eb[1]];
                $bi++;
            }
        }
    }

    private static function loadNode(string $hash, ChunkStore $store): array
    {
        $chunks = $store->getMany([$hash]);
        if (!isset($chunks[$hash])) {
            throw new \RuntimeException("TableTree: chunk not found: {$hash}");
        }
        return CanonicalEncoder::decode($chunks[$hash]->bytes);
    }
}
