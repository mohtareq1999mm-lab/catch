<?php

namespace App\Services\Fulfillment;

use App\Exceptions\PickingValidationException;
use App\Models\Fulfillment\FulfillmentItem;
use App\Models\Fulfillment\Location;
use App\Models\Fulfillment\PickingTask;
use App\Models\Fulfillment\ProductLocation;
use App\Services\Warehouse\BarcodeResolver;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Phase 8: the ONE coherent picking engine (order + batch flows share it).
 *
 * - claim: conditional pending→assigned (exactly one winner, 409 on conflict).
 * - confirm: scan-validated, locked, op_seq-idempotent quantity confirmation.
 * - rejects are logged with expected-vs-scanned evidence (audit trail).
 *
 * Permissions and warehouse scoping are enforced by callers (P13); this
 * service takes the actor identity for audit and an explicit override flag.
 */
class PickingExecutionService
{
    public function __construct(
        private BarcodeResolver $barcodes,
        private ProductLocationService $placements,
    ) {}

    /**
     * Claim a pending task. Exactly one worker wins.
     *
     * @throws \RuntimeException (409 semantics) when already claimed by another worker.
     */
    public function claim(PickingTask $task, int $userId, ?int $leaseMinutes = null, bool $override = false): PickingTask
    {
        $leaseMinutes = max(1, (int) ($leaseMinutes ?? config('fulfillment.claim_lease_minutes', 15)));

        return DB::transaction(function () use ($task, $userId, $leaseMinutes, $override) {
            $locked = PickingTask::whereKey($task->id)->lockForUpdate()->firstOrFail();

            // P4-8: override may bypass another worker's active claim, but it
            // must never reopen a terminal task. Claiming applies to open
            // tasks only — this guard covers the override path AND the
            // same-worker re-claim below (refreshing a lease on completed
            // work is not a valid operation).
            if (in_array($locked->status, ['picked', 'skipped', 'cancelled'], true)) {
                throw new \RuntimeException(
                    "Picking task #{$locked->id} is terminal (status: {$locked->status}): claim refused"
                );
            }

            if ($locked->claimed_by !== null && (int) $locked->claimed_by === $userId) {
                // Same worker re-claims: refresh the lease (idempotent).
                $locked->update(['claim_expires_at' => now()->addMinutes($leaseMinutes)]);

                return $locked->fresh();
            }

            $claimFree = $locked->status === 'pending' && $locked->claimed_by === null;
            $leaseExpired = $locked->claim_expires_at !== null
                && now()->greaterThan($locked->claim_expires_at);

            if ((!$claimFree && !$leaseExpired && !$override) || (!$override && !in_array($locked->status, ['pending', 'assigned'], true))) {
                throw new \RuntimeException(
                    "Picking task #{$locked->id} is already claimed (status: {$locked->status})"
                );
            }

            $locked->update([
                'status' => 'assigned',
                'claimed_by' => $userId,
                'claimed_at' => now(),
                'claim_expires_at' => now()->addMinutes($leaseMinutes),
            ]);

            Log::info('Picking task claimed', [
                'task_id' => $locked->id, 'user_id' => $userId, 'override' => $override,
            ]);

            return $locked->fresh();
        });
    }

    /**
     * Release a claim back to the pool (keeps picked progress for resume).
     */
    public function releaseClaim(PickingTask $task, int $userId, bool $override = false): PickingTask
    {
        return DB::transaction(function () use ($task, $userId, $override) {
            $locked = PickingTask::whereKey($task->id)->lockForUpdate()->firstOrFail();

            if (!$override && (int) $locked->claimed_by !== $userId) {
                throw new \RuntimeException("Picking task #{$locked->id} is claimed by another worker");
            }

            $locked->update([
                'status' => 'pending',
                'claimed_by' => null,
                'claimed_at' => null,
                'claim_expires_at' => null,
            ]);

            return $locked->fresh();
        });
    }

