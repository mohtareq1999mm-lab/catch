<?php

namespace Marvel\Http\Controllers;

use App\Events\FileOperationEvent;
use App\Http\Controllers\Controller;
use App\Traits\BroadcastsFileOperationProgress;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Marvel\Database\Models\Import;
use Marvel\Enums\FileOperationType;
use Marvel\Enums\Permission;
use Marvel\Enums\Role;
use Marvel\Http\Requests\ProductExportRequest;
use Marvel\Jobs\ExportProductsJob;
use Marvel\Traits\ApiResponse;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class ProductExportController extends Controller
{
    use ApiResponse, BroadcastsFileOperationProgress;

    public function __construct()
    {
        $this->middleware('auth:sanctum');
        $this->middleware('permission:' . Permission::EXPORT_PRODUCT);
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
        // Validate filters via ProductExportRequest rules manually to support both GET/POST
        $validator = \Illuminate\Support\Facades\Validator::make($request->all(), (new ProductExportRequest())->rules());
        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors(),
            ], 422);
        }
        $filters = $validator->validated();

        $idempotencyKey = $request->header('Idempotency-Key') ?: $request->header('X-Idempotency-Key');
        if ($idempotencyKey) {
            $cacheKey = 'idempotency:product-export:' . $request->user()->id . ':' . $idempotencyKey;
            if (Cache::store('file')->has($cacheKey)) {
                $cachedId = Cache::store('file')->get($cacheKey);
                $existing = Import::whereOperationType(FileOperationType::PRODUCT_EXPORT)->where('id', $cachedId)->first();
                if ($existing) {
                    return $this->apiResponse(__('message.MESSAGE.EXPORT_STARTED_SUCCESSFULLY'), 202, true, [
                        'export_id' => $existing->id,
                        'status' => $existing->status,
                    ]);
                }
            }
        }

        $exportOperation = Import::create([
            'type' => FileOperationType::PRODUCT_EXPORT,
            'file_path' => '',
            'file_name' => '',
            'status' => 'pending',
            'total_rows' => 0,
            'created_by' => $request->user()->id,
        ]);

        if ($idempotencyKey) {
            Cache::store('file')->put('idempotency:product-export:' . $request->user()->id . ':' . $idempotencyKey, $exportOperation->id, now()->addHours(24));
        }

        // Persist filters in errors field temporarily? Instead pass via job constructor filters
        // Store filters in file_name placeholder or cache; we pass via job
        // For idempotent replay we need to persist filters — store as json in errors temporarily or add column? Use cache.
        if (!empty($filters)) {
            Cache::store('file')->put('product-export:filters:' . $exportOperation->id, $filters, now()->addHours(2));
        }

        $this->broadcastFileOperationQueued(
            FileOperationEvent::PRODUCT_EXPORT_QUEUED,
            'product-export',
            $exportOperation->id,
            $exportOperation->total_rows ?: null,
            'Product export queued.'
        );

        ExportProductsJob::dispatch($exportOperation->id, $filters);

        return $this->apiResponse(__('message.MESSAGE.EXPORT_STARTED_SUCCESSFULLY'), 202, true, [
            'export_id' => $exportOperation->id,
            'status' => $exportOperation->status,
        ]);
    }

    public function status(int $id): JsonResponse
    {
        $user = auth()->user();
        $baseQuery = Import::whereOperationType(FileOperationType::PRODUCT_EXPORT);
        if ($user && ! $user->hasRole(Role::SUPER_ADMIN)) {
            $baseQuery->where('created_by', $user->id);
        }
        $exportOperation = $baseQuery
            ->select(['id', 'status', 'total_rows', 'processed_rows', 'success_rows', 'failed_rows', 'errors', 'created_at', 'updated_at', 'created_by'])
            ->findOrFail($id);

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
        $baseQuery = Import::whereOperationType(FileOperationType::PRODUCT_EXPORT);
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
        $baseQuery = Import::whereOperationType(FileOperationType::PRODUCT_EXPORT);
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
            FileOperationEvent::PRODUCT_EXPORT_CANCELLING,
            'product-export',
            $exportOperation->id,
            'Product export cancelling.'
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
            FileOperationEvent::PRODUCT_EXPORT_CANCELLED,
            'product-export',
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
