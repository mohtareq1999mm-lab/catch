<?php

namespace Marvel\Imports\Sheets;

use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\SkipsEmptyRows;
use Maatwebsite\Excel\Concerns\ToCollection;
use Maatwebsite\Excel\Concerns\WithChunkReading;
use Maatwebsite\Excel\Concerns\WithHeadingRow;
use Maatwebsite\Excel\Concerns\WithTitle;
use Marvel\Services\Import\ProductImportService;

class ProductBrandsSheetImport implements ToCollection, WithTitle, WithHeadingRow, SkipsEmptyRows, WithChunkReading
{
    protected ProductImportService $service;

    public function __construct(ProductImportService $service)
    {
        $this->service = $service;
    }

    public function title(): string
    {
        return 'brands';
    }

    public function collection(Collection $rows): void
    {
        $grouped = $rows->groupBy(fn($row) => $row['product_sku'] ?? '');

        foreach ($grouped as $sku => $brandRows) {
            if (empty($sku)) {
                continue;
            }
            $slugs = $brandRows->pluck('brand_slug')->filter()->toArray();
            $this->service->queueBrands($sku, $slugs);
        }
    }

    public function chunkSize(): int
    {
        return 500;
    }
}
