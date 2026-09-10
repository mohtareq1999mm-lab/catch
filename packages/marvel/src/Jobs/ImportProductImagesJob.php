<?php

namespace Marvel\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
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
        $this->onQueue('meem-medium');
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
    }
}
