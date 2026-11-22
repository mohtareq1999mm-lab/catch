<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Services\Shipment\CarrierGatewayFactory;
use Illuminate\Http\JsonResponse;
use Marvel\Traits\ApiResponse;

/**
 * Read-only projection of deployment-backed carrier configuration.
 * The factory returns only non-secret status fields.
 */
class ShippingProviderStatusController extends Controller
{
    use ApiResponse;

    public function __construct(private readonly CarrierGatewayFactory $factories) {}

    public function index(): JsonResponse
    {
        return $this->apiResponse(
            __('message.MESSAGE.SHIPPING_PROVIDER_STATUSES_RETRIEVED'),
            200,
            true,
            $this->factories->statuses(),
        );
    }

    public function show(string $provider): JsonResponse
    {
        if (!$this->factories->hasProvider($provider)) {
            return $this->apiResponse(__('message.ERROR.SHIPPING_PROVIDER_NOT_FOUND'), 404, false);
        }

        return $this->apiResponse(
            __('message.MESSAGE.SHIPPING_PROVIDER_STATUS_RETRIEVED'),
            200,
            true,
            $this->factories->status($provider),
        );
    }
}