    /**
     * Confirm a pick with scan validation.
     *
     * @param array{location: string, product: string, quantity: float|int, op_seq: int} $scan
     *
     * @throws PickingValidationException on wrong location/product/quantity.
     */
    public function confirm(PickingTask $task, array $scan, int $userId, bool $override = false): PickingTask
    {
        return DB::transaction(function () use ($task, $scan, $userId, $override) {
            $locked = PickingTask::whereKey($task->id)->lockForUpdate()->firstOrFail();
            $opSeq = (int) ($scan['op_seq'] ?? 0);

            // Idempotent replay: op_seq already applied → return current state.
            if ($opSeq > 0 && $opSeq <= (int) $locked->op_seq) {
                Log::info('Picking confirm replay ignored', [
                    'task_id' => $locked->id, 'op_seq' => $opSeq, 'user_id' => $userId,
                ]);

                return $locked;
            }

            if (!$override) {
                if (!in_array($locked->status, ['assigned', 'picking'], true)) {
                    $this->reject($locked, $scan, $userId, 'task_not_claimed');
                }
                if ($locked->claimed_by !== null && (int) $locked->claimed_by !== $userId) {
                    $this->reject($locked, $scan, $userId, 'task_claimed_by_other');
                }
            }

            $item = $locked->fulfillmentItem()->firstOrFail();
            $expectedLocationId = (int) $locked->product_location_id;

            // Validate WHERE.
            $location = $this->barcodes->resolve((string) ($scan['location'] ?? ''));
            if ($location['kind'] !== 'location' || (int) $location['id'] !== $this->locationIdOf($locked)) {
                $this->reject($locked, $scan, $userId, 'wrong_location', [
                    'expected_location_id' => $expectedLocationId,
                ]);
            }

            // Validate WHAT.
            $product = $this->barcodes->resolve((string) ($scan['product'] ?? ''));
            $this->assertScannedProduct($locked, $item, $product, $scan, $userId);

            // P4-7: execution-time placement revalidation. The location may
            // have been deactivated (or the placement drifted) after task
            // creation — reject through the standard channel; the operator
            // reallocates via reallocateTask. Nothing is mutated here.
            $this->assertPlacementStillValid($locked, $item, $scan, $userId);

            // Validate HOW MUCH.
            $quantity = (float) ($scan['quantity'] ?? 0);
            $remaining = (float) $locked->quantity_to_pick - (float) $locked->quantity_picked;
            if ($quantity <= 0 || $quantity > $remaining) {
                $this->reject($locked, $scan, $userId, 'invalid_quantity', ['remaining' => $remaining]);
            }

            $newTotal = (float) $locked->quantity_picked + $quantity;
            $complete = $newTotal >= (float) $locked->quantity_to_pick;

            $log = is_array($locked->scan_log) ? $locked->scan_log : [];
            $log[] = [
                'op_seq' => $opSeq, 'actor_id' => $userId, 'action' => 'confirm',
                'location' => $scan['location'], 'product' => $scan['product'],
                'quantity' => $quantity, 'result' => 'ok', 'at' => now()->toIso8601String(),
            ];

            $locked->update([
                'quantity_picked' => $newTotal,
                'status' => $complete ? 'picked' : 'picking',
                'picked_at' => $complete ? now() : $locked->picked_at,
                'op_seq' => max($opSeq, (int) $locked->op_seq),
                'scan_log' => $log,
            ]);

            $item->increment('quantity_picked', $quantity);

            Log::info('Pick confirmed', [
                'task_id' => $locked->id, 'user_id' => $userId, 'quantity' => $quantity,
                'complete' => $complete,
            ]);

            return $locked->fresh();
        });
    }

