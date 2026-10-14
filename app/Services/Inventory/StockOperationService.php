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
use Marvel\Database\Models\ProductVariant;
use Marvel\Enums\ItemType;
use Marvel\Enums\ProductType;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/**
 * Single domain authority for WMS stock intake and relocation.
 *
 * Hard invariants (mirror the existing authorities, never bypass them):
 * - Authority is SKU-level: products.stock_quantity for SIMPLE products,
 *   product_variants.stock_quantity per-VARIANT for VARIABLE products
 *   (mirrors OrderReservationService::lockStockRow). Parent aggregates are
 *   NEVER written when variants own stock.
 * - Placement rows (product_locations) are non-authoritative hints keyed by
 *   (product_id, product_variant_id, location_id) — one SKU per bin (see
 *   occupancy guard). Their sum is informational and NEVER written back.
 * - Reservations live centrally (reserved_quantity on the authority row) and
 *   are owned exclusively by OrderReservationService — never touched here.
 * - Receiving ADDS to the authority; transfer NEVER changes the authority;
 *   initial placement ASSIGNS the creation quantity exactly once.
 * - Every mutation runs inside one transaction with row locks held in a
 *   deterministic order (authority, then placements by id).
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
     * SKU-level authority resolution (single enforcement point).
     *
     * - Simple product (no variants): variant MUST be null, authority = Product.
     * - Variable product (has variants): variant is REQUIRED and MUST belong
     *   to the product, authority = ProductVariant. Parent stock is never
     *   touched (prevents cross-SKU posting).
     * - Digital lines hold no physical stock (D1) — refused for both levels.
     *
     * @return array{0: Product|ProductVariant, 1: Product, 2: ?ProductVariant}
     *
     * @throws \Illuminate\Database\Eloquent\ModelNotFoundException
     * @throws \InvalidArgumentException
     */
    public function resolveAuthority(int $productId, ?int $variantId): array
    {
        $product = Product::query()->findOrFail($productId);

        if (($product->item_type ?? ItemType::PHYSICAL) === ItemType::DIGITAL) {
            throw new \InvalidArgumentException(
                __('wms.stock.product_digital', ['id' => $product->id])
            );
        }

        $hasVariants = $product->variations()->exists();

        if ($variantId === null) {
            if ($hasVariants || (string) ($product->product_type ?? ProductType::SIMPLE) !== (string) ProductType::SIMPLE) {
                // Reuse the established key so existing clients get the same
                // actionable message (now with variant guidance).
                throw new \InvalidArgumentException(
                    __('wms.stock.product_variants', ['id' => $product->id])
                );
            }

            return [$product, $product, null];
        }

        $variant = ProductVariant::query()->findOrFail($variantId);

        if ((int) $variant->product_id !== (int) $product->id) {
            throw new \InvalidArgumentException(
                __('wms.stock.variant_mismatch', ['variant' => $variantId, 'product' => $productId])
            );
        }

        return [$variant, $product, $variant];
    }

    /**
     * Product-level stock operations are representable only for simple,
     * physical products: variants own their own stock rows and placements
     * carry no variant dimension; digital lines hold no physical stock (D1).
     *
     * @deprecated Use resolveAuthority() for SKU-level operations. Kept for
     * backward compat with direct callers (now delegates to variant-required
     * resolution with null variant).
     *
     * @throws \InvalidArgumentException
     */
    public function assertReceivableProduct(Product $product): void
    {
        $this->resolveAuthority((int) $product->id, null);
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
    public function placeInitialStock(Product $product, int $locationId, ?int $warehouseId, int $quantity, ?int $variantId = null): ProductLocation
    {
        $this->assertPositiveQuantity($quantity);

        return $this->runInTransaction(function () use ($product, $locationId, $warehouseId, $quantity, $variantId) {
            [$location, $warehouseId] = $this->lockedPlaceableLocation($locationId, $warehouseId);
            [$authority] = $this->resolveAuthority((int) $product->id, $variantId);

            $locked = $this->lockAuthority((int) $product->id, $variantId);
            $locked->forceFill([
                'stock_quantity' => $quantity,
                'in_stock' => ($quantity - (int) ($locked->reserved_quantity ?? 0)) > 0,
            ])->save();

            $placement = $this->lockedPlacement((int) $product->id, $variantId, $location->id, $warehouseId);
            $this->placements->updateLocationQuantity($placement->id, (float) $quantity, 'initial_placement');

            ActivityAuditService::recordModel(
                $locked,
                'stock.placed',
                'inventory',
                __('activity.stock_placed', ['id' => $product->id]),
                new: [
                    'stock_quantity' => $quantity,
                    'product_variant_id' => $variantId,
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
        ?int $variantId = null,
    ): array {
        [$authority, $product] = $this->resolveAuthority($productId, $variantId);
        $this->assertReceivableVariant($product, $variantId);
        $this->assertPositiveQuantity($quantity);
        [, $warehouseId] = $this->resolvePlaceableLocation($locationId);

        return $this->idempotent(
            $idempotencyKey,
            InventoryOperation::OPERATION_RECEIPT,
            [
                'product_id' => $product->id,
                'product_variant_id' => $variantId,
                'warehouse_id' => $warehouseId,
                'destination_location_id' => $locationId,
                'quantity' => $quantity,
                'reference' => $reference,
            ],
            function () use ($product, $variantId, $locationId, $warehouseId, $quantity, $reference) {
                $locked = $this->lockAuthority((int) $product->id, $variantId);
                // M3: re-assert authority inside the write transaction (a
                // simple→variable transition between the pre-check and the
                // row lock must fail closed, never post to the parent).
                $this->reassertAuthorityLocked((int) $product->id, $variantId);
                $oldStock = (int) ($locked->stock_quantity ?? 0);

                $locked->forceFill([
                    'stock_quantity' => $oldStock + $quantity,
                    'in_stock' => (($oldStock + $quantity) - (int) ($locked->reserved_quantity ?? 0)) > 0,
                ])->save();

                [$location] = $this->lockedPlaceableLocation($locationId, $warehouseId);
                $placement = $this->lockedPlacement((int) $product->id, $variantId, $location->id, $warehouseId);
                $this->placements->updateLocationQuantity($placement->id, (float) $quantity, 'stock_receipt');

                $result = $this->stockSnapshot($locked->fresh(), $warehouseId, $placement->fresh(), $quantity, $reference, $variantId);

                ActivityAuditService::recordModel(
                    $locked,
                    'stock.received',
                    'inventory',
                    __('activity.stock_received', ['id' => $product->id]),
                    old: ['stock_quantity' => $oldStock, 'product_variant_id' => $variantId],
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
        ?int $variantId = null,
    ): array {
        if ($sourceLocationId === $destinationLocationId) {
            throw new \InvalidArgumentException(__('wms.stock.transfer_same'));
        }

        [$authority, $product] = $this->resolveAuthority($productId, $variantId);
        $this->assertReceivableVariant($product, $variantId);
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
                'product_variant_id' => $variantId,
                'warehouse_id' => $sourceWarehouseId,
                'source_location_id' => $sourceLocationId,
                'destination_location_id' => $destinationLocationId,
                'quantity' => $quantity,
                'reference' => $reference,
            ],
            function () use ($product, $variantId, $sourceLocationId, $destinationLocationId, $sourceWarehouseId, $quantity, $reference) {
                $locked = $this->lockAuthority((int) $product->id, $variantId);
                // M3: same in-transaction re-assertion as receiveStock.
                $this->reassertAuthorityLocked((int) $product->id, $variantId);
                $stockBefore = (int) ($locked->stock_quantity ?? 0);

                // Deterministic lock order (by placement id) against deadlocks.
                // The source row must already exist for THIS SKU: a transfer never creates
                // stock, so a missing source fails closed WITHOUT writing a
                // zero-quantity row. Same variant stays the identity throughout.
                $source = ProductLocation::query()->forSku((int) $product->id, $variantId)
                    ->where('location_id', $sourceLocationId)
                    ->first();

                if (!$source) {
                    throw new \InvalidArgumentException(
                        __('wms.stock.no_stock', ['product' => $this->skuLabel((int) $product->id, $variantId), 'location' => $sourceLocationId])
                    );
                }

                // E-guard: an open picking task (pending/assigned/picking)
                // pins this placement for its operator. Draining the hint
                // underneath active work is refused — complete, skip, or
                // reallocate the task first, then transfer. Checked here
                // (inside the write transaction, before locks) so the
                // refusal never leaves a half-moved placement pair.
                $hasOpenTask = \App\Models\Fulfillment\PickingTask::query()
                    ->where('product_location_id', $source->id)
                    ->whereIn('status', ['pending', 'assigned', 'picking'])
                    ->exists();
                if ($hasOpenTask) {
                    throw new \InvalidArgumentException(
                        __('wms.stock.transfer_open_task', ['location' => $sourceLocationId])
                    );
                }

                // E-guard (pre-task window): a fulfillment item with remaining
                // quantity pinned to this placement is an outstanding picking
                // commitment even before its task exists (task creation copies
                // item.product_location_id verbatim). Draining it sends the
                // picker to an empty bin. Refused while the fulfillment is
                // still pre-pick-complete (pending/picking); picked+ means
                // the goods are in hand and the bin is free. Recovery: create
                // tasks, reallocate them elsewhere, then transfer.
                $hasOpenCommitment = \App\Models\Fulfillment\FulfillmentItem::query()
                    ->where('product_location_id', $source->id)
                    ->whereColumn('quantity_picked', '<', 'quantity')
                    ->whereHas('fulfillment', fn ($q) => $q->whereIn('status', ['pending', 'picking']))
                    ->exists();
                if ($hasOpenCommitment) {
                    throw new \InvalidArgumentException(
                        __('wms.stock.transfer_open_commitment', ['location' => $sourceLocationId])
                    );
                }

                if ((int) $source->warehouse_id !== $sourceWarehouseId) {
                    throw new \InvalidArgumentException(
                        __('wms.stock.already_placed', ['product' => $this->skuLabel((int) $product->id, $variantId), 'location' => $sourceLocationId, 'warehouse' => $source->warehouse_id])
                    );
                }

                // Variant identity guard: source placement must belong to the
                // selected SKU (prevents Black/L -> Black/S cross-posting).
                if ((int) $source->product_id !== (int) $product->id || $this->variantKey($source->product_variant_id) !== $this->variantKey($variantId)) {
                    throw new \InvalidArgumentException(
                        __('wms.stock.variant_mismatch', ['variant' => $variantId ?? 0, 'product' => $product->id])
                    );
                }

                $destination = $this->lockedPlacement((int) $product->id, $variantId, $destinationLocationId, $sourceWarehouseId);
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

                // Post-lock revalidation of the E-guard above: a task may
                // have pinned this placement between the pre-check and the
                // row locks. The locked read is authoritative.
                $stillOpen = \App\Models\Fulfillment\PickingTask::query()
                    ->where('product_location_id', $source->id)
                    ->whereIn('status', ['pending', 'assigned', 'picking'])
                    ->lockForUpdate()
                    ->first();
                if ($stillOpen !== null) {
                    throw new \InvalidArgumentException(
                        __('wms.stock.transfer_open_task', ['location' => $sourceLocationId])
                    );
                }

                // Post-lock revalidation of the pre-task commitment guard: a
                // fulfillment item may have been pinned (creation/assignment)
                // between the pre-check and the row locks. Unlocked snapshot
                // read — deliberately no new lock edge (see design note); the
                // locked task recheck above is the backstop for the racing
                // task-creation case, and any residual hint-level race cannot
                // move central stock (hints only).
                $stillCommitted = \App\Models\Fulfillment\FulfillmentItem::query()
                    ->where('product_location_id', $source->id)
                    ->whereColumn('quantity_picked', '<', 'quantity')
                    ->whereHas('fulfillment', fn ($q) => $q->whereIn('status', ['pending', 'picking']))
                    ->exists();
                if ($stillCommitted) {
                    throw new \InvalidArgumentException(
                        __('wms.stock.transfer_open_commitment', ['location' => $sourceLocationId])
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
                    'product_variant_id' => $variantId,
                    // S2: receive includes sku; transfer now does too.
                    'sku' => $locked instanceof \Marvel\Database\Models\ProductVariant ? ($locked->sku ?? null) : ($locked->sku ?? null),
                    'warehouse_id' => $sourceWarehouseId,
                    'transferred_quantity' => $quantity,
                    'stock_quantity' => $stockAfter,
                    'reserved_quantity' => (int) ($locked->reserved_quantity ?? 0),
                    'available_quantity' => max(0, $stockAfter - (int) ($locked->reserved_quantity ?? 0)),
                    'source_placement' => $this->placementSnapshot($source->fresh()),
                    'destination_placement' => $this->placementSnapshot($destination->fresh()),
                    'reference' => $reference,
                ];

                ActivityAuditService::recordModel(
                    $locked,
                    'stock.transferred',
                    'inventory',
                    __('activity.stock_transferred', ['id' => $product->id]),
                    old: ['stock_quantity' => $stockBefore, 'product_variant_id' => $variantId],
                    new: $result,
                );

                return $result;
            }
        );
    }

    /**
     * Idempotency shell: UNIQUE(idempotency_key) is the final backstop.
     * Completed keys replay their stored result (same payload only);
     * a duplicate insert that loses the race reloads the winner once it
     * commits (InnoDB blocks the loser on the uncommitted unique until
     * the winner commits or rolls back), else 409s with an actionable
     * message. Keyless calls execute once with no ledger row.
     *
     * Payload binding (Case C): a key is bound to the exact operation
     * payload that first completed under it (operation, product, variant,
     * warehouse, locations, quantity). Reusing the key with a different
     * payload is a caller error — 409, never a silent replay and never a
     * second execution (prevents cross-SKU/cross-bin double posting on
     * retry). Reference is operator metadata and is NOT bound.
     *
     * @return array{result: array<string, mixed>, replayed: bool}
     *
     * @throws \Symfony\Component\HttpKernel\Exception\ConflictHttpException
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
            $this->assertIdempotencyBinding($completed, $operation, $attributes);

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
            // concurrent writer. Completed winner replays (same payload
            // only); anything else (processing owner, other failures) is
            // a 409 with an actionable message, never a faked replay.
            if (!$this->isDuplicateKey($e)) {
                throw $e;
            }

            $winner = InventoryOperation::query()->where('idempotency_key', $key)->first();

            if ($winner && $winner->status === InventoryOperation::STATUS_COMPLETED) {
                $this->assertIdempotencyBinding($winner, $operation, $attributes);

                return ['result' => $winner->result ?? [], 'replayed' => true];
            }

            if ($winner) {
                // A concurrent writer owns the key and has not committed
                // yet. The binding still applies (a different payload under
                // the same key is already wrong), then the caller is told
                // to resolve via the operation-status endpoint instead of
                // firing a second attempt elsewhere.
                $this->assertIdempotencyBinding($winner, $operation, $attributes);

                throw new ConflictHttpException(
                    __('wms.stock.idempotency_processing', ['key' => $key])
                );
            }

            throw $e;
        }
    }

    /**
     * Enforce the idempotency-key payload binding described above.
     *
     * @throws \Symfony\Component\HttpKernel\Exception\ConflictHttpException
     */
    private function assertIdempotencyBinding(InventoryOperation $existing, string $operation, array $attributes): void
    {
        $same = $existing->operation === $operation
            && (int) $existing->product_id === (int) ($attributes['product_id'] ?? 0)
            && $this->variantKey($existing->product_variant_id) === $this->variantKey($attributes['product_variant_id'] ?? null)
            && (int) ($existing->warehouse_id ?? 0) === (int) ($attributes['warehouse_id'] ?? 0)
            && (int) ($existing->source_location_id ?? 0) === (int) ($attributes['source_location_id'] ?? 0)
            && (int) ($existing->destination_location_id ?? 0) === (int) ($attributes['destination_location_id'] ?? 0)
            && (float) $existing->quantity === (float) ($attributes['quantity'] ?? 0);

        if (!$same) {
            throw new ConflictHttpException(
                __('wms.stock.idempotency_conflict', ['key' => $existing->idempotency_key])
            );
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
     * Fetch-or-create the placement row for THIS SKU, race-safe on the UNIQUE
     * (product_id, location_id, product_variant_id) backstop, then return it locked.
     *
     * Occupancy is SKU-level (one SKU per bin): enforced via the single shared
     * ProductLocationService::assertLocationVacantFor (ReturnService uses the
     * same guard). A live row for a DIFFERENT SKU (different product OR
     * different variant of the same parent) occupies the bin. Zero-quantity
     * rows are retained history and do not occupy. The guard runs BEFORE any
     * write: a refusal persists nothing.
     * Lock order everywhere: authority → location → placements.
     */
    private function lockedPlacement(int $productId, ?int $variantId, int $locationId, int $warehouseId): ProductLocation
    {
        // SKU-level occupancy guard (locks the location row, refuses before
        // any write). Never merges unrelated SKUs into one bin.
        $this->placements->assertLocationVacantFor($locationId, $productId, $variantId);

        // firstOrCreate with NULL variant needs explicit null handling:
        // Eloquent translates ['product_variant_id' => null] to whereNull.
        try {
            $query = ProductLocation::query()
                ->where('product_id', $productId)
                ->where('location_id', $locationId);
            if ($variantId === null) {
                $query->whereNull('product_variant_id');
            } else {
                $query->where('product_variant_id', $variantId);
            }
            $placement = $query->first();
            if (!$placement) {
                $placement = ProductLocation::query()->create([
                    'product_id' => $productId,
                    'product_variant_id' => $variantId,
                    'location_id' => $locationId,
                    'warehouse_id' => $warehouseId,
                    'quantity' => 0,
                    'allocated_hint' => 0,
                ]);
            }
        } catch (QueryException $e) {
            if (!$this->isDuplicateKey($e)) {
                throw $e;
            }

            $placement = ProductLocation::query()->forSku($productId, $variantId)
                ->where('location_id', $locationId)
                ->firstOrFail();
        }

        // Denorm guard: an existing row pinned to another warehouse means
        // corrupt history — fail closed, never silently re-home it.
        if ((int) $placement->warehouse_id !== $warehouseId) {
            throw new \InvalidArgumentException(
                __('wms.stock.already_placed', ['product' => $this->skuLabel($productId, $variantId), 'location' => $locationId, 'warehouse' => $placement->warehouse_id])
            );
        }

        // SKU guard on the fetched row (covers the duplicate-key reload path).
        if ((int) $placement->product_id !== $productId || $this->variantKey($placement->product_variant_id) !== $this->variantKey($variantId)) {
            throw new \InvalidArgumentException(
                __('wms.stock.variant_mismatch', ['variant' => $variantId ?? 0, 'product' => $productId])
            );
        }

        return ProductLocation::query()->whereKey($placement->id)->lockForUpdate()->firstOrFail();
    }

    /**
     * Lock the SKU-level authority row (Product for simple, ProductVariant
     * for variants). Mirrors OrderReservationService::lockStockRow.
     *
     * @return Product|ProductVariant
     */
    private function lockAuthority(int $productId, ?int $variantId): mixed
    {
        if ($variantId !== null) {
            return ProductVariant::query()->whereKey($variantId)->lockForUpdate()->firstOrFail();
        }

        return Product::query()->whereKey($productId)->lockForUpdate()->firstOrFail();
    }

    private function variantKey(mixed $variantId): int
    {
        return $variantId === null ? 0 : (int) $variantId;
    }

    private function skuLabel(int $productId, ?int $variantId): string
    {
        // Raw IDs only: the lang string already prefixes `#` (file
        // convention `#:occupant` / `#:product`). Variant SKUs append
        // `:Vn` only when a variant is actually present.
        return $variantId === null ? "{$productId}" : "{$productId}:V{$variantId}";
    }

    /**
     * Variant belongs to product (already enforced by resolveAuthority, but
     * re-checked here for direct callers that bypass it).
     *
     * @throws \InvalidArgumentException
     */
    private function assertReceivableVariant(Product $product, ?int $variantId): void
    {
        if ($variantId === null) {
            return;
        }

        $variant = ProductVariant::query()->findOrFail($variantId);
        if ((int) $variant->product_id !== (int) $product->id) {
            throw new \InvalidArgumentException(
                __('wms.stock.variant_mismatch', ['variant' => $variantId, 'product' => $product->id])
            );
        }
    }

    /**
     * M3: in-transaction authority re-assertion. The pre-check
     * (resolveAuthority) runs outside the write transaction; a concurrent
     * simple→variable transition (variants added) between the two must fail
     * closed. Called AFTER lockAuthority while holding the authority lock.
     *
     * @throws \InvalidArgumentException
     */
    private function reassertAuthorityLocked(int $productId, ?int $variantId): void
    {
        $product = Product::query()->findOrFail($productId);

        if (($product->item_type ?? ItemType::PHYSICAL) === ItemType::DIGITAL) {
            throw new \InvalidArgumentException(
                __('wms.stock.product_digital', ['id' => $product->id])
            );
        }

        $hasVariants = $product->variations()->exists();

        if ($variantId === null) {
            if ($hasVariants || (string) ($product->product_type ?? ProductType::SIMPLE) !== (string) ProductType::SIMPLE) {
                throw new \InvalidArgumentException(
                    __('wms.stock.product_variants', ['id' => $product->id])
                );
            }

            return;
        }

        $variant = ProductVariant::query()->findOrFail($variantId);
        if ((int) $variant->product_id !== (int) $product->id) {
            throw new \InvalidArgumentException(
                __('wms.stock.variant_mismatch', ['variant' => $variantId, 'product' => $productId])
            );
        }
    }

    /**
     * Atomic operation scope. ALWAYS opens a transaction frame
     * (savepoint when nested): a refusal after a partial write — e.g. the
     * authority incremented before a placement guard throws — must undo
     * that write even when composing with a caller's transaction (see the
     * phantom-row regression: keyless calls under an outer transaction
     * leaked zero placement rows and stock increments on 422 paths).
     * Savepoint release on success keeps composition atomic with the
     * outer transaction (storeProduct rolls everything back on failure);
     * rollback-to-savepoint on throw keeps refusals side-effect free.
     * This mirrors the unconditional DB::transaction in idempotent().
     */
    private function runInTransaction(\Closure $work): mixed
    {
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
    private function stockSnapshot(Product|ProductVariant $authority, int $warehouseId, ProductLocation $placement, int $moved, ?string $reference, ?int $variantId = null): array
    {
        $stock = (int) ($authority->stock_quantity ?? 0);
        $reserved = (int) ($authority->reserved_quantity ?? 0);
        $sku = $authority instanceof ProductVariant
            ? ($authority->sku ?? null)
            : ($authority->sku ?? null);

        return [
            'product_id' => $placement->product_id,
            'product_variant_id' => $placement->product_variant_id !== null ? (int) $placement->product_variant_id : $variantId,
            'sku' => $sku,
            'warehouse_id' => $warehouseId,
            'received_quantity' => $moved,
            'stock_quantity' => $stock,
            'reserved_quantity' => $reserved,
            'available_quantity' => max(0, $stock - $reserved),
            'in_stock' => (bool) $authority->in_stock,
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
            'product_variant_id' => $placement->product_variant_id !== null ? (int) $placement->product_variant_id : null,
            'location_id' => $placement->location_id,
            'warehouse_id' => $placement->warehouse_id,
            'quantity' => (float) $placement->quantity,
            'allocated_hint' => (float) $placement->allocated_hint,
        ];
    }
}
