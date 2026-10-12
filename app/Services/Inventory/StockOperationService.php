<?php

namespace App\Services\Inventory;

use App\Audit\ActivityAuditService;
use App\Models\Fulfillment\Location;
use App\Models\Fulfillment\ProductLocation;
use App\Models\Fulfillment\Warehouse;
use App\Models\Inventory\InventoryOperation;
use App\Services\Fulfillment\ProductLocationService;
use App\Services\Warehouse\WarehouseAccess;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Marvel\Database\Models\Product;
use Marvel\Enums\ItemType;
use Marvel\Enums\ProductType;

/**
 * Single domain authority for WMS stock intake and relocation.
 *
 * Hard invariants (mirror the existing authorities, never bypass them):
 * - products.stock_quantity is the ONLY writable stock authority. Placement
 *   rows (product_locations) are non-authoritative hints (see
 *   ProductLocationService: MONITOR-only sync, allocation hints only).
 * - Reservations live centrally (reserved_quantity on the product row) and
 *   are owned exclusively by OrderReservationService — never touched here.
 * - Receiving ADDS to the authority; transfer NEVER changes the authority;
 *   initial placement ASSIGNS the creation quantity exactly once.
 * - Every mutation runs inside one transaction with row locks held in a
 *   deterministic order (product, then placements by id).
 */
class StockOperationService
{
    public function __construct(
        private ProductLocationService $placements,
        private WarehouseAccess $access,
    ) {}

    /**
     * Resolve and validate a placeable location plus its warehouse scope.
     *
     * Warehouse derivation rule: an explicit warehouse_id must equal the
     * location's own warehouse (mismatch is rejected); when omitted the
     * warehouse is derived from the authoritative location row.
     *
     * @return array{0: Location, 1: int}
     *
     * @throws \Illuminate\Database\Eloquent\ModelNotFoundException
     * @throws \InvalidArgumentException
     */
    public function resolvePlaceableLocation(int $locationId, ?int $warehouseId = null): array
    {
        $location = Location::whereKey($locationId)->firstOrFail();

        if ($location->status !== Location::STATUS_ACTIVE) {
            throw new \InvalidArgumentException(
                __('wms.stock.location_inactive', ['id' => $locationId])
            );
        }

        $type = $location->type;
        if ($type !== null && !in_array($type, Location::PLACEABLE_TYPES, true)) {
            throw new \InvalidArgumentException(
                __('wms.stock.location_unplaceable', ['id' => $locationId, 'type' => $type])
            );
        }

        // Warehouse row must exist and not be soft-deleted (firstOrFail
        // applies the SoftDeletes global scope, mirroring LocationController).
        $warehouse = Warehouse::whereKey($location->warehouse_id)->firstOrFail();

        if ($warehouseId !== null && (int) $warehouseId !== (int) $warehouse->id) {
            throw new \InvalidArgumentException(
                __('wms.stock.location_warehouse', ['id' => $locationId, 'warehouse' => $warehouse->id, 'expected' => $warehouseId])
            );
        }

        return [$location, (int) $warehouse->id];
    }

    /**
     * @throws \Illuminate\Auth\Access\AuthorizationException (renders 403)
     */
    public function authorizeStockOperation(mixed $user, int $warehouseId): void
    {
        $this->access->denyUnless($user, $warehouseId, 'inventory-adjust');
    }

    /**
     * Product-level stock operations are representable only for simple,
     * physical products: variants own their own stock rows and placements
     * carry no variant dimension; digital lines hold no physical stock (D1).
     *
     * @throws \InvalidArgumentException
     */
    public function assertReceivableProduct(Product $product): void
    {
        if (($product->product_type ?? null) !== null
            && (string) $product->product_type !== (string) ProductType::SIMPLE
            && $product->variations()->exists()
        ) {
            throw new \InvalidArgumentException(
                __('wms.stock.product_variants', ['id' => $product->id])
            );
        }

        if (($product->item_type ?? ItemType::PHYSICAL) === ItemType::DIGITAL) {
            throw new \InvalidArgumentException(
                __('wms.stock.product_digital', ['id' => $product->id])
            );
        }
    }

