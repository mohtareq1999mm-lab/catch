<?php

namespace App\Http\Controllers\Api\General\Guest;

use App\Http\Controllers\Controller;
use App\Models\GuestIdentity;
use App\Services\Guest\GuestIdentityService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Marvel\Traits\ApiResponse;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Mobile Guest Session lifecycle (D1/D6/D9 + A4).
 *
 * Native clients authenticate with an opaque `Authorization: Bearer` token.
 * No cookies are ever issued from these endpoints, CSRF does not apply, and
 * Origin is not required (GuestOrigin validates only when a header is
 * present). The raw token appears in the JSON exactly once, at issuance or
 * rotation; the database stores only its domain-separated HMAC hash.
 *
 * A4: this endpoint is NOT an idempotent session-resume. Calling `store()`
 * while a usable credential (either transport) is presented is rejected with
 * 409 `mobile_session_exists` — the exception message is the RAW translation
 * key so Handler exposes the machine-readable `code` (A10). Cold-start
 * clients probe `GET /guest/cart` first (A5): 200 => reuse; 401 => store().
 */
class GuestMobileSessionController extends Controller
{
    use ApiResponse;

    public function __construct(private GuestIdentityService $identities)
    {
    }

    public function store(Request $request): JsonResponse
    {
        $identity = $this->identities->current($request);

        if ($identity !== null) {
            // A4: a usable credential is already presented. Never create a
            // second identity; never idempotent-resume.
            throw new HttpException(409, 'guest_checkout.mobile_session_exists');
        }

        [$identity, $token] = $this->identities->createForMobile($request);

        return $this->respond(
            __('guest_checkout.mobile_session_ready'),
            201,
            [
                'guest' => $this->guestPayload($identity),
                'token' => $token,
                'token_type' => 'Bearer',
                'cart' => null,
            ]
        );
    }

    public function rotate(Request $request): JsonResponse
    {
        $identity = $this->identities->current($request);

        if (!$identity || $this->identities->authMode($request) !== GuestIdentityService::MODE_BEARER) {
            return $this->respond(__('guest_checkout.session_required'), 401);
        }

        $token = $this->identities->rotateMobileToken($request, $identity);

        return $this->respond(
            __('guest_checkout.mobile_session_rotated'),
            200,
            [
                'guest' => $this->guestPayload($identity),
                'token' => $token,
                'token_type' => 'Bearer',
            ]
        );
    }

    public function destroy(Request $request): JsonResponse
    {
        $identity = $this->identities->current($request);

        if (!$identity || $this->identities->authMode($request) !== GuestIdentityService::MODE_BEARER) {
            return $this->respond(__('guest_checkout.session_required'), 401);
        }

        $this->identities->revokeMobile($identity);

        // Clear cookies too, in case the same request also carried a browser
        // credential for this identity (identity-level revocation kills both).
        $this->identities->clearCookies();

        return $this->respond(__('guest_checkout.mobile_session_revoked'), 200);
    }

    private function guestPayload(GuestIdentity $identity): array
    {
        return ['expires_at' => $identity->expires_at?->toIso8601String()];
    }

    private function respond(string $message, int $status, array $data = []): JsonResponse
    {
        return $this->apiResponse($message, $status, true, $data)
            ->header('Cache-Control', 'no-store');
    }
}
