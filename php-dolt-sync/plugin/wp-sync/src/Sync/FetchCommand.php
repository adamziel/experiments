<?php
declare(strict_types=1);

namespace WpSync\Sync;

use WpSync\Store\ChunkStore;
use WpSync\Store\HttpChunkStore;
use WpSync\Store\RefStore;

/**
 * Fetch flow:
 *   1. remoteHash = remote.refs.get(refs/heads/main)
 *   2. localTrackingHash = localRefs.get(refs/remotes/origin/main)
 *   3. copyReachable(localChunks, remoteChunks, remoteHash)
 *   4. localRefs.cas(refs/remotes/origin/main, oldTracking, remoteHash)
 */
final class FetchCommand
{
    public function __construct(
        private readonly SyncEngine $engine,
        private readonly ChunkStore $localChunks,
        private readonly RefStore $localRefs,
        private readonly HttpChunkStore $remote,
    ) {}

    public function run(string $refName = 'refs/heads/main', string $trackingName = 'refs/remotes/origin/main'): array
    {
        $remoteRefs = $this->remote->getAllRefs();
        $remoteHash = $remoteRefs[$refName] ?? null;
        if ($remoteHash === null) {
            return ['transferred' => 0, 'hash' => null, 'status' => 'empty-remote'];
        }
        $localTracking = $this->localRefs->getRef($trackingName);
        $transferred = 0;
        if ($localTracking !== $remoteHash) {
            $transferred = $this->engine->copyReachable($this->localChunks, $this->remote, $remoteHash);
            $ok = $this->localRefs->casRef($trackingName, $localTracking, $remoteHash);
            if (!$ok) {
                throw new \RuntimeException('fetch: local tracking ref moved concurrently');
            }
        }
        return ['transferred' => $transferred, 'hash' => $remoteHash, 'status' => 'ok'];
    }
}
