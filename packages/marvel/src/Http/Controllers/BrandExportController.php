<?php

namespace Marvel\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Marvel\Database\Models\Import;
use Marvel\Enums\FileOperationType;
use Marvel\Enums\ImportType;
use Marvel\Enums\Permission;
use Marvel\Enums\Role;
use Marvel\Jobs\ExportBrandsJob;
use Marvel\Traits\ApiResponse;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class BrandExportController extends Controller
{
    use ApiResponse;

    public function __construct()
    {
        $this->middleware('auth:sanctum');
        $this->middleware('permission:' . Permission::EXPORT_BRAND);
    }

    public function export(\Illuminate\Http\Request $request): JsonResponse
    {
        $idempotencyKey = $request->header('Idempotency-Key') ?: $request->header('X-Idempotency-Key');

        if ($idempotencyKey) {
            $cacheKey = 'idempotency:brand-export:' . $request->user()->id . ':' . $idempotencyKey;

            if (Cache::has($cacheKey)) {
                $cachedId = Cache::get($cacheKey);
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
            Cache::put('idempotency:brand-export:' . $request->user()->id . ':' . $idempotencyKey, $exportOperation->id, now()->addHours(24));
        }

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

        $isTerminal = in_array($exportOperation->status, ['completed', 'completed_with_errors', 'failed', 'cancelled'], true);

        return response()
            ->json([
                'status' => 200,
                'message' => __('message.MESSAGE.BRAND_EXPORT_STATUS_FETCHED'),
                'success' => true,
                'data' => [
                    'id' => $exportOperation->id,
                    'status' => $exportOperation->status,
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
}
