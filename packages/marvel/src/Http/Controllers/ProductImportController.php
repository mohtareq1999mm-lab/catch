<?php

namespace Marvel\Http\Controllers;

use App\Events\FileOperationEvent;
use App\Http\Controllers\Controller;
use App\Traits\BroadcastsFileOperationProgress;
use Marvel\Database\Models\Import;
use Marvel\Enums\FileOperationType;
use Marvel\Enums\ImportType;
use Marvel\Http\Requests\ProductImportRequest;
use Marvel\Jobs\ImportProductsJob;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Marvel\Enums\Permission;
use Marvel\Enums\Role;
use Marvel\Traits\ApiResponse;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\File\Exception\FileNotFoundException;

class ProductImportController extends Controller
{
    use ApiResponse, BroadcastsFileOperationProgress;

    public function __construct()
    {
        $this->middleware('auth:sanctum');
        $this->middleware('permission:' . Permission::IMPORT_PRODUCT);
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

    public function import(ProductImportRequest $request): JsonResponse
    {
        $idempotencyKey = $request->header('Idempotency-Key') ?: $request->header('X-Idempotency-Key');
        $idempotencyCacheKey = null;
        $idempotencyLock = null;

        if ($idempotencyKey) {
            $idempotencyCacheKey = 'idempotency:product-import:' . $request->user()->id . ':' . $idempotencyKey;
            $lockKey = 'lock:' . $idempotencyCacheKey;
            try {
                $idempotencyLock = Cache::lock($lockKey, 10);
                $idempotencyLock->block(5);
            } catch (\Throwable $e) {
                $idempotencyLock = null;
            }
            if (Cache::has($idempotencyCacheKey)) {
                $cachedId = Cache::get($idempotencyCacheKey);
                $existing = Import::whereOperationType(FileOperationType::PRODUCT_IMPORT)->where('id', $cachedId)->first();
                if ($existing) {
                    if ($idempotencyLock) {
                        try { $idempotencyLock->release(); } catch (\Throwable $e) {}
                    }
                    return $this->apiResponse(__('message.MESSAGE.IMPORT_STARTED_SUCCESSFULLY'), 202, true, [
                        'import_id' => $existing->id,
                        'status' => $existing->status,
                    ]);
                }
            }
        }

        $file = $request->file('file');

        $fileHash = null;
        try {
            $fileHash = hash_file('sha256', $file->getRealPath());
            $recentDuplicate = Import::whereOperationType(FileOperationType::PRODUCT_IMPORT)
                ->where('created_by', $request->user()->id)
                ->whereIn('status', ['pending', 'processing'])
                ->where('created_at', '>', now()->subMinutes(10))
                ->latest('id')
                ->first();
            if ($recentDuplicate) {
                $hashCacheKey = 'product-import:hash:' . $request->user()->id . ':' . $fileHash;
                if (Cache::has($hashCacheKey)) {
                    $cachedId = Cache::get($hashCacheKey);
                    if ((int) $cachedId === (int) $recentDuplicate->id) {
                        if ($idempotencyKey) {
                            Cache::put('idempotency:product-import:' . $request->user()->id . ':' . $idempotencyKey, $recentDuplicate->id, now()->addHours(24));
                        }
                        if ($idempotencyLock) {
                            try { $idempotencyLock->release(); } catch (\Throwable $e) {}
                        }
                        return $this->apiResponse(__('message.MESSAGE.IMPORT_STARTED_SUCCESSFULLY'), 202, true, [
                            'import_id' => $recentDuplicate->id,
                            'status' => $recentDuplicate->status,
                        ]);
                    }
                }
            }
        } catch (\Throwable $e) {
        }

        $filePath = $file->store('imports', 'imports');

        $import = Import::create([
            'type' => FileOperationType::PRODUCT_IMPORT,
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

        if ($idempotencyKey && $idempotencyCacheKey) {
            Cache::put($idempotencyCacheKey, $import->id, now()->addHours(24));
            if ($idempotencyLock) {
                try { $idempotencyLock->release(); } catch (\Throwable $e) {}
            }
        } elseif ($idempotencyLock) {
            try { $idempotencyLock->release(); } catch (\Throwable $e) {}
        }

        if ($fileHash !== null) {
            Cache::put('product-import:hash:' . $request->user()->id . ':' . $fileHash, $import->id, now()->addMinutes(10));
        }

        ImportProductsJob::dispatch($import->id);

        return $this->apiResponse(__('message.MESSAGE.IMPORT_STARTED_SUCCESSFULLY'), 202, true, [
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
        $baseQuery = Import::whereOperationType(FileOperationType::PRODUCT_IMPORT);
        if ($user && ! $user->hasRole(Role::SUPER_ADMIN)) {
            $baseQuery->where('created_by', $user->id);
        }
        $import = $baseQuery
            ->select(['id', 'status', 'total_rows', 'processed_rows', 'success_rows', 'failed_rows', 'errors', 'created_by'])
            ->findOrFail($id);

        $this->authorize('view', $import);

        $cancelPending = $this->signalFileExists($id, 'cancel');
        $progressData = $this->readSignalFile($id, 'progress');

        $effectiveStatus = $cancelPending ? 'cancelling' : $import->status;

        if (in_array($import->status, ['completed', 'completed_with_errors'], true)) {
            $progress = 100.0;
        } elseif ($import->status === 'failed' || $import->status === 'cancelled') {
            $progress = $progressData['progress'] ?? 0.0;
        } elseif ($progressData && $import->status === 'processing' && !$cancelPending) {
            $progress = $progressData['progress'] ?? 99.0;
        } else {
            $progress = 0.0;
        }

        $processedRows = $progressData['processed_rows'] ?? $import->processed_rows;
        $successRows = $progressData['success_rows'] ?? $import->success_rows;
        $failedRows = $progressData['failed_rows'] ?? $import->failed_rows;

        // Invariant enforcement (4104→4913 forensic): counters must not exceed total_rows
        $totalRows = (int) $import->total_rows;
        if ($totalRows > 0) {
            $processedRows = min((int) $processedRows, $totalRows);
            $successRows = min((int) $successRows, $totalRows);
            $failedRows = min((int) $failedRows, $totalRows);
            // Ensure processed = success + failed when possible
            if ($processedRows !== ($successRows + $failedRows) && $import->status !== 'processing') {
                $processedRows = $successRows + $failedRows;
            }
            // Derive honest progress from counters when total is known
            if (in_array($import->status, ['processing'], true) && $totalRows > 0) {
                $derived = ($processedRows / $totalRows) * 99.0;
                // Use derived if it is more honest than signal (prevents 99 forever with inflated processed)
                if ($derived < $progress || $processedRows > $totalRows) {
                    $progress = round(min(max($derived, 0.0), 99.0), 2);
                }
            }
        }

        return response()
            ->json([
                'status' => 200,
                'message' => __('message.MESSAGE.IMPORT_STATUS_FETCHED'),
                'success' => true,
                'data' => [
                    'id' => $import->id,
                    'status' => $effectiveStatus,
                    'total_rows' => $import->total_rows,
                    'processed_rows' => $processedRows,
                    'successful_rows' => $successRows,
                    'success_rows' => $successRows,
                    'failed_rows' => $failedRows,
                    'progress' => $progress,
                    'errors' => $import->errors,
                    'error_count' => is_array($import->errors) ? count($import->errors) : 0,
                ],
            ])
            ->header('Cache-Control', 'no-cache, no-store, must-revalidate')
            ->header('Pragma', 'no-cache')
            ->header('Expires', '0');
    }

    public function downloadErrors(int $id): BinaryFileResponse|JsonResponse
    {
        $user = auth()->user();
        $baseQuery = Import::whereOperationType(FileOperationType::PRODUCT_IMPORT);
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

        $filename = "failed_import_rows_{$id}.xlsx";

        $errors = collect($import->errors);

        $export = new class($errors) implements \Maatwebsite\Excel\Concerns\FromCollection, \Maatwebsite\Excel\Concerns\WithHeadings {
            protected $errors;

            public function __construct($errors)
            {
                $this->errors = $errors;
            }

            public function collection()
            {
                return $this->errors->map(fn($e) => [
                    'sheet' => $e['sheet'] ?? '',
                    'row' => $e['row'] ?? '',
                    'sku' => $e['sku'] ?? '',
                    'error_message' => $e['error_message'] ?? '',
                ]);
            }

            public function headings(): array
            {
                return ['Sheet', 'Row', 'SKU', 'Error Message'];
            }
        };

        \Maatwebsite\Excel\Facades\Excel::store($export, $filename, 'local');

        return response()->download(
            storage_path("app/{$filename}"),
            $filename,
            ['Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet']
        )->deleteFileAfterSend(true);
    }

    public function cancel(int $id): JsonResponse
    {
        $user = auth()->user();
        $baseQuery = Import::whereOperationType(FileOperationType::PRODUCT_IMPORT);
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
            FileOperationEvent::PRODUCT_IMPORT_PROGRESS,
            'product-import',
            $import->id,
            'cancelled',
            false
        );

        return $this->apiResponse(__('message.MESSAGE.IMPORT_CANCELLED_SUCCESSFULLY'), 200, true, [
            'import_id' => $import->id,
            'status' => 'cancelled',
        ]);
    }

    public function downloadSample(): BinaryFileResponse|JsonResponse
    {
        $samplePath = config('marvel.import.samples.product');

        if (!is_file($samplePath)) {
            return $this->apiResponse(
                __('message.IMPORT.SAMPLE_NOT_FOUND'),
                404,
                false
            );
        }

        return response()->download(
            $samplePath,
            'product-import-sample.xlsx',
            ['Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet']
        );
    }
}
