<?php

namespace Marvel\Imports\Sheets;

class SlidersSheetImport extends TaxonomySheetImport
{
    public function title(): string
    {
        return 'sliders';
    }

    public function chunkSize(): int
    {
        return 500;
    }

    protected function slugColumn(): string
    {
        return 'slider_slug';
    }

    protected function queue(string $sku, array $slugs): void
    {
        $this->service->queueSliders($sku, $slugs);
    }
}
