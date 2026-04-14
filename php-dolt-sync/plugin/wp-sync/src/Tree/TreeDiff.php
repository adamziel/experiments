<?php
declare(strict_types=1);

namespace WpSync\Tree;

use WpSync\Store\ChunkStore;

/** Thin wrapper around TableTree::diff. */
final class TreeDiff
{
    /** @return \Generator<int,array{0:string,1:?string,2:?string}> */
    public static function diff(string $rootA, string $rootB, ChunkStore $store): \Generator
    {
        yield from TableTree::diff($rootA, $rootB, $store);
    }
}
