<?php

namespace App\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * @deprecated Legacy category import progress event — canonical contract is
 * FileOperationEvent via BroadcastsFileOperationProgress. This class is retained
 * for backward compatibility and now emits the canonical payload shape.
 * New code must use FileOperationEvent / BroadcastsFileOperationProgress.
 */
class CategoryImportProgress implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets;

    public function __construct(
        public int $userId,
        public int $importId,
        public array $data = [],
    ) {}

    /**
     * Same fix as FileOperationEvent: never inherit a stale/log default.
     *
     * @return string[]
     */
    public function broadcastConnections(): array
    {
        return ['pusher'];
    }

    public function broadcastOn(): array
    {
        return [
            new PrivateChannel('users.' . $this->userId),
        ];
    }

    public function broadcastAs(): string
    {
        return 'category.import.progress';
    }

    public function broadcastWith(): array
    {
        $progress = (float) ($this->data['progress'] ?? 0.0);
        $processed = (int) ($this->data['processed_rows'] ?? 0);
        $success = (int) ($this->data['success_rows'] ?? 0);
        $failed = (int) ($this->data['failed_rows'] ?? 0);
        $total = $this->data['total_rows'] ?? null;

        return [
            'kind' => 'category-import',
            'operation_type' => 'category-import',
            'id' => $this->importId,
            'operation_id' => $this->importId,
            'event' => 'category.import.progress',
            'state' => 'processing',
            'status' => 'processing',
            'has_errors' => $failed > 0,
            'progress' => round(max(0.0, min($progress, 100.0)), 2),
            'percentage' => round(max(0.0, min($progress, 100.0)), 2),
            'progress_detail' => [
                'percentage' => round(max(0.0, min($progress, 100.0)), 2),
                'processed' => $processed,
                'total' => $total,
            ],
            'processed_rows' => $processed,
            'success_rows' => $success,
            'failed_rows' => $failed,
            'total_rows' => $total,
            'download_available' => false,
            'timestamp' => now()->toIso8601String(),
            'message' => $this->data['message'] ?? 'Category import is processing.',
        ];
    }
}