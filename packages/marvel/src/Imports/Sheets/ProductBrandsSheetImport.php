<?php

namespace Marvel\Imports\Sheets;

class ProductBrandsSheetImport extends TaxonomySheetImport
{
    public function title(): string
    {
        return 'brands';
    }

    public function chunkSize(): int
    {
        return 500;
    }

    protected function slugColumn(): string
    {
        return 'brand_slug';
    }

    protected function queue(string $sku, array $slugs): void
    {
        $this->service->queueBrands($sku, $slugs);
    }
}
