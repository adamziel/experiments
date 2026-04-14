<?php
declare(strict_types=1);

namespace WpSync\Store;

/**
 * HTTP-backed chunk store. Talks to the sync mu-plugin REST API.
 *
 * The transport callable must be one of:
 *   - null: uses curl
 *   - callable(string $method, string $path, ?string $body, array $headers): array{status:int, body:string}
 */
final class HttpChunkStore implements ChunkStore
{
    private $transport;

    public function __construct(
        private readonly string $baseUrl,
        private readonly string $user,
        private readonly string $appPassword,
        ?callable $transport = null,
    ) {
        $this->transport = $transport ?? [$this, 'curlRequest'];
    }

    public function hasMany(array $hashes): array
    {
        if (!$hashes) return [];
        $resp = $this->post('/wp-json/sync/v1/chunks/has', ['hashes' => array_values($hashes)]);
        return isset($resp['present']) && is_array($resp['present']) ? $resp['present'] : [];
    }

    public function getMany(array $hashes): array
    {
        if (!$hashes) return [];
        $resp = $this->post('/wp-json/sync/v1/chunks/get', ['hashes' => array_values($hashes)]);
        $out = [];
        foreach ($resp['chunks'] ?? [] as $c) {
            $raw = base64_decode($c['data'], true);
            if ($raw === false) {
                throw new \RuntimeException('HttpChunkStore: base64 decode failed for ' . ($c['hash'] ?? '?'));
            }
            $chunk = new Chunk($c['hash'], $raw, $c['refs'] ?? []);
            $chunk->verify();
            $out[$chunk->hash] = $chunk;
        }
        return $out;
    }

    public function putMany(array $chunks): void
    {
        if (!$chunks) return;
        $payload = ['chunks' => []];
        foreach ($chunks as $c) {
            if (!$c instanceof Chunk) throw new \InvalidArgumentException('expected Chunk');
            $c->verify();
            $payload['chunks'][] = [
                'hash' => $c->hash,
                'data' => base64_encode($c->bytes),
                'refs' => $c->refs,
            ];
        }
        $this->post('/wp-json/sync/v1/chunks/put', $payload);
    }

    public function getAllRefs(): array
    {
        return $this->request('GET', '/wp-json/sync/v1/refs', null);
    }

    public function casRef(string $name, ?string $expectedOld, string $newHash): bool
    {
        $resp = $this->request('PUT', '/wp-json/sync/v1/refs/' . rawurlencode($name), [
            'old' => $expectedOld,
            'new' => $newHash,
        ], allowStatus: [200, 409]);
        return !empty($resp['ok']);
    }

    private function post(string $path, array $body): array
    {
        return $this->request('POST', $path, $body);
    }

    private function request(string $method, string $path, ?array $body, array $allowStatus = [200]): array
    {
        $bodyJson = $body === null ? null : json_encode($body, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        $headers = ['Content-Type: application/json', 'Accept: application/json'];
        $headers[] = 'Authorization: Basic ' . base64_encode($this->user . ':' . $this->appPassword);
        $r = ($this->transport)($method, $path, $bodyJson, $headers);
        if (!in_array($r['status'], $allowStatus, true)) {
            throw new \RuntimeException("HTTP {$method} {$path} failed: HTTP {$r['status']} body=" . substr($r['body'], 0, 512));
        }
        if ($r['body'] === '') return [];
        return json_decode($r['body'], true, flags: JSON_THROW_ON_ERROR);
    }

    private function curlRequest(string $method, string $path, ?string $body, array $headers): array
    {
        $ch = curl_init($this->baseUrl . $path);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        if ($body !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        }
        curl_setopt($ch, CURLOPT_TIMEOUT, 60);
        $resp = curl_exec($ch);
        if ($resp === false) {
            throw new \RuntimeException('curl error: ' . curl_error($ch));
        }
        $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        return ['status' => (int)$status, 'body' => (string)$resp];
    }
}