    /**
     * Distribute a freshly created product's creation quantity to a location.
     *
     * Must run inside the caller's (product creation) transaction: the
     * location row is re-locked and re-validated here so a concurrent
     * deactivation between the controller pre-check and this write fails
     * closed instead of placing into a dead location.
     *
     * Single-write rule: the authority is ASSIGNED (not incremented) from
     * the creation quantity, and the placement hint is set to the same
     * amount — the initial units enter total stock exactly once.
     */
    public function placeInitialStock(Product $product, int $locationId, ?int $warehouseId, int $quantity): ProductLocation
    {
        $this->assertPositiveQuantity($quantity);

        return $this->runInTransaction(function () use ($product, $locationId, $warehouseId, $quantity) {
            [$location, $warehouseId] = $this->lockedPlaceableLocation($locationId, $warehouseId);
            $this->assertReceivableProduct($product);

            $locked = Product::query()->whereKey($product->id)->lockForUpdate()->firstOrFail();
            $locked->forceFill([
                'stock_quantity' => $quantity,
                'in_stock' => ($quantity - (int) ($locked->reserved_quantity ?? 0)) > 0,
            ])->save();

            $placement = $this->lockedPlacement($product->id, $location->id, $warehouseId);
            $this->placements->updateLocationQuantity($placement->id, (float) $quantity, 'initial_placement');

            ActivityAuditService::recordModel(
                $locked,
                'stock.placed',
                'inventory',
                __('activity.stock_placed', ['id' => $product->id]),
                new: [
                    'stock_quantity' => $quantity,
                    'warehouse_id' => $warehouseId,
                    'location_id' => $location->id,
                    'placement_quantity' => $quantity,
                ],
            );

            return $placement->fresh();
        });
    }

    /**
     * Receive new physical stock: authority += qty, placement += qty.
     *
     * @return array{result: array<string, mixed>, replayed: bool}
     */
    public function receiveStock(
        int $productId,
        int $locationId,
        int $quantity,
        ?string $reference = null,
        ?string $idempotencyKey = null,
    ): array {
        $product = Product::query()->findOrFail($productId);
        $this->assertReceivableProduct($product);
        $this->assertPositiveQuantity($quantity);
        [, $warehouseId] = $this->resolvePlaceableLocation($locationId);

        return $this->idempotent(
            $idempotencyKey,
            InventoryOperation::OPERATION_RECEIPT,
            [
                'product_id' => $product->id,
                'warehouse_id' => $warehouseId,
                'destination_location_id' => $locationId,
                'quantity' => $quantity,
                'reference' => $reference,
            ],
            function () use ($product, $locationId, $warehouseId, $quantity, $reference) {
                $locked = Product::query()->whereKey($product->id)->lockForUpdate()->firstOrFail();
                $oldStock = (int) ($locked->stock_quantity ?? 0);

                $locked->forceFill([
                    'stock_quantity' => $oldStock + $quantity,
                    'in_stock' => (($oldStock + $quantity) - (int) ($locked->reserved_quantity ?? 0)) > 0,
                ])->save();

                [$location] = $this->lockedPlaceableLocation($locationId, $warehouseId);
                $placement = $this->lockedPlacement($product->id, $location->id, $warehouseId);
                $this->placements->updateLocationQuantity($placement->id, (float) $quantity, 'stock_receipt');

                $result = $this->stockSnapshot($locked->fresh(), $warehouseId, $placement->fresh(), $quantity, $reference);

                ActivityAuditService::recordModel(
                    $locked,
                    'stock.received',
                    'inventory',
                    __('activity.stock_received', ['id' => $product->id]),
                    old: ['stock_quantity' => $oldStock],
                    new: $result,
                );

                return $result;
            }
        );
    }

