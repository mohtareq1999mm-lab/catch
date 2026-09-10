<?php

namespace Marvel\Jobs;

use App\Events\FileOperationEvent;
use App\Traits\BroadcastsFileOperationProgress;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Marvel\Database\Models\Category;
use Marvel\Database\Models\Import;
use Throwable;

class BulkDeleteCategoriesJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;
    use BroadcastsFileOperationProgress;

    public int $tries = 3;

    public int $timeout = 900;

    public array $backoff = [30, 60, 120];

    protected const CHUNK_SIZE = 100;

    protected int $importId;

    public function __construct(int $importId)
    {
        $this->importId = $importId;
        $this->onQueue('meem-high');
    }

    protected function signalPath(string $type): ?string
    {
        return storage_path("app/imports/{$type}_{$this->importId}.json");
    }

    protected function writeSignal(string $type, array $data): void
    {
        $path = $this->signalPath($type);

        if ($path === null) {
            return;
        }

        $dir = dirname($path);

        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }

        try {
            file_put_contents($path, json_encode($data), LOCK_EX);
        } catch (Throwable $e) {
            report($e);
        }
    }

    protected function isCancelled(): bool
    {
        $path = $this->signalPath('cancel');

        if ($path === null) {
            return false;
        }

        clearstatcache(true, $path);

        return file_exists($path);
    }

    public function handle(): void
    {
        $import = Import::findOrFail($this->importId);

        if (in_array($import->status, ['completed', 'completed_with_errors', 'failed', 'cancelled'], true)) {
            return;
        }

        $import->update([
            'status' => 'processing',
            'processed_rows' => 0,
            'success_rows' => 0,
            'failed_rows' => 0,
        ]);

        $requestedIds = $this->loadRequestedIds($import);

        if ($this->isCancelled()) {
            $this->markCancelled($import);

            return;
        }

        $errors = [];
        $successCount = 0;
        $processedCount = 0;

        foreach (array_chunk($requestedIds, self::CHUNK_SIZE) as $chunk) {
            if ($this->isCancelled()) {
                break;
            }

            $categories = Category::withTrashed()
                ->whereIn('id', $chunk)
                ->orderBy('level', 'desc')
                ->orderBy('id', 'asc')
                ->get()
                ->keyBy('id');

            foreach ($categories as $category) {
                $id = (int) $category->id;

                if ($category->trashed()) {
                    $successCount++;
                    $processedCount++;

                    continue;
                }

                if ($category->children()->exists()) {
                    $errors[] = [
                        'category_id' => $id,
                        'category_name_en' => $this->categoryEnglishName($category),
                        'error' => __('message.MESSAGE.CATEGORY_BULK_DELETE_HAS_CHILDREN'),
                    ];
                    $processedCount++;

                    continue;
                }

                try {
                    $category->delete();
                    $successCount++;
                } catch (Throwable $e) {
                    report($e);

                    $errors[] = [
                        'category_id' => $id,
                        'category_name_en' => $this->categoryEnglishName($category),
                        'error' => $e->getMessage(),
                    ];
                }

                $processedCount++;
            }

            foreach ($chunk as $id) {
                if ($categories->has($id)) {
                    continue;
                }

                $errors[] = [
                    'category_id' => $id,
                    'category_name_en' => '',
                    'error' => __('message.MESSAGE.CATEGORY_BULK_DELETE_NOT_FOUND'),
                ];
                $processedCount++;
            }

            $this->writeSignal('progress', [
                'processed_rows' => $processedCount,
                'success_rows' => $successCount,
                'failed_rows' => count($errors),
                'progress' => $import->total_rows > 0 ? min(99.0, ($processedCount / $import->total_rows) * 100) : 99.0,
            ]);

            $this->broadcastFileOperationProgress(
                FileOperationEvent::CATEGORY_BULK_DELETE_PROGRESS,
                'category-bulk-delete',
                $this->importId,
                $import->total_rows > 0 ? min(99.0, ($processedCount / $import->total_rows) * 100) : 99.0,
                $processedCount,
                $successCount,
                count($errors),
                (int) $import->total_rows
            );
        }

        if ($this->isCancelled()) {
            $this->markCancelled($import);

            return;
        }

        $status = 'completed';

        if (!empty($errors) && $successCount > 0) {
            $status = 'completed_with_errors';
        } elseif (!empty($errors) && $successCount === 0) {
            $status = 'failed';
        }

        $import->update([
            'status' => $status,
            'processed_rows' => $processedCount,
            'success_rows' => $successCount,
            'failed_rows' => count($errors),
            'errors' => $errors,
        ]);

        $this->broadcastFileOperationTerminal(
            FileOperationEvent::CATEGORY_BULK_DELETE_COMPLETED,
            'category-bulk-delete',
            $this->importId,
            $status,
            !empty($errors),
            [
                'progress' => 100.0,
                'total_rows' => (int) $import->total_rows,
                'processed_rows' => $processedCount,
                'success_rows' => $successCount,
                'failed_rows' => count($errors),
            ]
        );
    }

    protected function loadRequestedIds(Import $import): array
    {
        $path = $this->signalPath('ids');

        if ($path === null || !file_exists($path)) {
            return [];
        }

        try {
            $data = json_decode((string) file_get_contents($path), true);

            if (is_array($data) && isset($data['ids']) && is_array($data['ids'])) {
                return array_values(array_filter(array_map('intval', $data['ids'])));
            }
        } catch (Throwable $e) {
        }

        return [];
    }

    protected function categoryEnglishName(Category $category): string
    {
        try {
            $name = $category->getTranslation('name', 'en', false);

            return is_string($name) ? $name : '';
        } catch (Throwable $e) {
            return '';
        }
    }

    protected function markCancelled(Import $import): void
    {
        $import->update([
            'status' => 'cancelled',
        ]);

        $this->broadcastFileOperationTerminal(
            FileOperationEvent::CATEGORY_BULK_DELETE_CANCELLED,
            'category-bulk-delete',
            $this->importId,
            'cancelled',
            false
        );
    }

    public function failed(Throwable $exception): void
    {
        $import = Import::find($this->importId);

        if ($import && $import->status === 'processing') {
            $import->update(['status' => 'failed']);

            $this->broadcastFileOperationTerminal(
                FileOperationEvent::CATEGORY_BULK_DELETE_FAILED,
                'category-bulk-delete',
                $this->importId,
                'failed',
                true
            );
        }
    }
}