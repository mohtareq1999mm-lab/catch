<?php

namespace Tests\Unit\Http\Middleware;

use App\Http\Middleware\BypassThrottle;
use App\Http\Middleware\GuestRateLimit;
use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\RateLimiter;
use Illuminate\Http\Request;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Support\Facades\Config;
use Tests\TestCase;

/**
 * The argument contract of the throttle middleware (Candidate 01).
 *
 * Laravel resolves a NAMED limiter only when parent::handle receives
 * EXACTLY three arguments; any extra argument makes func_num_args() !== 3
 * inside the parent, so the limiter NAME is parsed as maxAttempts and
 * silently becomes zero (the bug caught by GuestRouteBehaviourTest).
 */
class PolicyThrottleTest extends TestCase
{
    private function limiter(): RateLimiter
    {
        return new RateLimiter(new \Illuminate\Cache\Repository(new ArrayStore()));
    }

    private function request(string $uri = '/api/x'): Request
    {
        $request = Request::create($uri, 'GET');
        // The standard (numeric) path signs the request by route; unit
        // requests have no router, so provide a stand-in.
        $request->setRouteResolver(fn () => new \Illuminate\Routing\Route(['GET'], $uri, fn () => null));

        return $request;
    }

    private function namedLimiter(string $name, int $max): void
    {
        app(RateLimiter::class)->for($name, fn () => \Illuminate\Cache\RateLimiting\Limit::perMinute($max)->by('ip:127.0.0.1'));
    }

    public function test_named_limiter_is_enforced_through_the_bypass_alias(): void
    {
        $this->namedLimiter('public-api', 5);
        config(['app.disable_rate_limiting' => false]);

        $middleware = new BypassThrottle(app(RateLimiter::class));
        $request = Request::create('/api/x', 'GET');
        for ($i = 0; $i < 5; $i++) {
            $response = $middleware->handle($request, fn () => response()->json(['ok' => true]), 'public-api');
            $this->assertSame(200, $response->getStatusCode());
        }

        $this->expectException(ThrottleRequestsException::class);
        $middleware->handle($request, fn () => response()->json(['ok' => true]), 'public-api');
    }

    public function test_numeric_arguments_use_the_standard_path(): void
    {
        config(['app.disable_rate_limiting' => false]);
        $middleware = new BypassThrottle(app(RateLimiter::class));
        $request = $this->request();

        // throttle:2,1 — two middleware arguments, standard path, decay 1 min.
        for ($i = 0; $i < 2; $i++) {
            $middleware->handle($request, fn () => response('ok'), 2, 1);
        }

        $this->expectException(ThrottleRequestsException::class);
        $middleware->handle($request, fn () => response('ok'), 2, 1);
    }

    public function test_bypassable_policy_skips_when_rate_limiting_is_disabled(): void
    {
        config(['app.disable_rate_limiting' => true]);
        $this->namedLimiter('public-api', 1);

        $middleware = new BypassThrottle(app(RateLimiter::class));
        $request = Request::create('/api/x', 'GET');

        $response = $middleware->handle($request, fn () => response('ok'), 'public-api');
        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame(0, app(RateLimiter::class)->attempts('public-api:ip:127.0.0.1'), 'Bypassed calls must not consume limiter budget.');
    }

    public function test_fail_closed_policy_enforces_even_when_rate_limiting_is_disabled(): void
    {
        config(['app.disable_rate_limiting' => true]);
        $this->namedLimiter('guest_session', 1);

        $middleware = new GuestRateLimit(app(RateLimiter::class));
        $request = Request::create('/api/guest/x', 'GET');

        $middleware->handle($request, fn () => response('ok'), 'guest_session');

        // Exceeded: the fail-closed envelope is the guest JSON contract.
        $response = $middleware->handle($request, fn () => response('ok'), 'guest_session');
        $this->assertSame(429, $response->getStatusCode());
        $this->assertFalse($response->getData(true)['success']);
    }
}
