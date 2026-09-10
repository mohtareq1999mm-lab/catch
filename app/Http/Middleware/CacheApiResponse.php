<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Symfony\Component\HttpFoundation\Response;

class CacheApiResponse
{
    protected array $skipRoutes = [
        'api/general/products/*/reviews',
        'api/general/checkout',
        'api/general/checkout/*',
        'api/general/coupons/apply',
    ];

    protected int $ttl = 3600;

    public function handle(Request $request, Closure $next): Response
    {
        if (!$request->isMethod('GET')) {
            $response = $next($request);
            if ($response->isSuccessful()) {
                Cache::increment('api_cache_version');
            }
            return $response;
        }

        foreach ($this->skipRoutes as $pattern) {
            if ($request->is($pattern)) {
                return $next($request);
            }
        }

        $key = $this->buildCacheKey($request);

        if (Cache::has($key)) {
            $cached = Cache::get($key);
            return response($cached['content'], $cached['status'])
                ->withHeaders($cached['headers']);
        }

        $response = $next($request);

        if ($response->isSuccessful()) {
            Cache::put($key, [
                'content' => $response->getContent(),
                'status'  => $response->getStatusCode(),
                'headers' => $response->headers->all(),
            ], now()->addSeconds($this->ttl));
        }

        return $response;
    }

    protected function buildCacheKey(Request $request): string
    {
        $version = Cache::get('api_cache_version', 0);
        $parts = [
            'api_cache',
            $version,
            $request->fullUrl(),
            auth()->id() ?? 'guest',
        ];
        return md5(implode('|', $parts));
    }
}
