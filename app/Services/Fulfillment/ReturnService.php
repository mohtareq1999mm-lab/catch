<?php

namespace App\Services\Fulfillment;

use App\Models\Fulfillment\ReturnRequest;
use App\Models\Fulfillment\ReturnItem;
use App\Models\Fulfillment\Fulfillment;
use App\Models\Fulfillment\ProductLocation;
use Marvel\Database\Models\Order;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class ReturnService
{
    public function __construct(
        private ProductLocationService $productLocationService
    ) {}

    /**
     * Create a return request from an order
     */
    public function createReturnRequest(
        Order $order,
        array $items,
        string $reason,
        ?string $customerNotes = null,
        ?int $userId = null
    ): ReturnRequest {
        return DB::transaction(function () use ($order, $items, $reason, $customerNotes, $userId) {
            $fulfillment = Fulfillment::where('order_id', $order->id)
                ->whereIn('status', ['shipped', 'delivered'])
                ->first();

            if (!$fulfillment) {
                throw new \Exception('Cannot create return request: order has no completed fulfillment');
            }

            $returnNumber = $this->generateReturnNumber();

            $returnRequest = ReturnRequest::create([
                'return_number' => $returnNumber,
                'order_id' => $order->id,
                'fulfillment_id' => $fulfillment->id,
                'warehouse_id' => $fulfillment->warehouse_id,
                'customer_id' => $order->user_id,
                'status' => 'pending',
                'reason' => $reason,
                'customer_notes' => $customerNotes,
                'requested_by' => $userId,
                'requested_at' => now(),
            ]);

            foreach ($items as $item) {
                ReturnItem::create([
                    'return_request_id' => $returnRequest->id,
                    'order_item_id' => $item['order_item_id'],
                    'product_id' => $item['product_id'],
                    'fulfillment_item_id' => $item['fulfillment_item_id'] ?? null,
                    'quantity_returned' => $item['quantity'],
                    'condition' => 'unknown',
                ]);
            }

            Log::info('Return request created', [
                'return_request_id' => $returnRequest->id,
                'return_number' => $returnNumber,
                'order_id' => $order->id,
            ]);

            return $returnRequest->fresh(['returnItems']);
        });
    }

    /**
     * Approve return request
     */
    public function approveReturnRequest(
        ReturnRequest $returnRequest,
        array $approvedQuantities,
        int $userId,
        ?string $adminNotes = null
    ): ReturnRequest {
        if (!$returnRequest->canBeApproved()) {
            throw new \Exception(
                "Cannot approve return request in status: {$returnRequest->status}"
            );
        }

        return DB::transaction(function () use ($returnRequest, $approvedQuantities, $userId, $adminNotes) {
            foreach ($approvedQuantities as $itemId => $quantity) {
                $returnItem = ReturnItem::find($itemId);
                if ($returnItem && $returnItem->return_request_id === $returnRequest->id) {
                    $returnItem->update([
                        'quantity_approved' => min($quantity, $returnItem->quantity_returned),
                    ]);
                }
            }

            $returnRequest->update([
                'status' => 'approved',
                'approved_by' => $userId,
                'approved_at' => now(),
                'admin_notes' => $adminNotes,
            ]);

            Log::info('Return request approved', [
                'return_request_id' => $returnRequest->id,
                'approved_by' => $userId,
            ]);

            return $returnRequest->fresh(['returnItems']);
        });
    }

    /**
     * Reject return request
     */
    public function rejectReturnRequest(
        ReturnRequest $returnRequest,
        string $reason,
        int $userId
    ): ReturnRequest {
        if (!$returnRequest->canBeApproved()) {
            throw new \Exception(
                "Cannot reject return request in status: {$returnRequest->status}"
            );
        }

        $returnRequest->update([
            'status' => 'rejected',
            'approved_by' => $userId,
            'rejected_at' => now(),
            'admin_notes' => $reason,
        ]);

        Log::warning('Return request rejected', [
            'return_request_id' => $returnRequest->id,
            'reason' => $reason,
        ]);

        return $returnRequest->fresh();
    }

    /**
     * Mark return as received at warehouse
     */
    public function markAsReceived(ReturnRequest $returnRequest, int $userId): ReturnRequest
    {
        if (!$returnRequest->canBeReceived()) {
            throw new \Exception(
                "Cannot receive return request in status: {$returnRequest->status}"
            );
        }

        $returnRequest->update([
            'status' => 'received',
            'received_at' => now(),
        ]);

        Log::info('Return request received', [
            'return_request_id' => $returnRequest->id,
            'received_by' => $userId,
        ]);

        return $returnRequest->fresh();
    }

    /**
     * Start inspection process
     */
    public function startInspection(ReturnRequest $returnRequest, int $userId): ReturnRequest
    {
        if (!$returnRequest->canBeInspected()) {
            throw new \Exception(
                "Cannot start inspection for return request in status: {$returnRequest->status}"
            );
        }

        $returnRequest->update([
            'status' => 'inspecting',
            'inspected_by' => $userId,
        ]);

        Log::info('Return inspection started', [
            'return_request_id' => $returnRequest->id,
            'inspected_by' => $userId,
        ]);

        return $returnRequest->fresh();
    }

    /**
     * Inspect return item and update condition
     */
    public function inspectReturnItem(
        ReturnItem $returnItem,
        string $condition,
        ?string $notes = null
    ): ReturnItem {
        $returnItem->update([
            'condition' => $condition,
            'inspection_notes' => $notes,
            'inspected_at' => now(),
        ]);

        Log::info('Return item inspected', [
            'return_item_id' => $returnItem->id,
            'condition' => $condition,
        ]);

        return $returnItem->fresh();
    }

    /**
     * Restock return item to warehouse location
     */
    public function restockReturnItem(
        ReturnItem $returnItem,
        int $locationId,
        int $quantity
    ): ReturnItem {
        if (!$returnItem->isRestockable()) {
            throw new \Exception(
                "Cannot restock item with condition: {$returnItem->condition}"
            );
        }

        // Phase 12: duplicate-restock protection (restock is not idempotent
        // by quantity — a second call for the same approval must fail loudly).
        if ($returnItem->isFullyRestocked()) {
            throw new \Exception(
                "Return item #{$returnItem->id} is already fully restocked"
            );
        }

        if ($quantity > $returnItem->quantity_approved) {
            throw new \Exception('Restock quantity cannot exceed approved quantity');
        }

        return DB::transaction(function () use ($returnItem, $locationId, $quantity) {
            // Phase 12: central restore FIRST, placement hint second — the
            // authority moves before its projection, so the drift monitor
            // never observes an inconsistent intermediate state.
            $returnOrder = $returnItem->returnRequest?->order ?? $returnItem->returnRequest()->first()?->order;
            if ($returnOrder && $returnItem->order_item_id) {
                app(\App\Services\Inventory\InventoryRestoreService::class)->restoreLines(
                    $returnOrder,
                    [['order_item_id' => (int) $returnItem->order_item_id, 'quantity' => $quantity]],
                );
            }

            $productLocation = ProductLocation::firstOrCreate(
                [
                    'product_id' => $returnItem->product_id,
                    'location_id' => $locationId,
                    'warehouse_id' => $returnItem->returnRequest->warehouse_id,
                ],
                [
                    'quantity' => 0,
                    'allocated_hint' => 0,
                ]
            );

            $this->productLocationService->updateLocationQuantity(
                $productLocation->id,
                $quantity,
                'return_restock'
            );

            $returnItem->update([
                'quantity_restocked' => $quantity,
                'restocked_location_id' => $locationId,
                'product_location_id' => $productLocation->id,
                'restocked_at' => now(),
            ]);

            Log::info('Return item restocked', [
                'return_item_id' => $returnItem->id,
                'location_id' => $locationId,
                'quantity' => $quantity,
            ]);

            return $returnItem->fresh();
        });
    }

    /**
     * Complete return request after all items restocked
     */
    public function completeReturnRequest(ReturnRequest $returnRequest): ReturnRequest
    {
        if (!$returnRequest->canBeRestocked()) {
            throw new \Exception(
                "Cannot complete return request in status: {$returnRequest->status}"
            );
        }

        $allRestocked = $returnRequest->returnItems()
            ->get()
            ->every(fn($item) => !$item->isRestockable() || $item->isFullyRestocked());

        if (!$allRestocked) {
            throw new \Exception('Not all restockable items have been restocked');
        }

        $returnRequest->update([
            'status' => 'completed',
            'restocked_at' => now(),
            'completed_at' => now(),
        ]);

        Log::info('Return request completed', [
            'return_request_id' => $returnRequest->id,
        ]);

        return $returnRequest->fresh();
    }

    /**
     * Cancel return request
     */
    public function cancelReturnRequest(ReturnRequest $returnRequest, string $reason): ReturnRequest
    {
        if ($returnRequest->isComplete()) {
            throw new \Exception('Cannot cancel completed return request');
        }

        $returnRequest->update([
            'status' => 'cancelled',
            'admin_notes' => $reason,
        ]);

        Log::warning('Return request cancelled', [
            'return_request_id' => $returnRequest->id,
            'reason' => $reason,
        ]);

        return $returnRequest->fresh();
    }

    /**
     * Generate unique return number
     */
    private function generateReturnNumber(): string
    {
        do {
            $number = 'RET-' . date('Ymd') . '-' . strtoupper(substr(uniqid(), -8));
            $exists = ReturnRequest::where('return_number', $number)->exists();
        } while ($exists);

        return $number;
    }

    /**
     * Get pending return requests for warehouse
     */
    public function getPendingReturns(int $warehouseId): Collection
    {
        return ReturnRequest::where('warehouse_id', $warehouseId)
            ->whereIn('status', ['pending', 'approved', 'received', 'inspecting'])
            ->with(['returnItems.product', 'customer', 'order'])
            ->orderBy('requested_at')
            ->get();
    }

    /**
     * Get return statistics for warehouse
     */
    public function getReturnStatistics(int $warehouseId, ?\DateTime $date = null): array
    {
        $date = $date ?? now();

        $query = ReturnRequest::where('warehouse_id', $warehouseId)
            ->whereDate('created_at', $date->format('Y-m-d'));

        return [
            'total_returns' => $query->count(),
            'pending' => (clone $query)->where('status', 'pending')->count(),
            'approved' => (clone $query)->where('status', 'approved')->count(),
            'in_progress' => (clone $query)->whereIn('status', ['received', 'inspecting'])->count(),
            'completed' => (clone $query)->where('status', 'completed')->count(),
            'rejected' => (clone $query)->where('status', 'rejected')->count(),
        ];
    }
}
