<?php

namespace Marvel\Imports\Sheets;

use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\SkipsEmptyRows;
use Maatwebsite\Excel\Concerns\ToCollection;
use Maatwebsite\Excel\Concerns\WithChunkReading;
use Maatwebsite\Excel\Concerns\WithHeadingRow;
use Maatwebsite\Excel\Concerns\WithTitle;
use Marvel\Services\Import\ProductImportService;

class FlashSalesSheetImport implements ToCollection, WithTitle, WithHeadingRow, SkipsEmptyRows, WithChunkReading
{
    protected ProductImportService $service;

    public function __construct(ProductImportService $service)
    {
        $this->service = $service;
    }

    public function title(): string
    {
        return 'flash_sales';
    }

    public function collection(Collection $rows): void
    {
        $grouped = $rows->groupBy(fn($row) => $row['product_sku'] ?? '');

        foreach ($grouped as $sku => $flashSaleRows) {
            if (empty($sku)) {
                continue;
            }
            $slugs = $flashSaleRows->pluck('flash_sale_slug')->filter()->toArray();
            $this->service->queueFlashSales($sku, $slugs);
        }
    }

    public function chunkSize(): int
    {
        return 500;
    }
}