    /**
     * Relocate a task to another placement hint in the SAME warehouse
     * (§32: location deactivated mid-pick → recommend another, never fail
     * the fulfillment, never cross warehouses).
     *
     * @throws \RuntimeException on terminal task, wrong product, cross-warehouse,
     *   inactive / non-placeable target, or placement/location warehouse drift.
     */
    public function reallocateTask(PickingTask $task, int $newProductLocationId): PickingTask
    {
        return DB::transaction(function () use ($task, $newProductLocationId) {
            $locked = PickingTask::whereKey($task->id)->lockForUpdate()->firstOrFail();

            if (in_array($locked->status, ['picked', 'skipped', 'cancelled'], true)) {
                throw new \RuntimeException("Cannot reallocate task #{$locked->id} in status {$locked->status}");
            }

            $item = $locked->fulfillmentItem()->firstOrFail();
            $fulfillment = $item->fulfillment()->firstOrFail();

            $target = \App\Models\Fulfillment\ProductLocation::whereKey($newProductLocationId)->firstOrFail();
            $location = $target->location()->firstOrFail();

            if ((int) $target->product_id !== (int) $item->product_id) {
                throw new \RuntimeException('Reallocation target holds a different product');
            }
            if ((int) $target->warehouse_id !== (int) $fulfillment->warehouse_id) {
                throw new \RuntimeException('Reallocation must stay in the same warehouse');
            }
            // Mirror scopePlaceable: active + (null or placeable type).
            $placeable = $location->status === \App\Models\Fulfillment\Location::STATUS_ACTIVE
                && ($location->type === null || in_array($location->type, \App\Models\Fulfillment\Location::PLACEABLE_TYPES, true));
            if (!$placeable) {
                throw new \RuntimeException("Target location #{$location->id} is not active/placeable");
            }
            if ((int) $target->warehouse_id !== (int) $location->warehouse_id) {
                throw new \RuntimeException('Product placement warehouse does not match its location warehouse');
            }

            $locked->update(['product_location_id' => $target->id]);

            Log::info('Picking task reallocated', [
                'task_id' => $locked->id,
                'from_product_location' => $task->product_location_id,
                'to_product_location' => $target->id,
            ]);

            return $locked->fresh();
        });
    }

    /**
     * Release claims whose lease expired (sweeper calls this; P12 schedules it).
     * Covers both `assigned` and `picking`: confirm() keeps the lease while
     * work is in progress, so an expired lease must recycle either way
     * (picked progress is preserved for resume).
     *
     * P4-5: the select-then-release is split across transactions, so a task
     * can complete (or its claim refresh) between selection and release.
     * The final release decision therefore happens under row lock inside
     * releaseIfStillExpired(): stale selections release nothing and are not
     * counted. Never regresses picked/skipped/cancelled, never clears a
     * refreshed lease or another worker's newer claim.
     */
    public function sweepExpiredClaims(?int $limit = null): int
    {
        $limit = max(1, (int) ($limit ?? config('fulfillment.sweep_limit', 100)));
        $expired = PickingTask::whereIn('status', ['assigned', 'picking'])
            ->whereNotNull('claim_expires_at')
            ->where('claim_expires_at', '<', now())
            ->orderBy('claim_expires_at')
            ->limit($limit)
            ->get(['id', 'claimed_by', 'claim_expires_at']);

        $count = 0;
        foreach ($expired as $snapshot) {
            if ($this->releaseIfStillExpired($snapshot)) {
                $count++;
            }
        }

        return $count;
    }

    /**
     * P4-5: release one selected task only if, under row lock, it is still
     * in an open claimable state AND its lease is still expired AND the
     * claim identity (owner + expiry) is unchanged since selection.
     * Otherwise do nothing and report false (not counted).
     */
    private function releaseIfStillExpired(PickingTask $snapshot): bool
    {
        return DB::transaction(function () use ($snapshot) {
            $locked = PickingTask::whereKey($snapshot->id)->lockForUpdate()->first();
            if ($locked === null) {
                return false;
            }
            if (!in_array($locked->status, ['assigned', 'picking'], true)) {
                return false;
            }
            if ($locked->claim_expires_at === null || !now()->greaterThan($locked->claim_expires_at)) {
                return false;
            }
            if ((string) $locked->claimed_by !== (string) $snapshot->claimed_by) {
                return false;
            }
            if ((string) $locked->claim_expires_at !== (string) $snapshot->claim_expires_at) {
                return false;
            }

            $locked->update([
                'status' => 'pending',
                'claimed_by' => null,
                'claimed_at' => null,
                'claim_expires_at' => null,
            ]);

            return true;
        });
    }

