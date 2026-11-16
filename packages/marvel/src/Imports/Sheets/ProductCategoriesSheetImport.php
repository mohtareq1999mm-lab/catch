<?php

namespace Marvel\Imports\Sheets;

class ProductCategoriesSheetImport extends TaxonomySheetImport
{
    public function title(): string
    {
        return 'categories';
    }

    public function chunkSize(): int
    {
        return 500;
    }

    protected function slugColumn(): string
    {
        return 'category_slug';
    }

    protected function queue(string $sku, array $slugs): void
    {
        $this->service->queueCategories($sku, $slugs);
    }
}
