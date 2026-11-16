<?php

namespace Marvel\Imports\Sheets;

use Marvel\Imports\ProductWorkbookSchema;

class FlashSalesSheetImport extends TaxonomySheetImport
{
    protected const SHEET = ProductWorkbookSchema::FLASH_SALES;

    protected function slugColumn(): string
    {
        return 'flash_sale_slug';
    }

    protected function queue(string $sku, array $slugs): void
    {
        $this->service->queueFlashSales($sku, $slugs);
    }
}
