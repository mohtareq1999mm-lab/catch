<?php

namespace Marvel\Imports\Sheets;

class FlashSalesSheetImport extends TaxonomySheetImport
{
    public function title(): string
    {
        return 'flash_sales';
    }

    public function chunkSize(): int
    {
        return 500;
    }

    protected function slugColumn(): string
    {
        return 'flash_sale_slug';
    }

    protected function queue(string $sku, array $slugs): void
    {
        $this->service->queueFlashSales($sku, $slugs);
    }
}
