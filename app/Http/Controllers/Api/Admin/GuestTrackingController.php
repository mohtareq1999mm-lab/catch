<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Services\Guest\GuestOrderTokenService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Marvel\Database\Models\Order;
use Marvel\Traits\ApiResponse;

/**
 * Admin revocation of guest public tracking access.
 */
class GuestTrackingController extends Controller
{
    use ApiResponse;

    public function __construct(private GuestOrderTokenService $tokens)
    {
    }

    public function revoke(Request $request, int $orderId): JsonResponse
    {
        $order = Order::query()->find($orderId);

        if (!$order) {
            return $this->apiResponse(NOT_FOUND, 404, false);
        }

        $this->tokens->revokeTracking($order, $request->user()?->id);

        return $this->apiResponse(__('guest_checkout.tracking_revoked'), 200, true, [
            'order_id' => $order->id,
            'tracking_revoked' => true,
        ]);
    }
}
