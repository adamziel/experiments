<?php
declare(strict_types=1);

namespace WpSync\Test\Harness;

use WpSync\Store\Chunk;
use WpSync\Store\ChunkStore;
use WpSync\Store\RefStore;

/**
 * In-process transport that routes HttpChunkStore calls directly to a
 * server-side ChunkStore + RefStore, without actually making HTTP requests.
 * Drives the same JSON protocol the real mu-plugin REST endpoints implement,
 * so it exercises the same code paths.
 */
final class InProcessTransport
{
    /** Optional failure hook: called with ($method, $path, $chunksSeen) -> null|throw */
    public $failureHook = null;

    /** Count of chunks transferred via /chunks/put so far. */
    public int $putCount = 0;
    public int $getCount = 0;
    public int $hasCount = 0;

    public function __construct(
        public readonly ChunkStore $chunks,
        public readonly RefStore $refs,
    ) {}

    public function asCallable(): callable
    {
        return function (string $method, string $path, ?string $body, array $headers): array {
            return $this->handle($method, $path, $body);
        };
    }

    public function handle(string $method, string $path, ?string $body): array
    {
        $json = $body === null ? [] : json_decode($body, true, flags: JSON_THROW_ON_ERROR);

        if ($method === 'POST' && $path === '/wp-json/sync/v1/chunks/has') {
            $this->hasCount++;
            return ['status' => 200, 'body' => json_encode([
                'present' => $this->chunks->hasMany($json['hashes'] ?? [])
            ])];
        }
        if ($method === 'POST' && $path === '/wp-json/sync/v1/chunks/get') {
            $this->getCount++;
            $out = [];
            foreach ($this->chunks->getMany($json['hashes'] ?? []) as $c) {
                $out[] = ['hash' => $c->hash, 'data' => base64_encode($c->bytes), 'refs' => $c->refs];
            }
            return ['status' => 200, 'body' => json_encode(['chunks' => $out])];
        }
        if ($method === 'POST' && $path === '/wp-json/sync/v1/chunks/put') {
            $input = $json['chunks'] ?? [];
            if ($this->failureHook) {
                $r = ($this->failureHook)($method, $path, $this->putCount);
                if ($r === 'fail') {
                    return ['status' => 500, 'body' => '{"error":"simulated"}'];
                }
            }
            $objs = [];
            foreach ($input as $c) {
                $raw = base64_decode($c['data'], true);
                $objs[] = new Chunk($c['hash'], $raw, $c['refs'] ?? []);
            }
            $this->chunks->putMany($objs);
            $this->putCount += count($objs);
            return ['status' => 200, 'body' => json_encode(['stored' => count($objs)])];
        }
        if ($method === 'GET' && $path === '/wp-json/sync/v1/refs') {
            return ['status' => 200, 'body' => json_encode($this->refs->listRefs())];
        }
        if ($method === 'PUT' && str_starts_with($path, '/wp-json/sync/v1/refs/')) {
            $name = rawurldecode(substr($path, strlen('/wp-json/sync/v1/refs/')));
            $old = $json['old'] ?? null;
            $new = (string)($json['new'] ?? '');
            $ok = $this->refs->casRef($name, $old === null || $old === '' ? null : (string)$old, $new);
            if (!$ok) {
                return ['status' => 409, 'body' => '{"ok":false,"error":"cas_mismatch"}'];
            }
            return ['status' => 200, 'body' => '{"ok":true}'];
        }
        return ['status' => 404, 'body' => '{"error":"not-found"}'];
    }
}
