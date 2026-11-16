<?php

namespace App\Http\Middleware;

use App\Services\Guest\GuestIdentityService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Re-queue the guest cookie transport ONCE after a successful mutating cart
 * request.
 *
 * The domain owns retention (identity + cart expiry); this middleware owns
 * only the cookie transport, so `GuestCartService` no longer needs the HTTP
 * request. Reads never refresh; failed mutations never refresh; Bearer
 * credentials have no cookie to refresh.
 */
class GuestCookieRefresh
{
    public function __construct(private GuestIdentityService $identities)
    {
    }

    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        if ($request->isMethodSafe() || !$response->isSuccessful()) {
            return $response;
        }

        $identity = $this->identities->current($request);

        if ($identity && $this->identities->authMode($request) === GuestIdentityService::MODE_COOKIE) {
            $this->identities->refreshCookies($request, $identity);
        }

        return $response;
    }
}
