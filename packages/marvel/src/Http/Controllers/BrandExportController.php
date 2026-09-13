<?php

namespace Marvel\Http\Controllers;

use App\Events\FileOperationEvent;
use App\Http\Controllers\Controller;
use App\Traits\BroadcastsFileOperationProgress;
use Illuminate\Http\JsonResponse;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Marvel\Database\Models\Import;
use Marvel\Enums\FileOperationType;
use Marvel\Enums\Permission;
use Marvel\Enums\Role;
use Marvel\Jobs\ExportBrandsJob;
use Marvel\Traits\ApiResponse;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class BrandExportController extends Controller
{
    use ApiResponse, BroadcastsFileOperationProgress;

    public function __construct()
    {
        $this->middleware('auth:sanctum');
        $this->middleware('permission:' . Permission::EXPORT_BRAND);
    }

    protected function readSignalFile(int $operationId, string $signalType): ?array
    {
        $path = storage_path("app/imports/{$signalType}_{$operationId}.json");
        clearstatcache(true, $path);
        if (!file_exists($path)) {
            return null;
        }
        try {
            return json_decode((string) file_get_contents($path), true) ?: null;
        } catch (\Throwable $e) {
            return null;
        }
    }

    protected function signalFileExists(int $operationId, string $signalType): bool
    {
        $path = storage_path("app/imports/{$signalType}_{$operationId}.json");
        clearstatcache(true, $path);
        return file_exists($path);
    }

    protected function writeSignalFile(int $operationId, string $signalType, array $data = []): void
    {
        $dir = storage_path('app/imports');
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }
        try {
            file_put_contents($dir . "/{$signalType}_{$operationId}.json", json_encode($data), LOCK_EX);
        } catch (\Throwable $e) {
            report($e);
        }
    }

    public function export(\Illuminate\Http\Request $request): JsonResponse
    {
        $idempotencyKey = $request->header('Idempotency-Key') ?: $request->header('X-Idempotency-Key');

        if ($idempotencyKey) {
            $cacheKey = 'idempotency:brand-export:' . $request->user()->id . ':' . $idempotencyKey;

            if (Cache::store('file')->has($cacheKey)) {
                $cachedId = Cache::store('file')->get($cacheKey);
                $existing = Import::whereOperationType(FileOperationType::BRAND_EXPORT)->where('id', $cachedId)->first();

                if ($existing) {
                    return $this->apiResponse(__('message.MESSAGE.BRAND_EXPORT_STARTED'), 202, true, [
                        'export_id' => $existing->id,
                        'status' => $existing->status,
                    ]);
                }
            }
        }

        // No automatic dedup for exports without Idempotency-Key — each request intentionally creates a new operation
        // Clients should send Idempotency-Key to safely retry

        $exportOperation = Import::create([
            'type' => FileOperationType::BRAND_EXPORT,
            'file_path' => '',
            'file_name' => '',
            'status' => 'pending',
            'total_rows' => 0,
            'created_by' => $request->user()->id,
        ]);

        if ($idempotencyKey) {
            Cache::store('file')->put('idempotency:brand-export:' . $request->user()->id . ':' . $idempotencyKey, $exportOperation->id, now()->addHours(24));
        }

        $this->broadcastFileOperationQueued(
            FileOperationEvent::BRAND_EXPORT_QUEUED,
            'brand-export',
            $exportOperation->id,
            null,
            'Brand export queued.'
        );

        ExportBrandsJob::dispatch($exportOperation->id);

        return $this->apiResponse(__('message.MESSAGE.BRAND_EXPORT_STARTED'), 202, true, [
            'export_id' => $exportOperation->id,
            'status' => $exportOperation->status,
        ]);
    }

    public function status(int $id): JsonResponse
    {
        $user = auth()->user();
        $baseQuery = Import::whereOperationType(FileOperationType::BRAND_EXPORT);
        if ($user && ! $user->hasRole(Role::SUPER_ADMIN)) {
            $baseQuery->where('created_by', $user->id);
        }
        $exportOperation = $baseQuery
            ->select([
                'id',
                'status',
                'total_rows',
                'processed_rows',
                'success_rows',
                'failed_rows',
                'errors',
                'created_at',
                'updated_at',
                'created_by',
            ])->findOrFail($id);

        $this->authorize('view', $exportOperation);

        $cancelPending = $this->signalFileExists($id, 'cancel');
        $effectiveStatus = $cancelPending ? 'cancelling' : $exportOperation->status;
        $isTerminal = in_array($exportOperation->status, ['completed', 'completed_with_errors', 'failed', 'cancelled'], true);

        return response()
            ->json([
                'status' => 200,
                'message' => __('message.MESSAGE.BRAND_EXPORT_STATUS_FETCHED'),
                'success' => true,
                'data' => [
                    'id' => $exportOperation->id,
                    'status' => $effectiveStatus,
                    'total_rows' => $exportOperation->total_rows,
                    'processed_rows' => $exportOperation->processed_rows,
                    'successful_rows' => $exportOperation->success_rows,
                    'failed_rows' => $exportOperation->failed_rows,
                    'errors' => $exportOperation->errors,
                    'error_count' => is_array($exportOperation->errors) ? count($exportOperation->errors) : 0,
                    'created_at' => optional($exportOperation->created_at)->toIso8601String(),
                    'completed_at' => $isTerminal ? optional($exportOperation->updated_at)->toIso8601String() : null,
                ],
            ])
            ->header('Cache-Control', 'no-cache, no-store, must-revalidate')
            ->header('Pragma', 'no-cache')
            ->header('Expires', '0');
    }

    public function download(int $id): BinaryFileResponse|JsonResponse
    {
        $user = auth()->user();
        $baseQuery = Import::whereOperationType(FileOperationType::BRAND_EXPORT);
        if ($user && ! $user->hasRole(Role::SUPER_ADMIN)) {
            $baseQuery->where('created_by', $user->id);
        }
        $exportOperation = $baseQuery
            ->select(['id', 'status', 'file_path', 'file_name', 'created_by'])
            ->findOrFail($id);

        $this->authorize('download', $exportOperation);

        if ($exportOperation->status !== 'completed' || ! $exportOperation->file_path || ! Storage::disk('imports')->exists($exportOperation->file_path)) {
            return $this->apiResponse(__('message.MESSAGE.EXPORT_NOT_READY'), 409, false);
        }

        $filename = $exportOperation->file_name ?: basename($exportOperation->file_path);

        return response()->download(
            Storage::disk('imports')->path($exportOperation->file_path),
            $filename,
            ['Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet']
        );
    }

    public function cancel(int $id): JsonResponse
    {
        $user = auth()->user();
        $baseQuery = Import::whereOperationType(FileOperationType::BRAND_EXPORT);
        if ($user && ! $user->hasRole(Role::SUPER_ADMIN)) {
            $baseQuery->where('created_by', $user->id);
        }
        $exportOperation = $baseQuery
            ->select(['id', 'status', 'created_by'])
            ->findOrFail($id);
        $this->authorize('cancel', $exportOperation);
        if (in_array($exportOperation->status, ['completed', 'completed_with_errors', 'failed', 'cancelled'], true)) {
            return $this->apiResponse(__('message.MESSAGE.IMPORT_CANNOT_CANCEL'), 409, false);
        }
        $this->writeSignalFile($exportOperation->id, 'cancel', ['cancelled_at' => now()->toIso8601String()]);
        $this->broadcastFileOperationCancelling(
            FileOperationEvent::BRAND_EXPORT_CANCELLING,
            'brand-export',
            $exportOperation->id,
            'Brand export cancelling.'
        );
        try {
            $affected = Import::where('id', $exportOperation->id)
                ->whereIn('status', ['pending', 'processing'])
                ->update(['status' => 'cancelled']);
            if ($affected === 0) {
                $exportOperation->refresh();
                if ($exportOperation->isTerminal()) {
                    return $this->apiResponse(__('message.MESSAGE.IMPORT_CANNOT_CANCEL'), 409, false);
                }
            } else {
                $exportOperation->refresh();
            }
        } catch (QueryException $e) {
            report($e);
        }
        $this->broadcastFileOperationTerminal(
            FileOperationEvent::BRAND_EXPORT_CANCELLED,
            'brand-export',
            $exportOperation->id,
            'cancelled',
            false,
            ['progress' => 100.0, 'download_available' => false]
        );
        return $this->apiResponse(__('message.MESSAGE.IMPORT_CANCELLED_SUCCESSFULLY'), 200, true, [
            'export_id' => $exportOperation->id,
            'status' => 'cancelled',
        ]);
    }
}
