<?php

namespace App\Http\Controllers\Api\Admin\Wms;

use App\Http\Requests\Admin\Wms\ListInventoryOperationsRequest;
use App\Http\Requests\Admin\Wms\ReceiveStockRequest;
use App\Http\Requests\Admin\Wms\TransferStockRequest;
use App\Http\Resources\Wms\InventoryOperationResource;
use App\Http\Resources\Wms\ProductPlacementsResource;
use App\Http\Resources\Wms\StockOperationResource;
use App\Models\Inventory\InventoryOperation;
use App\Services\Inventory\StockOperationService;
use App\Services\Warehouse\WarehouseAccess;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

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
        private WarehouseAccess $access,
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
                isset($data['product_variant_id']) && $data['product_variant_id'] !== null ? (int) $data['product_variant_id'] : null,
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
                isset($data['product_variant_id']) && $data['product_variant_id'] !== null ? (int) $data['product_variant_id'] : null,
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

    /**
     * GET /api/v1/admin/products/{product}/placements
     *
     * Read model: one product with ALL its location placements (the
     * multi-location contract). Catalog data is global; placements are
     * confined to the actor's warehouse scope (global holders may pass
     * ?warehouse_id to narrow). Empty placement set → 200 with [].
     *
     * Variant-aware: each placement carries its SKU identity
     * (product_variant_id NULL = simple row). ?product_variant_id narrows
     * to one SKU; variants expose attributes/SKU/stock via the resource.
     * Backward compat: existing product/placements fields unchanged.
     */
    public function productPlacements(Request $request, int $product): JsonResponse
    {
        try {
            $model = \Marvel\Database\Models\Product::whereKey($product)->with('variations.attributeProducts.attributeValue.attribute')->firstOrFail();
        } catch (ModelNotFoundException $e) {
            throw new \Symfony\Component\HttpKernel\Exception\NotFoundHttpException(__('wms.stock.product_not_found'), $e);
        }

        if (auth()->user()->can('manage-warehouse')) {
            $scope = (int) $request->get('warehouse_id', 0) ?: null;
            if ($scope !== null) {
                $this->authorizeWarehouseScope($scope, 'view-location', true);
            }
        } else {
            $scope = $this->access->warehouseIdFor(auth()->user());
            if ($scope === null) {
                throw new AuthorizationException('Forbidden warehouse operation.');
            }
        }

        $variantFilter = $request->get('product_variant_id', null);
        $variantFilter = $variantFilter !== null && $variantFilter !== '' ? (int) $variantFilter : null;
        if ($variantFilter !== null) {
            // M5: missing variant → 404 (variant_not_found), wrong-parent →
            // 422 (variant_mismatch). Previously both mapped to 422.
            $variant = \Marvel\Database\Models\ProductVariant::whereKey($variantFilter)->first();
            if (!$variant) {
                throw new \Symfony\Component\HttpKernel\Exception\NotFoundHttpException(__('wms.stock.variant_not_found'));
            }
            if ((int) $variant->product_id !== (int) $model->id) {
                $this->unprocessable(__('wms.stock.variant_mismatch', ['variant' => $variantFilter, 'product' => $model->id]));
            }
        }

        $query = \App\Models\Fulfillment\ProductLocation::query()
            ->where('product_id', $model->id)
            ->with(['location', 'variant.attributeProducts.attributeValue.attribute'])
            ->orderBy('id');
        if ($scope !== null) {
            $query->where('warehouse_id', $scope);
        }
        if ($variantFilter !== null) {
            $query->where('product_variant_id', $variantFilter);
        }

        return $this->apiResponse(
            __('wms.stock.placements_fetched'),
            200,
            true,
            (new ProductPlacementsResource([
                'product' => $model,
                'placements' => $query->get(),
            ]))->toArray($request)
        );
    }

    /**
     * GET /api/v1/admin/inventory/overview
     *
     * SKU-level stats without double-counting parent aggregates:
     * - total_sku_units: SUM(stock_quantity) over stock-owning entities only
     *   (simple products WITHOUT variants + every variant row). Variable
     *   parents are excluded (their stock lives on variants).
     * - active_bins: COUNT(active locations) in scope.
     * - today_transactions: COUNT(completed inventory_operations TODAY,
     *   app timezone) in scope.
     *
     * Scope (M4 — explicit): stock totals are GLOBAL authority sums (products
     * and variants carry no warehouse column; placement hints are NOT summed
     * because hints drift from authority by design). Bins/transactions ARE
     * warehouse-scoped. totals_scope is always 'global' so scoped callers
     * cannot mistake the totals for per-warehouse levels.
     *
     * Scope: actor warehouse unless manage-warehouse (+ optional
     * ?warehouse_id narrow, 404 anti-enumeration on foreign scope).
     */
    public function overview(Request $request): JsonResponse
    {
        if (auth()->user()->can('manage-warehouse')) {
            $scope = (int) $request->get('warehouse_id', 0) ?: null;
            if ($scope !== null) {
                $this->authorizeWarehouseScope($scope, 'view-location', true);
            }
        } else {
            $scope = $this->access->warehouseIdFor(auth()->user());
            if ($scope === null) {
                throw new AuthorizationException('Forbidden warehouse operation.');
            }
        }

        $simpleUnits = \Marvel\Database\Models\Product::query()
            ->whereDoesntHave('variations')
            ->sum('stock_quantity');

        $variantUnits = \Marvel\Database\Models\ProductVariant::query()->sum('stock_quantity');

        $binsQuery = \App\Models\Fulfillment\Location::query()->where('status', \App\Models\Fulfillment\Location::STATUS_ACTIVE);
        if ($scope !== null) {
            $binsQuery->where('warehouse_id', $scope);
        }

        $txQuery = \App\Models\Inventory\InventoryOperation::query()->where('status', \App\Models\Inventory\InventoryOperation::STATUS_COMPLETED)
            ->whereDate('created_at', today());
        if ($scope !== null) {
            $txQuery->where('warehouse_id', $scope);
        }

        return $this->apiResponse(
            __('wms.stock.overview_fetched'),
            200,
            true,
            [
                'total_sku_units' => (int) $simpleUnits + (int) $variantUnits,
                'simple_units' => (int) $simpleUnits,
                'variant_units' => (int) $variantUnits,
                'totals_scope' => 'global',
                'active_bins' => (int) (clone $binsQuery)->count(),
                'today_transactions' => (int) (clone $txQuery)->count(),
                'warehouse_id' => $scope,
            ]
        );
    }

    /**
     * GET /api/v1/admin/inventory/operations
     *
     * List and filter ledger operations (receipts, transfers, initial placements).
     * Scoped to actor's warehouse unless user has manage-warehouse.
     */
    public function operations(ListInventoryOperationsRequest $request): JsonResponse
    {
        $query = InventoryOperation::query()
            ->with([
                'product:id,name,sku',
                'productVariant:id,sku,title',
                'warehouse:id,name,code',
                'sourceLocation:id,code,name',
                'destinationLocation:id,code,name',
            ])
            ->orderByDesc('id');

        if (auth()->user()->can('manage-warehouse')) {
            $scope = (int) $request->validated('warehouse_id', 0) ?: null;
            if ($scope !== null) {
                $this->authorizeWarehouseScope($scope, 'view-location', true);
                $query->where('warehouse_id', $scope);
            }
        } else {
            $scope = $this->access->warehouseIdFor(auth()->user());
            if ($scope === null) {
                throw new AuthorizationException('Forbidden warehouse operation.');
            }
            $requestedScope = (int) $request->validated('warehouse_id', 0) ?: null;
            if ($requestedScope !== null && $requestedScope !== $scope) {
                $this->authorizeWarehouseScope($requestedScope, 'view-location', true);
            }
            $query->where('warehouse_id', $scope);
        }

        if ($request->filled('operation')) {
            $query->where('operation', $request->validated('operation'));
        }

        if ($request->filled('status')) {
            $query->where('status', $request->validated('status'));
        }

        if ($request->filled('product_id')) {
            $query->where('product_id', $request->validated('product_id'));
        }

        if ($request->filled('product_variant_id')) {
            $query->where('product_variant_id', $request->validated('product_variant_id'));
        }

        if ($request->filled('source_location_id')) {
            $query->where('source_location_id', $request->validated('source_location_id'));
        }

        if ($request->filled('destination_location_id')) {
            $query->where('destination_location_id', $request->validated('destination_location_id'));
        }

        if ($request->filled('reference')) {
            $query->where('reference', 'like', '%' . $request->validated('reference') . '%');
        }

        if ($request->filled('date_from')) {
            $query->whereDate('created_at', '>=', $request->validated('date_from'));
        }

        if ($request->filled('date_to')) {
            $query->whereDate('created_at', '<=', $request->validated('date_to'));
        }

        $perPage = min((int) ($request->validated('limit') ?? 15), 100);

        return $this->apiResponse(
            __('wms.stock.operations_fetched'),
            200,
            true,
            InventoryOperationResource::collection($query->paginate($perPage))
        );
    }

    /**
     * GET /api/v1/admin/inventory/operations/{key}
     *
     * Case-C resolution: after a timeout or lost response on a keyed
     * receipt/transfer, the client fetches the key to learn whether the
     * operation committed (completed + stored result), is still running
     * (processing → wait and re-poll, do NOT start a second attempt
     * elsewhere), or never arrived / rolled back (404 → safe to retry,
     * preferably with the same key and the identical payload).
     *
     * Failed attempts leave no ledger row (the ledger write shares the
     * operation transaction, so a 422 refusal rolls it back): 404 also
     * means "conclusively failed without committing", after which the
     * frontend may submit a new attempt — ideally with a fresh key per
     * destination so each attempt stays individually auditable.
     *
     * Read-only (permission:view-location); warehouse-scoped like the
     * placements read (404 anti-enumeration for foreign rows).
     */
    public function operationStatus(string $key): JsonResponse
    {
        $key = trim($key);

        $operation = $key === ''
            ? null
            : \App\Models\Inventory\InventoryOperation::query()
                ->where('idempotency_key', $key)
                ->first();

        if (!$operation) {
            throw new \Symfony\Component\HttpKernel\Exception\NotFoundHttpException(__('wms.stock.operation_not_found'));
        }

        if (!auth()->user()->can('manage-warehouse')) {
            $home = $this->access->warehouseIdFor(auth()->user());
            if ($home === null || (int) ($operation->warehouse_id ?? 0) !== $home) {
                throw new \Symfony\Component\HttpKernel\Exception\NotFoundHttpException(__('wms.stock.operation_not_found'));
            }
        }

        $operation->loadMissing([
            'product:id,name,sku,product_type',
            'productVariant.attributeProducts.attributeValue.attribute',
            'warehouse:id,name,code',
            'sourceLocation:id,code,name',
            'destinationLocation:id,code,name',
        ]);

        return $this->apiResponse(
            __('wms.stock.operation_fetched'),
            200,
            true,
            (new InventoryOperationResource($operation))->toArray(request())
        );
    }
}
