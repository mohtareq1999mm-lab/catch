<?php

namespace App\Http\Controllers\Api\General\Guest;

use App\Http\Controllers\Controller;
use App\Services\Guest\GuestIdentityService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Marvel\Traits\ApiResponse;

/**
 * Guest session lifecycle. The Backend owns generation, cookie issuance,
 * expiration and revocation. No token ever appears in JSON.
 */
class GuestSessionController extends Controller
{
    use ApiResponse;

    public function __construct(private GuestIdentityService $identities)
    {
    }

    public function store(Request $request): JsonResponse
    {
        $identity = $this->identities->current($request);

        if (!$identity) {
            [$identity] = $this->identities->create($request);
        } else {
            // Re-queue cookies without extending the 7-day retention.
            $this->identities->refreshCookies($request, $identity);
        }

        return $this->apiResponse(
            __('guest_checkout.session_ready'),
            200,
            true,
            [
                'guest' => [
                    'expires_at' => $identity->expires_at?->toIso8601String(),
                ],
                // The CSRF value is NOT an authentication credential. It is
                // returned here so a frontend on a different host cannot rely
                // on reading the API host's cookie via document.cookie.
                'csrf_token' => $this->identities->csrfToken($identity),
                'cookies' => $this->identities->cookieMetadata($request),
            ]
        );
    }

    public function destroy(Request $request): JsonResponse
    {
        $identity = $this->identities->current($request);

        if ($identity) {
            $identity->forceFill(['revoked_at' => now()])->save();
        }

        $this->identities->clearCookies();

        return $this->apiResponse(__('guest_checkout.session_revoked'), 200, true);
    }
}
