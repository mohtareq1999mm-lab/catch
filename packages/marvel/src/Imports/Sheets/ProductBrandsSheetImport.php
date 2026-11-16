<?php

namespace Marvel\Imports\Sheets;

use Marvel\Workbook\ProductWorkbookSchema;

class ProductBrandsSheetImport extends TaxonomySheetImport
{
    protected const SHEET = ProductWorkbookSchema::BRANDS;

    protected function slugColumn(): string
    {
        return 'brand_slug';
    }

    protected function queue(string $sku, array $slugs): void
    {
        $this->service->queueBrands($sku, $slugs);
    }
}
