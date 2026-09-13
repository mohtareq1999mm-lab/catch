<?php

namespace App\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Generic realtime notification for long-running file operations
 * (imports / exports / bulk deletes) processed on the high queue (config queue.queues.high).
 *
 * This event is a wake-up signal only. The `imports` table remains the
 * single source of truth; clients must reconcile through the existing
 * status endpoints after receiving (or missing) this event.
 *
 * Payload contract (safe fields only — never paths, disk names, secrets,
 * stack traces, or raw error arrays):
 *
 * {
 *   "kind":          "product-import",
 *   "id":            123,
 *   "status":        "processing|completed|completed_with_errors|failed|cancelled",
 *   "progress":      65.5,
 *   "processed_rows": 650,
 *   "success_rows":  640,
 *   "failed_rows":   10,
 *   "total_rows":    1000,
 *   "has_errors":    true
 * }
 */
class FileOperationEvent implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets;

    public const PRODUCT_IMPORT_PROGRESS = 'product.import.progress';
    public const PRODUCT_IMPORT_QUEUED = 'product.import.queued';
    public const PRODUCT_IMPORT_COMPLETED = 'product.import.completed';
    public const PRODUCT_IMPORT_FAILED = 'product.import.failed';
    public const PRODUCT_IMPORT_CANCELLING = 'product.import.cancelling';
    public const PRODUCT_IMPORT_CANCELLED = 'product.import.cancelled';
    public const PRODUCT_EXPORT_QUEUED = 'product.export.queued';
    public const PRODUCT_EXPORT_PROGRESS = 'product.export.progress';
    public const PRODUCT_EXPORT_COMPLETED = 'product.export.completed';
    public const PRODUCT_EXPORT_FAILED = 'product.export.failed';
    public const CATEGORY_IMPORT_PROGRESS = 'category.import.progress';
    public const CATEGORY_IMPORT_QUEUED = 'category.import.queued';
    public const CATEGORY_IMPORT_COMPLETED = 'category.import.completed';
    public const CATEGORY_IMPORT_FAILED = 'category.import.failed';
    public const CATEGORY_IMPORT_CANCELLING = 'category.import.cancelling';
    public const CATEGORY_IMPORT_CANCELLED = 'category.import.cancelled';
    public const BRAND_IMPORT_PROGRESS = 'brand.import.progress';
    public const BRAND_IMPORT_QUEUED = 'brand.import.queued';
    public const BRAND_IMPORT_COMPLETED = 'brand.import.completed';
    public const BRAND_IMPORT_FAILED = 'brand.import.failed';
    public const BRAND_IMPORT_CANCELLING = 'brand.import.cancelling';
    public const BRAND_IMPORT_CANCELLED = 'brand.import.cancelled';
    public const CATEGORY_EXPORT_QUEUED = 'category.export.queued';
    public const CATEGORY_EXPORT_PROGRESS = 'category.export.progress';
    public const CATEGORY_EXPORT_COMPLETED = 'category.export.completed';
    public const CATEGORY_EXPORT_FAILED = 'category.export.failed';
    public const BRAND_EXPORT_QUEUED = 'brand.export.queued';
    public const BRAND_EXPORT_PROGRESS = 'brand.export.progress';
    public const BRAND_EXPORT_COMPLETED = 'brand.export.completed';
    public const BRAND_EXPORT_FAILED = 'brand.export.failed';
    public const CATEGORY_BULK_DELETE_QUEUED = 'category.bulk-delete.queued';
    public const CATEGORY_BULK_DELETE_PROGRESS = 'category.bulk-delete.progress';
    public const CATEGORY_BULK_DELETE_COMPLETED = 'category.bulk-delete.completed';
    public const CATEGORY_BULK_DELETE_CANCELLED = 'category.bulk-delete.cancelled';
    public const CATEGORY_BULK_DELETE_FAILED = 'category.bulk-delete.failed';

    public function __construct(
        public int $userId,
        public string $eventName,
        public array $payload = [],
    ) {}

    public function broadcastOn(): array
    {
        return [
            new PrivateChannel('users.' . $this->userId),
        ];
    }

    public function broadcastAs(): string
    {
        return $this->eventName;
    }

    public function broadcastWith(): array
    {
        return $this->payload;
    }
}
