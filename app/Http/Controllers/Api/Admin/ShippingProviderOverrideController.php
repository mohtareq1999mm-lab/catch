<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Admin;

use App\Exceptions\CarrierConfigurationException;
use App\Exceptions\ProviderOverrideConflictException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Shipment\OverrideShippingProviderRequest;
use App\Services\Shipment\ProviderAssignmentService;
use App\Services\Shipment\ShipmentService;
use Illuminate\Http\JsonResponse;
use Marvel\Traits\ApiResponse;

/**
 * #148: explicit admin-only override of the config-resolved provider.
 * The route permission is the authorization boundary; the service owns the
 * AWB guard and the canonical activity-log record.
 */
final class ShippingProviderOverrideController extends Controller
{
    use ApiResponse;

    public function __construct(
        private readonly ShipmentService $shipments,
        private readonly ProviderAssignmentService $assignment,
    ) {}

    public function override(OverrideShippingProviderRequest $request, int $id): JsonResponse
    {
        $shipment = $this->shipments->find($id);

        try {
            $updated = $this->assignment->override(
                $shipment,
                (string) $request->validated('provider'),
                (string) $request->validated('reason'),
            );
        } catch (ProviderOverrideConflictException) {
            return $this->apiResponse(
                __('message.ERROR.SHIPPING_PROVIDER_AWB_LOCKED'),
                409,
                false,
            );
        } catch (CarrierConfigurationException) {
            return $this->apiResponse(
                __('message.ERROR.SHIPPING_PROVIDER_UNAVAILABLE'),
                422,
                false,
            );
        }

        return $this->apiResponse(
            __('message.MESSAGE.SHIPPING_PROVIDER_OVERRIDDEN'),
            200,
            true,
            [
                'shipment_id' => $updated->id,
                'provider_code' => $updated->provider_code,
                'service_level' => $updated->provider_service_level,
                'provider_override' => (bool) $updated->provider_override,
            ],
        );
    }
}
