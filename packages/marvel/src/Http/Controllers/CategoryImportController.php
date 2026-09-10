<?php

namespace Marvel\Http\Controllers;

use App\Events\FileOperationEvent;
use App\Http\Controllers\Controller;
use App\Traits\BroadcastsFileOperationProgress;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Storage;
use Marvel\Database\Models\Import;
use Marvel\Enums\FileOperationType;
use Marvel\Enums\ImportType;
use Marvel\Enums\Permission;
use Marvel\Enums\Role;
use Marvel\Http\Requests\CategoryImportRequest;
use Marvel\Jobs\ImportCategoriesJob;
use Marvel\Traits\ApiResponse;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\File\Exception\FileNotFoundException;

class CategoryImportController extends Controller
{
    use ApiResponse, BroadcastsFileOperationProgress;

    public function __construct()
    {
        $this->middleware('auth:sanctum');
        $this->middleware('permission:' . Permission::IMPORT_CATEGORY);
    }

    protected function readSignalFile(int $importId, string $signalType): ?array
    {
        $path = storage_path("app/imports/{$signalType}_{$importId}.json");
        clearstatcache(true, $path);

        if (!file_exists($path)) {
            return null;
        }

        try {
            $contents = file_get_contents($path);

            return json_decode($contents, true) ?: null;
        } catch (\Throwable $e) {
            return null;
        }
    }

    protected function signalFileExists(int $importId, string $signalType): bool
    {
        $path = storage_path("app/imports/{$signalType}_{$importId}.json");
        clearstatcache(true, $path);

        return file_exists($path);
    }

    protected function writeSignalFile(int $importId, string $signalType, array $data = []): void
    {
        $dir = storage_path('app/imports');

        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }

