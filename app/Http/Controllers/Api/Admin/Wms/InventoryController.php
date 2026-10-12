<?php

namespace App\Http\Controllers\Api\Admin\Wms;

use App\Http\Requests\Admin\Wms\ReceiveStockRequest;
use App\Http\Requests\Admin\Wms\TransferStockRequest;
use App\Http\Resources\Wms\StockOperationResource;
use App\Services\Inventory\StockOperationService;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;

/**
 * Inventory intake + relocation HTTP adapter.
 *
 * Thin adapter only (per WmsAdminController): warehouse scope enforcement
 * and canonical error mapping. Transactions, locks, and domain transitions
 * belong to StockOperationService.
 *
 * Replay convention (mirrors FulfillmentController): a completed
 * idempotency key returns 200 with the stored result; a first execution
 * returns 201. A lost duplicate-key race reloads the winner when completed.
 */
class InventoryController extends WmsAdminController
{
    public function __construct(
        private StockOperationService $stock,
    ) {}

    /**
     * POST /api/v1/admin/locations/{location}/stock-receipts
     */
    public function receive(ReceiveStockRequest $request, int $location): JsonResponse
    {
        $data = $request->validated();

        try {
            $warehouseId = $this->stock->resolvePlaceableLocation($location)[1];
        } catch (ModelNotFoundException $e) {
            throw new \Symfony\Component\HttpKernel\Exception\NotFoundHttpException(__('wms.stock.location_not_found'), $e);
        } catch (\InvalidArgumentException $e) {
            $this->unprocessable($e->getMessage());
        }

        $this->authorizeWarehouseScope($warehouseId, 'inventory-adjust');

        try {
            $outcome = $this->stock->receiveStock(
                (int) $data['product_id'],
                $location,
                (int) $data['quantity'],
                $data['reference'] ?? null,
                $data['idempotency_key'] ?? null,
            );
        } catch (ModelNotFoundException $e) {
            throw new \Symfony\Component\HttpKernel\Exception\NotFoundHttpException(__('wms.stock.product_not_found'), $e);
        } catch (\InvalidArgumentException $e) {
            $this->unprocessable($e->getMessage());
        }

        return $this->apiResponse(
            $outcome['replayed'] ? __('wms.stock.receipt_replay') : __('wms.stock.receipt_ok'),
            $outcome['replayed'] ? 200 : 201,
            true,
            [
                'operation' => (new StockOperationResource($outcome['result']))->toArray($request),
                'replayed' => $outcome['replayed'],
            ]
        );
    }

    /**
     * POST /api/v1/admin/stock-transfers
     */
    public function transfer(TransferStockRequest $request): JsonResponse
    {
        $data = $request->validated();
        $sourceId = (int) $data['source_location_id'];
        $destinationId = (int) $data['destination_location_id'];

        try {
            $sourceWarehouseId = $this->stock->resolvePlaceableLocation($sourceId)[1];
            $destinationWarehouseId = $this->stock->resolvePlaceableLocation($destinationId)[1];
        } catch (ModelNotFoundException $e) {
            throw new \Symfony\Component\HttpKernel\Exception\NotFoundHttpException(__('wms.stock.location_not_found'), $e);
        } catch (\InvalidArgumentException $e) {
            $this->unprocessable($e->getMessage());
        }

        // Same-warehouse rule is enforced in the service; scope-check the
        // source warehouse here so cross-warehouse probes cannot enumerate
        // foreign locations (403 before the 422 business refusal).
        $this->authorizeWarehouseScope($sourceWarehouseId, 'inventory-adjust');
        $this->authorizeWarehouseScope($destinationWarehouseId, 'inventory-adjust');

        try {
            $outcome = $this->stock->transferStock(
                (int) $data['product_id'],
                $sourceId,
                $destinationId,
                (int) $data['quantity'],
                $data['reference'] ?? null,
                $data['idempotency_key'] ?? null,
            );
        } catch (ModelNotFoundException $e) {
            throw new \Symfony\Component\HttpKernel\Exception\NotFoundHttpException(__('wms.stock.product_not_found'), $e);
        } catch (\InvalidArgumentException $e) {
            $this->unprocessable($e->getMessage());
        }

        return $this->apiResponse(
            $outcome['replayed'] ? __('wms.stock.transfer_replay') : __('wms.stock.transfer_ok'),
            $outcome['replayed'] ? 200 : 201,
            true,
            [
                'operation' => (new StockOperationResource($outcome['result']))->toArray($request),
                'replayed' => $outcome['replayed'],
            ]
        );
    }
}