    /**
     * Relocate existing stock: source -= qty, destination += qty, authority
     * provably unchanged (captured before/after and compared).
     *
     * Same-warehouse only (mirrors ReturnService cross-warehouse restock
     * integrity): anything else is an explicit 422, never a silent cross
     * posting.
     *
     * @return array{result: array<string, mixed>, replayed: bool}
     */
    public function transferStock(
        int $productId,
        int $sourceLocationId,
        int $destinationLocationId,
        int $quantity,
        ?string $reference = null,
        ?string $idempotencyKey = null,
    ): array {
        if ($sourceLocationId === $destinationLocationId) {
            throw new \InvalidArgumentException(__('wms.stock.transfer_same'));
        }

        $product = Product::query()->findOrFail($productId);
        $this->assertReceivableProduct($product);
        $this->assertPositiveQuantity($quantity);

        [, $sourceWarehouseId] = $this->resolvePlaceableLocation($sourceLocationId);
        [, $destinationWarehouseId] = $this->resolvePlaceableLocation($destinationLocationId);

        if ($sourceWarehouseId !== $destinationWarehouseId) {
            throw new \InvalidArgumentException(
                __('wms.stock.transfer_cross')
            );
        }

        return $this->idempotent(
            $idempotencyKey,
            InventoryOperation::OPERATION_TRANSFER,
            [
                'product_id' => $product->id,
                'warehouse_id' => $sourceWarehouseId,
                'source_location_id' => $sourceLocationId,
                'destination_location_id' => $destinationLocationId,
                'quantity' => $quantity,
                'reference' => $reference,
            ],
            function () use ($product, $sourceLocationId, $destinationLocationId, $sourceWarehouseId, $quantity, $reference) {
                $locked = Product::query()->whereKey($product->id)->lockForUpdate()->firstOrFail();
                $stockBefore = (int) ($locked->stock_quantity ?? 0);

                // Deterministic lock order (by placement id) against deadlocks.
                // The source row must already exist: a transfer never creates
                // stock, so a missing source fails closed WITHOUT writing a
                // zero-quantity row.
                $source = ProductLocation::query()
                    ->where('product_id', $product->id)
                    ->where('location_id', $sourceLocationId)
                    ->first();

                if (!$source) {
                    throw new \InvalidArgumentException(
                        __('wms.stock.no_stock', ['product' => $product->id, 'location' => $sourceLocationId])
                    );
                }

                if ((int) $source->warehouse_id !== $sourceWarehouseId) {
                    throw new \InvalidArgumentException(
                        __('wms.stock.already_placed', ['product' => $product->id, 'location' => $sourceLocationId, 'warehouse' => $source->warehouse_id])
                    );
                }

                $destination = $this->lockedPlacement($product->id, $destinationLocationId, $sourceWarehouseId);
                [$first, $second] = $source->id <= $destination->id
                    ? [$source, $destination]
                    : [$destination, $source];
                $first = ProductLocation::query()->whereKey($first->id)->lockForUpdate()->firstOrFail();
                $second = ProductLocation::query()->whereKey($second->id)->lockForUpdate()->firstOrFail();
                $source = $first->id === $source->id ? $first : $second;
                $destination = $first->id === $destination->id ? $first : $second;

                $available = (float) $source->quantity - (float) $source->allocated_hint;
                if ($available < $quantity) {
                    throw new \InvalidArgumentException(
                        __('wms.stock.insufficient', ['location' => $sourceLocationId, 'available' => $available, 'quantity' => $quantity])
                    );
                }

                $this->placements->updateLocationQuantity($source->id, -(float) $quantity, 'stock_transfer_out');
                $this->placements->updateLocationQuantity($destination->id, (float) $quantity, 'stock_transfer_in');

                $stockAfter = (int) $locked->fresh()->stock_quantity;
                if ($stockAfter !== $stockBefore) {
                    throw new \RuntimeException(__('wms.stock.transfer_altered'));
                }

                $result = [
                    'product_id' => $product->id,
                    'warehouse_id' => $sourceWarehouseId,
                    'transferred_quantity' => $quantity,
                    'stock_quantity' => $stockAfter,
                    'reserved_quantity' => (int) ($locked->reserved_quantity ?? 0),
                    'source_placement' => $this->placementSnapshot($source->fresh()),
                    'destination_placement' => $this->placementSnapshot($destination->fresh()),
                    'reference' => $reference,
                ];

                ActivityAuditService::recordModel(
                    $locked,
                    'stock.transferred',
                    'inventory',
                    __('activity.stock_transferred', ['id' => $product->id]),
                    old: ['stock_quantity' => $stockBefore],
                    new: $result,
                );

                return $result;
            }
        );
    }

    /**
     * Idempotency shell: UNIQUE(idempotency_key) is the final backstop.
     * Completed keys replay their stored result; a duplicate insert that
     * loses the race reloads the winner when completed, else 409s.
     * Keyless calls execute once with no ledger row.
     *
     * @return array{result: array<string, mixed>, replayed: bool}
     */
    private function idempotent(?string $key, string $operation, array $attributes, \Closure $work): array
    {
        $key = $key !== null ? trim($key) : null;

        if ($key === null || $key === '') {
            return ['result' => $this->runInTransaction($work), 'replayed' => false];
        }

        $completed = InventoryOperation::query()
            ->where('idempotency_key', $key)
            ->where('status', InventoryOperation::STATUS_COMPLETED)
            ->first();

        if ($completed) {
            return ['result' => $completed->result ?? [], 'replayed' => true];
        }

        try {
            return DB::transaction(function () use ($key, $operation, $attributes, $work) {
                InventoryOperation::query()->create(array_merge($attributes, [
                    'idempotency_key' => $key,
                    'operation' => $operation,
                    'status' => InventoryOperation::STATUS_PROCESSING,
                ]));

                $result = $work();

                InventoryOperation::query()->where('idempotency_key', $key)->update([
                    'status' => InventoryOperation::STATUS_COMPLETED,
                    'result' => $result,
                ]);

                return ['result' => $result, 'replayed' => false];
            });
        } catch (QueryException $e) {
            // Narrow race fallback ONLY: our key insert collided with a
            // concurrent writer. Completed winner replays; anything else
            // (processing owner, other failures) is rethrown, never faked.
            if (!$this->isDuplicateKey($e)) {
                throw $e;
            }

            $winner = InventoryOperation::query()->where('idempotency_key', $key)->first();

            if ($winner && $winner->status === InventoryOperation::STATUS_COMPLETED) {
                return ['result' => $winner->result ?? [], 'replayed' => true];
            }

            throw $e;
        }
    }

