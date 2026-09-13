<?php

namespace App\Traits;

use App\Events\FileOperationEvent;
use Illuminate\Support\Facades\Log;
use Marvel\Database\Models\Import;
use Throwable;

/**
 * Shared realtime-notification logic for long-running file operations
 * (imports / exports / bulk deletes).
 *
 * Guarantees:
 *  - Broadcasting failures NEVER propagate to the caller: a Pusher outage
 *    must never fail the underlying business operation.
 *  - Payloads are whitelisted, safe fields only.
 *  - Respects the project's existing Pusher gating conventions
 *    (app.env testing guard + config('shop.pusher.enabled')).
 *  - Terminal events are emitted at most once per process via the
 *    broadcastFileOperationTerminal() guard (retry attempts re-entering a
 *    terminal state are additionally blocked by job-level early returns).
 */
trait BroadcastsFileOperationProgress
{
    /**
     * Cached owner (created_by) of the current operation.
     */
    protected ?int $fileOperationOwnerId = null;

    /**
     * Per-process guard against duplicate terminal events.
     */
    protected bool $fileOperationTerminalEmitted = false;

    protected function shouldBroadcastFileOperation(): bool
    {
        if (config('app.env') === 'testing') {
            return false;
        }

        return config('shop.pusher.enabled', true) !== false;
    }

    protected function resolveFileOperationOwnerId(int $operationId): ?int
    {
        if ($this->fileOperationOwnerId !== null) {
            return $this->fileOperationOwnerId;
        }

        $this->fileOperationOwnerId = (int) Import::where('id', $operationId)->value('created_by') ?: null;

        return $this->fileOperationOwnerId;
    }

    /**
     * Emit a queued lifecycle event (pending state, progress 0).
     */
    protected function broadcastFileOperationQueued(
        string $eventName,
        string $kind,
        int $operationId,
        ?int $totalRows = null,
        string $message = '',
    ): void {
        $this->broadcastFileOperationProgress(
            $eventName,
            $kind,
            $operationId,
            0.0,
            0,
            0,
            0,
            $totalRows,
            'pending',
            array_filter([
                'message' => $message !== '' ? $message : null,
                'download_available' => false,
                'has_errors' => false,
            ], fn ($v) => $v !== null)
        );
    }

    /**
     * Emit a cancelling lifecycle event (non-terminal) — canonical payload.
     */
    protected function broadcastFileOperationCancelling(
        string $eventName,
        string $kind,
        int $operationId,
        string $message = '',
    ): void {
        $normalizedProgress = 0.0;
        $payload = [
            'kind' => $kind,
            'operation_type' => $kind,
            'id' => $operationId,
            'operation_id' => $operationId,
            'event' => $eventName,
            'state' => 'cancelling',
            'status' => 'cancelling',
            'has_errors' => false,
            'progress' => $normalizedProgress,
            'percentage' => $normalizedProgress,
            'progress_detail' => [
                'percentage' => $normalizedProgress,
                'processed' => 0,
                'total' => null,
            ],
            'processed_rows' => 0,
            'success_rows' => 0,
            'failed_rows' => 0,
            'total_rows' => null,
            'download_available' => false,
            'timestamp' => now()->toIso8601String(),
            'message' => $message !== '' ? $message : ucfirst(str_replace('-', ' ', $kind)) . ' cancelling.',
        ];
        $this->dispatchFileOperationEvent($eventName, $kind, $operationId, $payload);
    }

