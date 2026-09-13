<?php

namespace Marvel\Http\Controllers;

use App\Events\FileOperationEvent;
use App\Http\Controllers\Controller;
use App\Traits\BroadcastsFileOperationProgress;
use Illuminate\Http\JsonResponse;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Storage;
use Marvel\Database\Models\Import;
use Marvel\Enums\FileOperationType;
use Marvel\Enums\Permission;
use Marvel\Enums\Role;
use Marvel\Http\Requests\CategoryExportRequest;
use Marvel\Jobs\ExportCategoriesJob;
use Marvel\Traits\ApiResponse;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class CategoryExportController extends Controller
{
    use ApiResponse, BroadcastsFileOperationProgress;

    public function __construct()
    {
        $this->middleware('auth:sanctum');
        $this->middleware('permission:' . Permission::EXPORT_CATEGORY);
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

    public function export(CategoryExportRequest $request): JsonResponse
    {
        $import = Import::create([
            'type' => FileOperationType::CATEGORY_EXPORT,
            'file_path' => '',
            'file_name' => '',
            'status' => 'pending',
            'total_rows' => 0,
            'created_by' => $request->user()->id,
        ]);

        $this->broadcastFileOperationQueued(
            FileOperationEvent::CATEGORY_EXPORT_QUEUED,
            'category-export',
            $import->id,
            null,
            'Category export queued.'
        );

        ExportCategoriesJob::dispatch($import->id);

        return $this->apiResponse(__('message.MESSAGE.CATEGORY_EXPORT_STARTED'), 202, true, [
            'export_id' => $import->id,
            'status' => $import->status,
        ]);
    }

    public function status(int $id): JsonResponse
    {
        $user = auth()->user();
        $baseQuery = Import::whereOperationType(FileOperationType::CATEGORY_EXPORT);
        if ($user && ! $user->hasRole(Role::SUPER_ADMIN)) {
            $baseQuery->where('created_by', $user->id);
        }
        $import = $baseQuery
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

        $this->authorize('view', $import);

        $cancelPending = $this->signalFileExists($id, 'cancel');
        $effectiveStatus = $cancelPending ? 'cancelling' : $import->status;
        $isTerminal = in_array($import->status, ['completed', 'completed_with_errors', 'failed', 'cancelled'], true);

        return response()
            ->json([
                'status' => 200,
                'message' => __('message.MESSAGE.CATEGORY_EXPORT_STATUS_FETCHED'),
                'success' => true,
                'data' => [
                    'id' => $import->id,
                    'status' => $effectiveStatus,
                    'total_rows' => $import->total_rows,
                    'processed_rows' => $import->processed_rows,
                    'successful_rows' => $import->success_rows,
                    'failed_rows' => $import->failed_rows,
                    'errors' => $import->errors,
                    'created_at' => optional($import->created_at)->toIso8601String(),
                    'completed_at' => $isTerminal ? optional($import->updated_at)->toIso8601String() : null,
                ],
            ])
            ->header('Cache-Control', 'no-cache, no-store, must-revalidate')
            ->header('Pragma', 'no-cache')
            ->header('Expires', '0');
    }

    public function download(int $id): BinaryFileResponse|JsonResponse
    {
        $user = auth()->user();
        $baseQuery = Import::whereOperationType(FileOperationType::CATEGORY_EXPORT);
        if ($user && ! $user->hasRole(Role::SUPER_ADMIN)) {
            $baseQuery->where('created_by', $user->id);
        }
        $import = $baseQuery
            ->select(['id', 'status', 'file_path', 'file_name', 'created_by'])
            ->findOrFail($id);

        $this->authorize('view', $import);

        if ($import->status !== 'completed' || !$import->file_path || !Storage::disk('imports')->exists($import->file_path)) {
            return $this->apiResponse(__('message.MESSAGE.EXPORT_NOT_READY'), 409, false);
        }

        $filename = $import->file_name ?: basename($import->file_path);

        return response()->download(
            Storage::disk('imports')->path($import->file_path),
            $filename,
            ['Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet']
        );
    }

    public function cancel(int $id): JsonResponse
    {
        $user = auth()->user();
        $baseQuery = Import::whereOperationType(FileOperationType::CATEGORY_EXPORT);
        if ($user && ! $user->hasRole(Role::SUPER_ADMIN)) {
            $baseQuery->where('created_by', $user->id);
        }
        $import = $baseQuery
            ->select(['id', 'status', 'created_by'])
            ->findOrFail($id);
        $this->authorize('cancel', $import);
        if (in_array($import->status, ['completed', 'completed_with_errors', 'failed', 'cancelled'], true)) {
            return $this->apiResponse(__('message.MESSAGE.IMPORT_CANNOT_CANCEL'), 409, false);
        }
        $this->writeSignalFile($import->id, 'cancel', ['cancelled_at' => now()->toIso8601String()]);
        $this->broadcastFileOperationCancelling(
            FileOperationEvent::CATEGORY_EXPORT_CANCELLING,
            'category-export',
            $import->id,
            'Category export cancelling.'
        );
        try {
            $affected = Import::where('id', $import->id)
                ->whereIn('status', ['pending', 'processing'])
                ->update(['status' => 'cancelled']);
            if ($affected === 0) {
                $import->refresh();
                if ($import->isTerminal()) {
                    return $this->apiResponse(__('message.MESSAGE.IMPORT_CANNOT_CANCEL'), 409, false);
                }
            } else {
                $import->refresh();
            }
        } catch (QueryException $e) {
            report($e);
        }
        $this->broadcastFileOperationTerminal(
            FileOperationEvent::CATEGORY_EXPORT_CANCELLED,
            'category-export',
            $import->id,
            'cancelled',
            false,
            ['progress' => 100.0, 'download_available' => false]
        );
        return $this->apiResponse(__('message.MESSAGE.IMPORT_CANCELLED_SUCCESSFULLY'), 200, true, [
            'export_id' => $import->id,
            'status' => 'cancelled',
        ]);
    }
}