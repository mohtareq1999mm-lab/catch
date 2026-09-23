<?php

namespace App\Services\Fulfillment;

use App\Exceptions\PickingValidationException;
use App\Models\Fulfillment\FulfillmentItem;
use App\Models\Fulfillment\PickingTask;
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
    ) {}

    /**
     * Claim a pending task. Exactly one worker wins.
     *
     * @throws \RuntimeException (409 semantics) when already claimed by another worker.
     */
    public function claim(PickingTask $task, int $userId, int $leaseMinutes = 15, bool $override = false): PickingTask
    {
        return DB::transaction(function () use ($task, $userId, $leaseMinutes, $override) {
            $locked = PickingTask::whereKey($task->id)->lockForUpdate()->firstOrFail();

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
     * Release claims whose lease expired (sweeper calls this; P12 schedules it).
     */
    public function sweepExpiredClaims(int $limit = 100): int
    {
        $expired = PickingTask::where('status', 'assigned')
            ->whereNotNull('claim_expires_at')
            ->where('claim_expires_at', '<', now())
            ->limit($limit)
            ->pluck('id');

        $count = 0;
        foreach ($expired as $id) {
            $task = PickingTask::find($id);
            if ($task) {
                $this->releaseClaim($task, (int) $task->claimed_by, true);
                $count++;
            }
        }

        return $count;
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
