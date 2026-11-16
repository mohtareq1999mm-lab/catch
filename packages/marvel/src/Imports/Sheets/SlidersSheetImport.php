<?php

namespace Marvel\Imports\Sheets;

use Marvel\Workbook\ProductWorkbookSchema;

class SlidersSheetImport extends TaxonomySheetImport
{
    protected const SHEET = ProductWorkbookSchema::SLIDERS;

    protected function slugColumn(): string
    {
        return 'slider_slug';
    }

    protected function queue(string $sku, array $slugs): void
    {
        $this->service->queueSliders($sku, $slugs);
    }
}
