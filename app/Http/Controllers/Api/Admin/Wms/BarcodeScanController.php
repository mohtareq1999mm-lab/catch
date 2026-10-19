<?php

namespace App\Http\Controllers\Api\Admin\Wms;

use App\Http\Requests\Admin\Wms\ScanBarcodeRequest;
use App\Http\Resources\Wms\BarcodeScanResource;
use App\Services\Warehouse\BarcodeScanService;
use App\Services\Warehouse\WarehouseAccess;
use Illuminate\Http\JsonResponse;
use Marvel\Enums\Permission;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class BarcodeScanController extends WmsAdminController
{
    public function __construct(
        private BarcodeScanService $scanner,
        private WarehouseAccess $access,
    ) {
        $this->middleware('permission:' . Permission::BARCODE_SCAN, ['only' => ['scan']]);
    }

    /**
     * Resolve barcode with optional type and warehouse context.
     *
     * POST|GET /api/v1/admin/barcode/scan
     */
    public function scan(ScanBarcodeRequest $request): JsonResponse
    {
        $code = (string) $request->validated('code');
        $type = $request->validated('type');
        $requestedWarehouseId = $request->validated('warehouse_id') !== null
            ? (int) $request->validated('warehouse_id')
            : null;

        $targetWarehouseId = null;
        if (auth()->user()?->can('manage-warehouse')) {
            $targetWarehouseId = $requestedWarehouseId;
        } else {
            $home = $this->access->warehouseIdFor(auth()->user());
            if ($requestedWarehouseId !== null && $requestedWarehouseId !== $home) {
                $this->authorizeWarehouseScope($requestedWarehouseId, 'barcode.scan', true);
            }
            $targetWarehouseId = $home;
        }

        $result = $this->scanner->scan($code, $type, $targetWarehouseId);

        if ($result === null) {
            throw new NotFoundHttpException(__('wms.barcode.unknown', ['code' => $code]));
        }

        return $this->apiResponse(
            __('wms.barcode.scanned'),
            200,
            true,
            BarcodeScanResource::make($result)
        );
    }
}
