<?php

namespace App\Http\Middleware;

use App\Models\GuestIdentity;
use App\Services\Guest\GuestIdentityService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Resolve the verified Guest Identity ONCE for guest surfaces that require an
 * existing session.
 *
 * Fails closed with the approved 401 envelope. On success the identity is
 * bound into the container so controllers receive it via method injection —
 * no controller re-proves the credential.
 *
 * Session creation, idempotent revocation and the public one-way token paths
 * are exempt by design: they must run without a session.
 */
class GuestSession
{
    public function __construct(private GuestIdentityService $identities)
    {
    }

    public function handle(Request $request, Closure $next): Response
    {
        $identity = $this->identities->current($request);

        if (!$identity) {
            return response()->json([
                'status' => 401,
                'message' => __('guest_checkout.session_required'),
                'success' => false,
            ], 401);
        }

        app()->instance(GuestIdentity::class, $identity);

        return $next($request);
    }
}
