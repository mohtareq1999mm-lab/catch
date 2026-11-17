<?php

namespace Marvel\Jobs;

use App\Enums\FrontendResource;
use App\Services\General\HomeService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Marvel\Database\Models\Import;
use Marvel\Services\Import\ImageImporter;
use Marvel\Services\Import\ImportReport;
use Throwable;

class ImportProductImagesJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $timeout = 600;

    public array $backoff = [30, 60, 120];

    protected int $importId;

    /**
     * Each entry: ['product_sku' => ..., 'image' => ..., 'row' => ...].
     */
    protected array $imageRows;

    public function __construct(int $importId, array $imageRows)
    {
        $this->importId = $importId;
        $this->imageRows = $imageRows;
        $this->onQueue(config('queue.queues.medium'));
    }

    public function imageRows(): array
    {
        return $this->imageRows;
    }

    public function handle(): void
    {
        $import = Import::find($this->importId);
        if (!$import || $import->status === 'cancelled') {
            return;
        }

        // One importer per chunk: handler and report state stay with the seam.
        $importer = new ImageImporter(new ImportReport());
        $importer->ingest($this->imageRows);

        // Merge image errors into import errors (append, keep product counters
        // intact). The merge rule lives on the report, not the job.
        try {
            $import->refresh();
            $newErrors = $importer->report()->imageErrors();
            if (!empty($newErrors)) {
                $import->update(['errors' => ImportReport::mergePersisted($import->errors ?? [], $newErrors)]);
            }
        } catch (Throwable $e) {
            report($e);
        }

        // Media change affects product detail/listing caches
        $this->invalidateFrontendCaches();
    }

    protected function invalidateFrontendCaches(): void
    {
        try {
            $needsFlush = false;
            $tags = [FrontendResource::PRODUCTS->value];
            try {
                $resolver = app(\App\Services\General\ProductEngine\ProductStrategyResolver::class);
                foreach ($resolver->supportedTypes() as $type) { $tags[] = FrontendResource::PRODUCTS->value . '_' . $type; }
            } catch (\Throwable $e) {}
            $tags = array_merge($tags, [FrontendResource::CATEGORIES->value, FrontendResource::BRANDS->value, FrontendResource::BRANDS_PRODUCTS->value]);
            foreach ($tags as $tag) {
                try {
                    Cache::tags([$tag])->flush();
                    if (! Cache::getStore() instanceof \Illuminate\Cache\TaggableStore) { $needsFlush = true; }
                } catch (\BadMethodCallException) { $needsFlush = true; }
            }
            if ($needsFlush) Cache::flush();
            HomeService::clearCache();
            Cache::increment('api_cache_version');
        } catch (Throwable $e) { report($e); }
    }
}
