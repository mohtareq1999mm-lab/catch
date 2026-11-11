<?php

namespace App\Http\Controllers\Api\General\Guest;

use App\Exceptions\GuestCheckoutConflictException;
use App\Http\Controllers\Controller;
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
        $validated = $request->validate([
            'token' => ['required', 'string', 'max:200'],
        ]);

        try {
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
}
