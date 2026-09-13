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
use Marvel\Services\Import\ProductImportService;
use Throwable;

class ImportProductImagesJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;
    public int $timeout = 600;
    public array $backoff = [30, 60, 120];

    protected int $importId;
    protected array $imageRows; // each: ['product_sku'=>..., 'image'=>..., 'row'=>...]

    public function __construct(int $importId, array $imageRows)
    {
        $this->importId = $importId;
        $this->imageRows = $imageRows;
        $this->onQueue(config('queue.queues.medium'));
    }

    public function handle(): void
    {
        $import = Import::find($this->importId);
        if (!$import || $import->status === 'cancelled') {
            return;
        }

        $service = new ProductImportService($this->importId);

        foreach ($this->imageRows as $row) {
            $sku = $row['product_sku'] ?? '';
            $image = $row['image'] ?? '';
            $rowIndex = $row['row'] ?? 0;
            if (empty($sku) || empty($image)) {
                continue;
            }
            $service->processProductImage((string) $sku, (string) $image, (int) $rowIndex);
        }

        // Merge image errors into import errors (append, keep product counters intact)
        try {
            $import->refresh();
            $existing = $import->errors ?? [];
            if (is_string($existing)) {
                $existing = json_decode($existing, true) ?? [];
            }
            $newErrors = $service->getImageErrors();
            if (!empty($newErrors)) {
                $merged = array_merge($existing, $newErrors);
                $import->update(['errors' => array_slice($merged, 0, 2000)]);
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
        } catch (\Throwable $e) { report($e); }
    }
}
