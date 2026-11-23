<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Admin;

use App\Exceptions\CarrierConfigurationException;
use App\Exceptions\ProviderOverrideConflictException;
use App\Http\Controllers\Api\Admin\Wms\WmsAdminController;
use App\Http\Requests\Shipment\OverrideShippingProviderRequest;
use App\Http\Resources\Shipment\ProviderOverrideResource;
use App\Models\Fulfillment\Fulfillment;
use App\Models\Shipment;
use App\Services\Shipment\ProviderAssignmentService;
use App\Services\Shipment\ShipmentService;
use Illuminate\Http\JsonResponse;
use Marvel\Enums\Permission;

/**
 * #148: explicit authorized override of the config-resolved provider on the
 * admin API. Route permission is the coarse gate; WmsAdminController adds
 * object-level warehouse scope. The service owns the AWB guard and audit log.
 */
final class ShippingProviderOverrideController extends WmsAdminController
{
    public function __construct(
        private readonly ShipmentService $shipments,
        private readonly ProviderAssignmentService $assignment,
    ) {}

    public function override(OverrideShippingProviderRequest $request, int $id): JsonResponse
    {
        $shipment = $this->shipments->find($id);
        $this->authorizeWarehouseScope(
            $this->warehouseIdFor($shipment),
            Permission::SHIPPING_PROVIDER_OVERRIDE,
        );

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
            ProviderOverrideResource::make($updated),
        );
    }

    /**
     * Order-only labels have no warehouse scope: null fails closed in
     * WarehouseAccess (only manage-warehouse holders cross that boundary).
     * A dangling fulfillment id likewise resolves to null rather than a 404,
     * so write denials stay 403 and no object existence is disclosed.
     */
    private function warehouseIdFor(Shipment $shipment): ?int
    {
        if ($shipment->fulfillment_id === null) {
            return null;
        }

        $warehouseId = Fulfillment::find((int) $shipment->fulfillment_id)?->warehouse_id;

        return $warehouseId === null ? null : (int) $warehouseId;
    }
}
