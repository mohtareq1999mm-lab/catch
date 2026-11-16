<?php

namespace Marvel\Imports;

use Maatwebsite\Excel\Concerns\SkipsUnknownSheets;
use Maatwebsite\Excel\Concerns\WithMultipleSheets;
use Marvel\Services\Import\ProductImportService;

class ProductsImport implements WithMultipleSheets, SkipsUnknownSheets
{
    protected ProductImportService $service;
    protected bool $withImages;

    public function __construct(ProductImportService $service, bool $withImages = true)
    {
        $this->service = $service;
        $this->withImages = $withImages;
    }

    public function sheets(): array
    {
        $sheets = [];

        foreach (ProductWorkbookSchema::importers() as $key => $importerClass) {
            if ($key === ProductWorkbookSchema::IMAGES && !$this->withImages) {
                continue;
            }

            $sheets[ProductWorkbookSchema::title($key)] = new $importerClass($this->service);
        }

        return $sheets;
    }

    public function onUnknownSheet($sheetName): void
    {
        // Intentionally ignore unknown or omitted sheets (e.g. legacy 8-sheet workbooks without product_barcodes)
    }
}
