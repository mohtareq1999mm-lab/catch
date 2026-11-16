<?php

namespace App\Http\Controllers\Api\General\Guest;

use App\Http\Controllers\Controller;
use App\Models\GuestIdentity;
use App\Services\Guest\GuestMobileSessionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Marvel\Traits\ApiResponse;

/**
 * Mobile Guest Session HTTP surface (D1/D6/D9, A3/A4).
 *
 * Thin: policy lives in GuestMobileSessionService. This controller only
 * shapes the approved response payloads. No cookies are ever issued; the raw
 * token appears exactly once, at open/rotate; every response is no-store.
 */
class GuestMobileSessionController extends Controller
{
    use ApiResponse;

    public function __construct(private GuestMobileSessionService $sessions)
    {
    }

    public function store(Request $request): JsonResponse
    {
        $result = $this->sessions->open($request);

        return $this->respond(
            __('guest_checkout.mobile_session_ready'),
            201,
            [
                'guest' => $this->guestPayload($result['identity']),
                'token' => $result['token'],
                'token_type' => 'Bearer',
                'cart' => null,
            ]
        );
    }

    public function rotate(Request $request): JsonResponse
    {
        $result = $this->sessions->rotate($request);

        // §2.1 contract: flat payload (token, token_type, expires_at).
        return $this->respond(
            __('guest_checkout.mobile_session_rotated'),
            200,
            [
                'token' => $result['token'],
                'token_type' => 'Bearer',
                'expires_at' => $this->expiresAt($result['identity']),
            ]
        );
    }

    public function destroy(Request $request): JsonResponse
    {
        $this->sessions->revoke($request);

        return $this->respond(__('guest_checkout.mobile_session_revoked'), 200);
    }

    private function guestPayload(GuestIdentity $identity): array
    {
        return ['expires_at' => $this->expiresAt($identity)];
    }

    private function expiresAt(GuestIdentity $identity): ?string
    {
        return $identity->expires_at?->toIso8601String();
    }

    private function respond(string $message, int $status, array $data = []): JsonResponse
    {
        return $this->apiResponse($message, $status, true, $data)
            ->header('Cache-Control', 'no-store');
    }
}
