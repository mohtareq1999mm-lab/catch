<?php

namespace Marvel\Imports\Sheets;

use Marvel\Imports\ProductWorkbookSchema;

class ProductCategoriesSheetImport extends TaxonomySheetImport
{
    protected const SHEET = ProductWorkbookSchema::CATEGORIES;

    protected function slugColumn(): string
    {
        return 'category_slug';
    }

    protected function queue(string $sku, array $slugs): void
    {
        $this->service->queueCategories($sku, $slugs);
    }
}
