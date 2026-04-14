<?php
declare(strict_types=1);

namespace WpSync\Sync;

use WpSync\Store\ChunkStore;
use WpSync\Store\HttpChunkStore;
use WpSync\Store\RefStore;

/**
 * Push flow:
 *   1. Read local and remote ref hashes.
 *   2. If equal, no-op.
 *   3. Verify fast-forward (remote is ancestor of local).
 *   4. copyReachable(remoteChunks, localChunks, localHash).
 *   5. casRef(remote, name, expected=remoteOld, new=localHash). If fail: abort.
 */
final class PushCommand
{
    public function __construct(
        private readonly SyncEngine $engine,
        private readonly ChunkStore $localChunks,
        private readonly RefStore $localRefs,
        private readonly HttpChunkStore $remote,
    ) {}

    public function run(string $refName = 'refs/heads/main'): array
    {
        $localHash = $this->localRefs->getRef($refName);
        if ($localHash === null) {
            throw new \RuntimeException("no local ref: {$refName}");
        }
        $remoteRefs = $this->remote->getAllRefs();
        $remoteHash = $remoteRefs[$refName] ?? null;

        if ($localHash === $remoteHash) {
            return ['transferred' => 0, 'status' => 'up-to-date'];
        }
        if ($remoteHash !== null && !$this->engine->isFastForward($this->localChunks, $localHash, $remoteHash)) {
            throw new \RuntimeException('push rejected: non-fast-forward');
        }

        $transferred = $this->engine->copyReachable($this->remote, $this->localChunks, $localHash);

        $ok = $this->remote->casRef($refName, $remoteHash, $localHash);
        if (!$ok) {
            throw new \RuntimeException('push rejected: remote ref moved concurrently; pull first');
        }
        return ['transferred' => $transferred, 'status' => 'pushed'];
    }
}