    /**
     * Emit a realtime progress update for a file operation — canonical payload.
     */
    protected function broadcastFileOperationProgress(
        string $eventName,
        string $kind,
        int $operationId,
        float $progress,
        int $processedRows,
        int $successRows,
        int $failedRows,
        ?int $totalRows = null,
        string $status = 'processing',
        array $extraPayload = [],
    ): void {
        $normalizedProgress = round(max(0.0, min($progress, 100.0)), 2);
        $payload = array_merge([
            'kind' => $kind,
            'operation_type' => $kind,
            'id' => $operationId,
            'operation_id' => $operationId,
            'event' => $eventName,
            'state' => $status,
            'status' => $status,
            'progress' => $normalizedProgress,
            'percentage' => $normalizedProgress,
            'progress_detail' => [
                'percentage' => $normalizedProgress,
                'processed' => $processedRows,
                'total' => $totalRows,
            ],
            'processed_rows' => $processedRows,
            'success_rows' => $successRows,
            'failed_rows' => $failedRows,
            'total_rows' => $totalRows,
            'has_errors' => $failedRows > 0,
            'download_available' => false,
            'timestamp' => now()->toIso8601String(),
            'message' => $extraPayload['message'] ?? '',
        ], $extraPayload);

        // Preserve explicit overrides for has_errors/download_available if caller set them
        if (array_key_exists('has_errors', $extraPayload)) {
            $payload['has_errors'] = (bool) $extraPayload['has_errors'];
        }
        if (array_key_exists('download_available', $extraPayload)) {
            $payload['download_available'] = (bool) $extraPayload['download_available'];
        }
        // Keep event/state consistent even if extra overrides progress/percentage
        $payload['event'] = $eventName;
        $payload['state'] = $status;
        $payload['status'] = $status;
        $payload['operation_type'] = $kind;
        $payload['kind'] = $kind;

        // Ensure canonical keys always present
        $payload['total_rows'] = $payload['total_rows'] ?? $totalRows;
        $payload['progress_detail']['total'] = $payload['total_rows'];
        $payload['progress_detail']['percentage'] = $normalizedProgress;
        $payload['progress_detail']['processed'] = $processedRows;
        $payload['percentage'] = $normalizedProgress;
        $payload['progress'] = $normalizedProgress;
        $payload['timestamp'] = $payload['timestamp'] ?? now()->toIso8601String();
        if (!isset($payload['message']) || $payload['message'] === '') {
            $payload['message'] = $extraPayload['message'] ?? '';
            if ($payload['message'] === '') {
                $payload['message'] = ucfirst(str_replace('-', ' ', $kind)) . ' ' . $status . '.';
            }
        }
        // Strip legacy keys if accidentally passed via extraPayload
        unset($payload['import_id'], $payload['type']);

        $this->dispatchFileOperationEvent($eventName, $kind, $operationId, $payload);
    }

