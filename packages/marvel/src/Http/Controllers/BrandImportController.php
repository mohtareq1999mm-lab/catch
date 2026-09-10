<?php

namespace Marvel\Http\Controllers;

use App\Events\FileOperationEvent;
use App\Http\Controllers\Controller;
use App\Traits\BroadcastsFileOperationProgress;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Marvel\Database\Models\Import;
use Marvel\Enums\FileOperationType;
use Marvel\Enums\ImportType;
use Marvel\Enums\Permission;
use Marvel\Enums\Role;
use Marvel\Http\Requests\BrandImportRequest;
use Marvel\Jobs\ImportBrandsJob;
use Marvel\Traits\ApiResponse;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\File\Exception\FileNotFoundException;

class BrandImportController extends Controller
{
    use ApiResponse, BroadcastsFileOperationProgress;

    public function __construct()
    {
        $this->middleware('auth:sanctum');
        $this->middleware('permission:' . Permission::IMPORT_BRAND);
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

    public function import(BrandImportRequest $request): JsonResponse
    {
        // Phase 16: Idempotency via header or recent duplicate file check
        $idempotencyKey = $request->header('Idempotency-Key') ?: $request->header('X-Idempotency-Key');

        $idempotencyCacheKey = null;
        $idempotencyLock = null;

        if ($idempotencyKey) {
            $idempotencyCacheKey = 'idempotency:brand-import:' . $request->user()->id . ':' . $idempotencyKey;
            $lockKey = 'lock:' . $idempotencyCacheKey;

            try {
                $idempotencyLock = Cache::lock($lockKey, 10);
                $idempotencyLock->block(5);
            } catch (\Throwable $e) {
                $idempotencyLock = null;
            }

            if (Cache::has($idempotencyCacheKey)) {
                $cachedId = Cache::get($idempotencyCacheKey);
                $existing = Import::whereOperationType(FileOperationType::BRAND_IMPORT)->where('id', $cachedId)->first();

                if ($existing) {
                    if ($idempotencyLock) {
                        try {
                            $idempotencyLock->release();
                        } catch (\Throwable $e) {
                        }
                    }

                    return $this->apiResponse(__('message.MESSAGE.BRAND_IMPORT_STARTED'), 202, true, [
                        'import_id' => $existing->id,
                        'status' => $existing->status,
                    ]);
                }
            }
            // Hold lock until after creation (released after Cache::put)
        }

        $file = $request->file('file');

        // Check for recent duplicate upload (same user, same file hash, pending/processing within 10 min)
        try {
            $fileHash = hash_file('sha256', $file->getRealPath());

            $recentDuplicate = Import::whereOperationType(FileOperationType::BRAND_IMPORT)
                ->where('created_by', $request->user()->id)
                ->whereIn('status', ['pending', 'processing'])
                ->where('created_at', '>', now()->subMinutes(10))
                ->latest('id')
                ->first();

            // If recent import exists with same file hash (stored via cache mapping), reuse
            if ($recentDuplicate) {
                $hashCacheKey = 'brand-import:hash:' . $request->user()->id . ':' . $fileHash;

                if (Cache::has($hashCacheKey)) {
                    $cachedId = Cache::get($hashCacheKey);

                    if ((int) $cachedId === (int) $recentDuplicate->id) {
                        if ($idempotencyKey) {
                            Cache::put('idempotency:brand-import:' . $request->user()->id . ':' . $idempotencyKey, $recentDuplicate->id, now()->addHours(24));
                        }

                        return $this->apiResponse(__('message.MESSAGE.BRAND_IMPORT_STARTED'), 202, true, [
                            'import_id' => $recentDuplicate->id,
                            'status' => $recentDuplicate->status,
                        ]);
                    }
                }
            }
        } catch (\Throwable $e) {
            // Hashing failed — proceed without deduplication
        }

        $filePath = $file->store('imports', 'imports');

        $import = Import::create([
            'type' => FileOperationType::BRAND_IMPORT,
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

        // Store idempotency mappings
        if ($idempotencyKey && $idempotencyCacheKey) {
            Cache::put($idempotencyCacheKey, $import->id, now()->addHours(24));

            if ($idempotencyLock) {
                try {
                    $idempotencyLock->release();
                } catch (\Throwable $e) {
                }
            }
        } elseif ($idempotencyLock) {
            try {
                $idempotencyLock->release();
            } catch (\Throwable $e) {
            }
        }

        if (isset($fileHash)) {
            Cache::put('brand-import:hash:' . $request->user()->id . ':' . $fileHash, $import->id, now()->addMinutes(10));
        }

        ImportBrandsJob::dispatch($import->id);

        return $this->apiResponse(__('message.MESSAGE.BRAND_IMPORT_STARTED'), 202, true, [
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
        $baseQuery = Import::whereOperationType(FileOperationType::BRAND_IMPORT);
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
                'message' => __('message.MESSAGE.BRAND_IMPORT_STATUS_FETCHED'),
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
        $baseQuery = Import::whereOperationType(FileOperationType::BRAND_IMPORT);
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
            FileOperationEvent::BRAND_IMPORT_PROGRESS,
            'brand-import',
            $import->id,
            'cancelled',
            false
        );

        return $this->apiResponse(__('message.MESSAGE.IMPORT_CANCELLED_SUCCESSFULLY'), 200, true, [
            'import_id' => $import->id,
            'status' => 'cancelled',
        ]);
    }

    public function downloadErrors(int $id): BinaryFileResponse|JsonResponse
    {
        $user = auth()->user();
        $baseQuery = Import::whereOperationType(FileOperationType::BRAND_IMPORT);
        if ($user && ! $user->hasRole(Role::SUPER_ADMIN)) {
            $baseQuery->where('created_by', $user->id);
        }
        $import = $baseQuery
            ->select(['id', 'errors', 'created_by'])
            ->findOrFail($id);

        $this->authorize('download', $import);

        if (empty($import->errors)) {
            return $this->apiResponse(__('message.MESSAGE.IMPORT_NO_ERRORS'), 404, false);
        }

        $filename = "failed_brand_import_rows_{$id}_" . uniqid() . ".xlsx";

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
                    'error_message' => $e['error_message'] ?? '',
                ]);
            }

            public function headings(): array
            {
                return ['Sheet', 'Row', 'Name (EN)', 'Name (AR)', 'Error Message'];
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
        $samplePath = config('marvel.import.samples.brand');

        if (!is_file($samplePath)) {
            return $this->apiResponse(
                __('message.IMPORT.SAMPLE_NOT_FOUND'),
                404,
                false
            );
        }

        return response()->download(
            $samplePath,
            'brand-import-sample.xlsx',
            ['Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet']
        );
    }
}