    /**
     * Service-level floor (request validation guarantees this first; the
     * service stays safe when called directly).
     *
     * @throws \InvalidArgumentException
     */
    private function assertPositiveQuantity(int $quantity): void
    {
        if ($quantity < 1) {
            throw new \InvalidArgumentException(__('message.QUANTITY_MINIMUM'));
        }
    }

    /**
     * Lock + re-validate a location inside the write transaction (TOCTOU
     * guard for the controller pre-check).
     *
     * @return array{0: Location, 1: int}
     */
    private function lockedPlaceableLocation(int $locationId, ?int $warehouseId): array
    {
        $location = Location::query()->whereKey($locationId)->lockForUpdate()->firstOrFail();

        if ($location->status !== Location::STATUS_ACTIVE) {
            throw new \InvalidArgumentException(__('wms.stock.location_inactive', ['id' => $locationId]));
        }

        if ($location->type !== null && !in_array($location->type, Location::PLACEABLE_TYPES, true)) {
            throw new \InvalidArgumentException(__('wms.stock.location_unplaceable', ['id' => $locationId, 'type' => $location->type]));
        }

        $warehouse = Warehouse::whereKey($location->warehouse_id)->firstOrFail();

        if ($warehouseId !== null && (int) $warehouseId !== (int) $warehouse->id) {
            throw new \InvalidArgumentException(
                __('wms.stock.location_warehouse', ['id' => $locationId, 'warehouse' => $warehouse->id, 'expected' => $warehouseId])
            );
        }

        return [$location, (int) $warehouse->id];
    }

    /**
     * Fetch-or-create the placement row, race-safe on the UNIQUE
     * (product_id, location_id) backstop, then return it locked.
     */
    private function lockedPlacement(int $productId, int $locationId, int $warehouseId): ProductLocation
    {
        try {
            $placement = ProductLocation::query()->firstOrCreate(
                ['product_id' => $productId, 'location_id' => $locationId],
                ['warehouse_id' => $warehouseId, 'quantity' => 0, 'allocated_hint' => 0],
            );
        } catch (QueryException $e) {
            if (!$this->isDuplicateKey($e)) {
                throw $e;
            }

            $placement = ProductLocation::query()
                ->where('product_id', $productId)
                ->where('location_id', $locationId)
                ->firstOrFail();
        }

        // Denorm guard: an existing row pinned to another warehouse means
        // corrupt history — fail closed, never silently re-home it.
        if ((int) $placement->warehouse_id !== $warehouseId) {
            throw new \InvalidArgumentException(
                __('wms.stock.already_placed', ['product' => $productId, 'location' => $locationId, 'warehouse' => $placement->warehouse_id])
            );
        }

        return ProductLocation::query()->whereKey($placement->id)->lockForUpdate()->firstOrFail();
    }

    /**
     * Compose with the caller's transaction when present (same pattern as
     * OrderReservationService::run); stay atomic alone otherwise.
     */
    private function runInTransaction(\Closure $work): mixed
    {
        if (DB::transactionLevel() > 0) {
            return $work();
        }

        return DB::transaction($work);
    }

    /**
     * Duplicate-key detector. MySQL error 1062 only — anything else is
     * rethrown, never replayed (mirrors FulfillmentController).
     */
    private function isDuplicateKey(QueryException $e): bool
    {
        $info = $e->errorInfo ?? null;

        return is_array($info) && ($info[1] ?? null) === 1062;
    }

    /**
     * @return array<string, mixed>
     */
    private function stockSnapshot(Product $product, int $warehouseId, ProductLocation $placement, int $moved, ?string $reference): array
    {
        $stock = (int) ($product->stock_quantity ?? 0);
        $reserved = (int) ($product->reserved_quantity ?? 0);

        return [
            'product_id' => $product->id,
            'warehouse_id' => $warehouseId,
            'received_quantity' => $moved,
            'stock_quantity' => $stock,
            'reserved_quantity' => $reserved,
            'available_quantity' => max(0, $stock - $reserved),
            'in_stock' => (bool) $product->in_stock,
            'placement' => $this->placementSnapshot($placement),
            'reference' => $reference,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function placementSnapshot(ProductLocation $placement): array
    {
        return [
            'id' => $placement->id,
            'product_id' => $placement->product_id,
            'location_id' => $placement->location_id,
            'warehouse_id' => $placement->warehouse_id,
            'quantity' => (float) $placement->quantity,
            'allocated_hint' => (float) $placement->allocated_hint,
        ];
    }
}
