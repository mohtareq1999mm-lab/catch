<?php

namespace Marvel\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Storage;
use Marvel\Database\Models\Import;
use Marvel\Enums\FileOperationType;
use Marvel\Enums\ImportType;
use Marvel\Enums\Permission;
use Marvel\Enums\Role;
use Marvel\Http\Requests\CategoryExportRequest;
use Marvel\Jobs\ExportCategoriesJob;
use Marvel\Traits\ApiResponse;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class CategoryExportController extends Controller
{
    use ApiResponse;

    public function __construct()
    {
        $this->middleware('auth:sanctum');
        $this->middleware('permission:' . Permission::EXPORT_CATEGORY);
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

        $isTerminal = in_array($import->status, ['completed', 'completed_with_errors', 'failed', 'cancelled'], true);

        return response()
            ->json([
                'status' => 200,
                'message' => __('message.MESSAGE.CATEGORY_EXPORT_STATUS_FETCHED'),
                'success' => true,
                'data' => [
                    'id' => $import->id,
                    'status' => $import->status,
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
}