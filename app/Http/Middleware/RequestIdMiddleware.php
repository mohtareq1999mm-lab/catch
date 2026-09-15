<?php

namespace App\Http\Middleware;

use App\Audit\ActivityContext;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class RequestIdMiddleware
{
    public function handle(Request $request, Closure $next)
    {
        ActivityContext::reset();

        $requestId = $request->header('X-Request-ID');
        if (!is_string($requestId) || $requestId === '' || strlen($requestId) > 128
            || !preg_match('/^[a-zA-Z0-9\-\_.]+$/', $requestId)) {
            $requestId = (string) Str::uuid();
        }

        $source = $request->is('graphql*') ? 'graphql' : 'api';

        ActivityContext::instance()
            ->set('request_id', $requestId)
            ->set('source', $source)
            ->set('ip', $request->ip())
            ->set('user_agent', $request->userAgent())
            ->set('route', $request->route()?->getName() ?? $request->path())
            ->set('method', $request->method());

        $response = $next($request);
        $response->headers->set('X-Request-ID', $requestId);

        return $response;
    }
}
