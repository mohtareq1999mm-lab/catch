<?php

namespace Marvel\Imports\Sheets;

use Marvel\Workbook\ProductWorkbookSchema;

class TagsSheetImport extends TaxonomySheetImport
{
    protected const SHEET = ProductWorkbookSchema::TAGS;

    protected function slugColumn(): string
    {
        return 'tag_slug';
    }

    protected function queue(string $sku, array $slugs): void
    {
        $this->service->queueTags($sku, $slugs);
    }
}
