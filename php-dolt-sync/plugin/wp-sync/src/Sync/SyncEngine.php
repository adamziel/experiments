<?php
declare(strict_types=1);

namespace WpSync\Sync;

use WpSync\Store\Chunk;
use WpSync\Store\ChunkStore;
use WpSync\Store\RefStore;
use WpSync\Snapshot\Snapshotter;
use WpSync\Encoding\CanonicalEncoder;

final class SyncEngine
{
    public int $getManyCalls = 0;

    /**
     * Walk the DAG from $rootHash. Copy every chunk reachable from it
     * that is not already present in $to. Writes are ordered so each chunk's
     * children are present before it.
     *
     * Returns count of chunks transferred.
     */
    public function copyReachable(
        ChunkStore $to,
        ChunkStore $from,
        string $rootHash,
        int $batchSize = 256,
    ): int {
        $transferred = 0;
        $seen = [];            // hashes we've already scheduled or processed
        $queue = [$rootHash];
        $seen[$rootHash] = true;

        // Phase 1: discovery — collect all reachable chunks missing from $to.
        // Phase 2: topological write — write children before parents.
        //
        // Since we don't know refs until we fetch the chunk, we do interleaved
        // discovery: ask `hasMany` first to avoid fetching present chunks.

        $pendingChunks = []; // hash => Chunk (fetched but not yet written)
        $childrenOf = [];    // hash => string[] of child hashes

        while ($queue) {
            $batch = array_splice($queue, 0, $batchSize);
            // Ask remote's target which are already present.
            $present = $to->hasMany($batch);

            $need = [];
            foreach ($batch as $h) {
                if (isset($present[$h])) continue;
                $need[] = $h;
            }

            if ($need) {
                $this->getManyCalls++;
                $got = $from->getMany($need);
                if (count($got) !== count($need)) {
                    $missing = array_diff($need, array_keys($got));
                    throw new \RuntimeException('sync: getMany returned fewer than requested: ' . implode(',', $missing));
                }
                foreach ($got as $h => $c) {
                    $c->verify();
                    $pendingChunks[$h] = $c;
                    $childrenOf[$h] = $c->refs;
                    foreach ($c->refs as $child) {
                        if (!isset($seen[$child])) {
                            $seen[$child] = true;
                            $queue[] = $child;
                        }
                    }
                }
            }
        }

        // Topological write: a chunk is ready when all its children are either
        // already in $to or have been written in this run.
        $written = [];
        $writtenCount = 0;
        // We combine "present in target" with "written in this run" into a
        // single "exists" check. We'll check target presence in batches when needed.

        // First, batch-check which pendings' children are already in $to.
        $childrenAll = [];
        foreach ($pendingChunks as $h => $c) {
            foreach ($c->refs as $cref) {
                $childrenAll[$cref] = true;
            }
        }
        $targetPresent = $to->hasMany(array_keys($childrenAll));

        while ($pendingChunks) {
            $writeBatch = [];
            foreach ($pendingChunks as $h => $c) {
                $ready = true;
                foreach ($c->refs as $child) {
                    if (isset($written[$child])) continue;
                    if (isset($targetPresent[$child])) continue;
                    if (isset($pendingChunks[$child])) { $ready = false; break; }
                    // Not in any known set — impossible if we tracked refs correctly.
                    // Optimistically re-check target.
                    $ready = false; break;
                }
                if ($ready) {
                    $writeBatch[$h] = $c;
                    if (count($writeBatch) >= $batchSize) break;
                }
            }
            if (!$writeBatch) {
                // Cycle or missing dependency.
                throw new \RuntimeException('sync: cycle or missing dependency in DAG (pending=' . count($pendingChunks) . ')');
            }
            $to->putMany(array_values($writeBatch));
            foreach ($writeBatch as $h => $_) {
                $written[$h] = true;
                unset($pendingChunks[$h]);
                $writtenCount++;
            }
        }
        return $writtenCount;
    }

    /** True iff walking local commit parents from $localRefHash reaches $remoteRefHash. */
    public function isFastForward(ChunkStore $store, string $localRefHash, ?string $remoteRefHash): bool
    {
        if ($remoteRefHash === null) return true;
        if ($localRefHash === $remoteRefHash) return true;
        $seen = [];
        $q = [$localRefHash];
        while ($q) {
            $h = array_shift($q);
            if (isset($seen[$h])) continue;
            $seen[$h] = true;
            if ($h === $remoteRefHash) return true;
            $got = $store->getMany([$h]);
            if (!isset($got[$h])) return false;
            $commit = CanonicalEncoder::decode($got[$h]->bytes);
            foreach ($commit['parents'] ?? [] as $p) {
                if (!isset($seen[$p])) $q[] = $p;
            }
        }
        return false;
    }
}
