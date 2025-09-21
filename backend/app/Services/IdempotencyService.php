<?php

namespace App\Services;

use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Http\Request;

class IdempotencyService
{
    public function __construct(private readonly CacheRepository $cache)
    {
    }

    public function getCachedResponse(Request $request, string $operation, ?string $scope = null): ?array
    {
        $cacheKey = $this->resolveCacheKey($request, $operation, $scope);

        if ($cacheKey === null) {
            return null;
        }

        $cached = $this->cache->get($cacheKey);

        return is_array($cached) ? $cached : null;
    }

    /**
     * @param array<string, mixed> $response
     */
    public function storeResponse(Request $request, string $operation, array $response, ?string $scope = null): void
    {
        $cacheKey = $this->resolveCacheKey($request, $operation, $scope);

        if ($cacheKey === null) {
            return;
        }

        $ttl = (int) config('api.idempotency_ttl', 300);

        if ($ttl <= 0) {
            $ttl = 300;
        }

        $this->cache->put($cacheKey, $response, $ttl);
    }

    private function resolveCacheKey(Request $request, string $operation, ?string $scope = null): ?string
    {
        $header = $request->header('Idempotency-Key');

        if ($header === null) {
            return null;
        }

        $header = trim($header);

        if ($header === '') {
            return null;
        }

        $payload = $scope !== null ? $scope.'|'.$header : $header;

        return sprintf('idempotency:%s:%s', $operation, sha1($payload));
    }
}