        try {
            file_put_contents($dir . "/{$signalType}_{$importId}.json", json_encode($data), LOCK_EX);
        } catch (\Throwable $e) {
            report($e);
        }
    }

    public function import(CategoryImportRequest $request): JsonResponse
    {
        $file = $request->file('file');

        $filePath = $file->store('imports', 'imports');

        $import = Import::create([
            'type' => FileOperationType::CATEGORY_IMPORT,
            'file_path' => $filePath,
            'file_name' => $file->getClientOriginalName(),
            'status' => 'pending',
            'total_rows' => 0,
            'created_by' => $request->user()->id,
        ]);

        $this->writeSignalFile($import->id, 'progress', [
            'processed_rows' => 0,
            'success_rows' => 0,
            'failed_rows' => 0,
        ]);

        ImportCategoriesJob::dispatch($import->id);

        return $this->apiResponse(__('message.MESSAGE.CATEGORY_IMPORT_STARTED'), 202, true, [
            'import_id' => $import->id,
            'status' => $import->status,
        ]);
    }

    protected function estimateRowCount(string $filePath): int
    {
        try {
            $fullPath = Storage::disk('imports')->exists($filePath)
                ? Storage::disk('imports')->path($filePath)
                : Storage::disk('public')->path($filePath);

            if (!file_exists($fullPath)) {
                return 0;
            }

            $reader = \PhpOffice\PhpSpreadsheet\IOFactory::createReaderForFile($fullPath);
            $reader->setReadDataOnly(true);
            $spreadsheet = $reader->load($fullPath);

            $total = 0;

            foreach ($spreadsheet->getSheetNames() as $name) {
                $sheet = $spreadsheet->getSheetByName($name);

                if ($sheet) {
                    $total += $sheet->getHighestDataRow();
                }
            }

            $spreadsheet->disconnectWorksheets();
            unset($spreadsheet, $reader);

            return $total;
        } catch (\Throwable $e) {
            report($e);

            return 0;
        }
    }

    public function status(int $id): JsonResponse
    {
        $user = auth()->user();
        $baseQuery = Import::whereOperationType(FileOperationType::CATEGORY_IMPORT);
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
        $progressData = $this->readSignalFile($id, 'progress');

        $effectiveStatus = $cancelPending ? 'cancelling' : $import->status;

        if (in_array($import->status, ['completed', 'completed_with_errors'], true)) {
            $progress = 100.0;
        } elseif (in_array($import->status, ['failed', 'cancelled'], true)) {
            $progress = $progressData['progress'] ?? 0.0;
        } elseif ($progressData && $import->status === 'processing' && !$cancelPending) {
            $progress = $progressData['progress'] ?? 99.0;
        } else {
            $progress = 0.0;
        }

        $processedRows = $progressData['processed_rows'] ?? $import->processed_rows;
        $successRows = $progressData['success_rows'] ?? $import->success_rows;
        $failedRows = $progressData['failed_rows'] ?? $import->failed_rows;

        $isTerminal = in_array($import->status, ['completed', 'completed_with_errors', 'failed', 'cancelled'], true);

        return response()
            ->json([
                'status' => 200,
                'message' => __('message.MESSAGE.CATEGORY_IMPORT_STATUS_FETCHED'),
                'success' => true,
                'data' => [
                    'id' => $import->id,
                    'status' => $effectiveStatus,
                    'total_rows' => $import->total_rows,
                    'processed_rows' => $processedRows,
                    'successful_rows' => $successRows,
                    'failed_rows' => $failedRows,
                    'progress' => $progress,
                    'errors' => $import->errors,
                    'error_count' => is_array($import->errors) ? count($import->errors) : 0,
                    'created_at' => optional($import->created_at)->toIso8601String(),
                    'completed_at' => $isTerminal ? optional($import->updated_at)->toIso8601String() : null,
                ],
            ])
            ->header('Cache-Control', 'no-cache, no-store, must-revalidate')
            ->header('Pragma', 'no-cache')
            ->header('Expires', '0');
    }

    public function cancel(int $id): JsonResponse
    {
        $user = auth()->user();
        $baseQuery = Import::whereOperationType(FileOperationType::CATEGORY_IMPORT);
        if ($user && ! $user->hasRole(Role::SUPER_ADMIN)) {
            $baseQuery->where('created_by', $user->id);
        }
        $import = $baseQuery
            ->select(['id', 'status', 'created_by'])
            ->findOrFail($id);

        $this->authorize('view', $import);

        if (in_array($import->status, ['completed', 'completed_with_errors', 'failed', 'cancelled'], true)) {
            return $this->apiResponse(__('message.MESSAGE.IMPORT_CANNOT_CANCEL'), 409, false);
        }

        $this->writeSignalFile($import->id, 'cancel', ['cancelled_at' => now()->toIso8601String()]);

        try {
            $affected = Import::where('id', $import->id)
                ->whereIn('status', ['pending', 'processing'])
                ->update([
                    'status' => 'cancelled',
                ]);

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
            FileOperationEvent::CATEGORY_IMPORT_PROGRESS,
            'category-import',
            $import->id,
            'cancelled',
            false,
            [
                'type' => 'category',
                'import_id' => $import->id,
            ]
        );

        return $this->apiResponse(__('message.MESSAGE.IMPORT_CANCELLED_SUCCESSFULLY'), 200, true, [
            'import_id' => $import->id,
            'status' => 'cancelled',
        ]);
    }

    public function downloadErrors(int $id): BinaryFileResponse|JsonResponse
    {
        $user = auth()->user();
        $baseQuery = Import::whereOperationType(FileOperationType::CATEGORY_IMPORT);
        if ($user && ! $user->hasRole(Role::SUPER_ADMIN)) {
            $baseQuery->where('created_by', $user->id);
        }
        $import = $baseQuery
            ->select(['id', 'errors', 'created_by'])
            ->findOrFail($id);

        $this->authorize('view', $import);

        if (empty($import->errors)) {
            return $this->apiResponse(__('message.MESSAGE.IMPORT_NO_ERRORS'), 404, false);
        }

        $filename = "failed_category_import_rows_{$id}.xlsx";

        $errors = collect($import->errors);

        $export = new class($errors) implements \Maatwebsite\Excel\Concerns\FromCollection, \Maatwebsite\Excel\Concerns\WithHeadings {
            protected $errors;

            public function __construct($errors)
            {
                $this->errors = $errors;
            }

            public function collection()
            {
                return $this->errors->map(fn ($e) => [
                    'sheet' => $e['sheet'] ?? '',
                    'row' => $e['row'] ?? '',
                    'name_en' => $e['name_en'] ?? '',
                    'name_ar' => $e['name_ar'] ?? '',
                    'parent_name_en' => $e['parent_name_en'] ?? '',
                    'error_message' => $e['error_message'] ?? '',
                ]);
            }

            public function headings(): array
            {
                return ['Sheet', 'Row', 'Name (EN)', 'Name (AR)', 'Parent Name (EN)', 'Error Message'];
            }
        };

        \Maatwebsite\Excel\Facades\Excel::store($export, $filename, 'local');

        return response()->download(
            storage_path("app/{$filename}"),
            $filename,
            ['Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet']
        )->deleteFileAfterSend(true);
    }

    public function downloadSample(): BinaryFileResponse|JsonResponse
    {
        $samplePath = config('marvel.import.samples.category');

        if (!is_file($samplePath)) {
            return $this->apiResponse(
                __('message.IMPORT.SAMPLE_NOT_FOUND'),
                404,
                false
            );
        }

        return response()->download(
            $samplePath,
            'category-import-sample.xlsx',
            ['Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet']
        );
    }
}