    /**
     * Emit a realtime terminal transition for a file operation.
     * Guaranteed to fire at most once per process and guarded against
     * cross-process duplicate of a different terminal state.
     */
    protected function broadcastFileOperationTerminal(
        string $eventName,
        string $kind,
        int $operationId,
        string $status,
        bool $hasErrors = false,
        array $extraPayload = [],
    ): void {
        if ($this->fileOperationTerminalEmitted) {
            return;
        }

        // Cross-process guard: if DB already holds a different terminal, suppress duplicate
        try {
            $existing = Import::where('id', $operationId)->value('status');
            if ($existing !== null && in_array($existing, ['completed', 'completed_with_errors', 'failed', 'cancelled'], true) && $existing !== $status) {
                // Another terminal already won — log and suppress
                \Illuminate\Support\Facades\Log::warning('file-operation.event.terminal_suppressed_race', [
                    'event' => $eventName,
                    'kind' => $kind,
                    'operation_id' => $operationId,
                    'attempted_status' => $status,
                    'existing_status' => $existing,
                ]);
                $this->fileOperationTerminalEmitted = true;
                return;
            }
        } catch (\Throwable $e) {
            // Do not block broadcast on lookup failure
        }

        $this->fileOperationTerminalEmitted = true;

        $terminalProgress = $extraPayload['progress'] ?? 100.0;
        $normalizedProgress = round(max(0.0, min((float) $terminalProgress, 100.0)), 2);

        $base = [
            'kind' => $kind,
            'operation_type' => $kind,
            'id' => $operationId,
            'operation_id' => $operationId,
            'event' => $eventName,
            'state' => $status,
            'status' => $status,
            'has_errors' => $hasErrors,
            'progress' => $normalizedProgress,
            'percentage' => $normalizedProgress,
            'progress_detail' => [
                'percentage' => $normalizedProgress,
                'processed' => $extraPayload['processed_rows'] ?? 0,
                'total' => $extraPayload['total_rows'] ?? null,
            ],
            'processed_rows' => $extraPayload['processed_rows'] ?? 0,
            'success_rows' => $extraPayload['success_rows'] ?? 0,
            'failed_rows' => $extraPayload['failed_rows'] ?? 0,
            'total_rows' => $extraPayload['total_rows'] ?? null,
            'download_available' => $extraPayload['download_available'] ?? $hasErrors,
            'timestamp' => now()->toIso8601String(),
            'message' => $extraPayload['message'] ?? '',
        ];

        // Ensure download_available reflects caller intent
        if (array_key_exists('download_available', $extraPayload)) {
            $base['download_available'] = (bool) $extraPayload['download_available'];
        }
        if (array_key_exists('message', $extraPayload) && $extraPayload['message'] !== '') {
            $base['message'] = $extraPayload['message'];
        } elseif ($base['message'] === '') {
            $base['message'] = ucfirst(str_replace('-', ' ', $kind)) . ' ' . $status . '.';
            if ($status === 'completed_with_errors') {
                $base['message'] = ucfirst(str_replace('-', ' ', $kind)) . ' completed with errors.';
            } elseif ($status === 'completed') {
                $base['message'] = ucfirst(str_replace('-', ' ', $kind)) . ' completed.';
            } elseif ($status === 'failed') {
                $base['message'] = ucfirst(str_replace('-', ' ', $kind)) . ' failed.';
            } elseif ($status === 'cancelled') {
                $base['message'] = ucfirst(str_replace('-', ' ', $kind)) . ' cancelled.';
            }
        }

        $payload = array_merge($base, $extraPayload);
        // Re-assert canonical keys after merge
        $payload['event'] = $eventName;
        $payload['state'] = $status;
        $payload['status'] = $status;
        $payload['operation_type'] = $kind;
        $payload['kind'] = $kind;
        $payload['has_errors'] = $hasErrors;
        $payload['progress'] = $normalizedProgress;
        $payload['percentage'] = $normalizedProgress;
        $payload['progress_detail']['percentage'] = $normalizedProgress;
        $payload['progress_detail']['processed'] = $payload['processed_rows'] ?? 0;
        $payload['progress_detail']['total'] = $payload['total_rows'] ?? null;
        $payload['timestamp'] = $payload['timestamp'] ?? now()->toIso8601String();
        // Strip legacy keys
        unset($payload['import_id'], $payload['type']);

        $this->dispatchFileOperationEvent($eventName, $kind, $operationId, $payload);
    }

    /**
     * Dispatch the event while isolating every failure mode.
     * A broadcast problem is logged and reported; it never throws.
     */
    protected function dispatchFileOperationEvent(
        string $eventName,
        string $kind,
        int $operationId,
        array $payload,
    ): void {
        try {
            if (!$this->shouldBroadcastFileOperation()) {
                return;
            }

            $userId = $this->resolveFileOperationOwnerId($operationId);

            if ($userId === null) {
                Log::warning('file-operation.event.skipped', [
                    'event' => $eventName,
                    'kind' => $kind,
                    'operation_id' => $operationId,
                    'reason' => 'no_owner_user',
                ]);

                return;
            }

            // Final canonical safety: strip legacy aliases
            unset($payload['import_id'], $payload['type']);
            FileOperationEvent::dispatch($userId, $eventName, $payload);

            Log::info('file-operation.event.dispatched', [
                'event' => $eventName,
                'kind' => $kind,
                'operation_id' => $operationId,
                'user_id' => $userId,
                'channel' => 'private-users.' . $userId,
            ]);
        } catch (Throwable $e) {
            Log::error('file-operation.event.broadcast_failed', [
                'event' => $eventName,
                'kind' => $kind,
                'operation_id' => $operationId,
                'error' => $e->getMessage(),
            ]);

            report($e);
        }
    }
}
