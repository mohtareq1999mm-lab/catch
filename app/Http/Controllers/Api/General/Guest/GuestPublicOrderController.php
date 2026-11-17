<?php

namespace App\Http\Controllers\Api\General\Guest;

use App\Exceptions\GuestCheckoutConflictException;
use App\Http\Controllers\Controller;
use App\Services\Guest\GuestCancellationOtpService;
use App\Services\Guest\GuestCancellationService;
use App\Services\Guest\GuestTrackingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Marvel\Traits\ApiResponse;

/**
 * Public (token-authenticated) guest order surfaces.
 *
 * Tokens are accepted in the POST body — never in the URL — so web-server /
 * proxy access logs cannot capture the credential.
 */
class GuestPublicOrderController extends Controller
{
    use ApiResponse;

    public function __construct(
        private GuestTrackingService $tracking,
        private GuestCancellationService $cancellation,
        private GuestCancellationOtpService $cancellationOtp,
    ) {
    }

    public function track(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'token' => ['required', 'string', 'max:200'],
        ]);

        try {
            $payload = $this->tracking->track($validated['token']);

            return $this->apiResponse(__('guest_checkout.tracking_retrieved'), 200, true, $payload);
        } catch (GuestCheckoutConflictException $e) {
            return $this->apiResponse($e->getMessage(), $e->httpStatus(), false);
        }
    }

    public function cancel(Request $request): JsonResponse
    {
        // Two-step cancellation, step 2 (design D6): the confirm endpoint
        // requires the Cancellation Token + the OTP identifier from the
        // request step + the code. This is the disclosed breaking change
        // of the token-only contract; the response shape stays identical.
        // otp_id stays required even on the static-bypass path (D4): the
        // LocalGateway pins its id and the provider path needs it.
        $validated = $request->validate([
            'token' => ['required', 'string', 'max:200'],
            'otp_id' => ['required', 'string', 'max:200'],
            'code' => ['required', 'string', 'max:120'],
        ]);

        try {
            // Verification is a gate IN FRONT of the canonical
            // cancellation authority: no events and no used-mark here —
            // the canonical flow below stays the sole authority.
            $this->cancellationOtp->verify(
                $validated['token'],
                $validated['otp_id'],
                $validated['code'],
            );

            $order = $this->cancellation->cancel($validated['token']);

            return $this->apiResponse(__('checkout.order_cancelled_successfully'), 200, true, [
                'order' => [
                    'order_number' => $order->order_number,
                    'status' => $order->status,
                ],
            ]);
        } catch (GuestCheckoutConflictException $e) {
            return $this->apiResponse($e->getMessage(), $e->httpStatus(), false);
        } catch (\RuntimeException $e) {
            report($e);

            return $this->apiResponse(__('guest_checkout.cancel_failed'), 422, false);
        }
    }

    /**
     * Cancellation step 1 (design D1/D2/D5): dispatch the Cancellation OTP
     * to the phone recorded on the token-resolved order. The token is the
     * sole credential and is NOT consumed by this step.
     */
    public function requestCancellationOtp(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'token' => ['required', 'string', 'max:200'],
        ]);

        try {
            $payload = $this->cancellationOtp->request($validated['token']);

            return $this->apiResponse(__('guest_checkout.cancel_otp_sent'), 200, true, $payload);
        } catch (GuestCheckoutConflictException $e) {
            return $this->apiResponse($e->getMessage(), $e->httpStatus(), false);
        }
    }
}