    /**
     * P4-7: revalidate the task's pinned placement at execution time —
     * product match, location activeness/placeability, warehouse agreement
     * (fulfillment-pinned warehouse, never a fresh default), and
     * placement/location consistency via the existing domain helper.
     * Rejects through the standard validation channel (no mutation).
     */
    private function assertPlacementStillValid(PickingTask $task, FulfillmentItem $item, array $scan, int $userId): void
    {
        $placement = ProductLocation::whereKey($task->product_location_id)->first();
        if ($placement === null) {
            $this->reject($task, $scan, $userId, 'stale_placement', [
                'product_location_id' => $task->product_location_id,
            ]);
        }
        if ((int) $placement->product_id !== (int) $item->product_id) {
            $this->reject($task, $scan, $userId, 'stale_placement', [
                'expected_product_id' => $item->product_id,
                'placement_product_id' => $placement->product_id,
            ]);
        }

        $location = Location::whereKey($placement->location_id)->first();
        // Mirror scopePlaceable: active + (null or placeable type).
        $placeable = $location !== null
            && $location->status === Location::STATUS_ACTIVE
            && ($location->type === null || in_array($location->type, Location::PLACEABLE_TYPES, true));
        if (!$placeable) {
            $this->reject($task, $scan, $userId, 'stale_placement', [
                'location_id' => $placement->location_id,
                'location_status' => $location?->status,
            ]);
        }

        $fulfillment = $item->fulfillment()->firstOrFail();
        if ((int) $placement->warehouse_id !== (int) $fulfillment->warehouse_id) {
            $this->reject($task, $scan, $userId, 'stale_placement', [
                'placement_warehouse_id' => $placement->warehouse_id,
                'fulfillment_warehouse_id' => $fulfillment->warehouse_id,
            ]);
        }

        try {
            $this->placements->assertConsistent($placement);
        } catch (\RuntimeException $e) {
            $this->reject($task, $scan, $userId, 'stale_placement', [
                'detail' => $e->getMessage(),
            ]);
        }
    }

    private function locationIdOf(PickingTask $task): int
    {
        $productLocation = $task->productLocation()->first();

        return (int) ($productLocation?->location_id ?? 0);
    }

    /**
     * @param array{kind: string, id: int|string} $product
     */
    private function assertScannedProduct(PickingTask $task, FulfillmentItem $item, array $product, array $scan, int $userId): void
    {
        $ok = match ($product['kind']) {
            'product' => (int) $product['id'] === (int) $item->product_id && $item->product_variant_id === null,
            'variant' => (int) $product['id'] === (int) $item->product_variant_id,
            default => false,
        };

        if (!$ok) {
            $this->reject($task, $scan, $userId, 'wrong_product', [
                'expected_product_id' => $item->product_id,
                'expected_variant_id' => $item->product_variant_id,
            ]);
        }
    }

    /**
     * @return never
     *
     * @throws PickingValidationException
     */
    private function reject(PickingTask $task, array $scan, int $userId, string $reason, array $extra = []): void
    {
        Log::warning('Picking scan rejected', array_merge([
            'task_id' => $task->id,
            'user_id' => $userId,
            'reason' => $reason,
            'scanned_location' => $scan['location'] ?? null,
            'scanned_product' => $scan['product'] ?? null,
            'scanned_quantity' => $scan['quantity'] ?? null,
        ], $extra));

        throw new PickingValidationException($reason, $extra);
    }
}
