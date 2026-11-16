<?php

namespace Marvel\Imports\Sheets;

class TagsSheetImport extends TaxonomySheetImport
{
    public function title(): string
    {
        return 'tags';
    }

    public function chunkSize(): int
    {
        return 500;
    }

    protected function slugColumn(): string
    {
        return 'tag_slug';
    }

    protected function queue(string $sku, array $slugs): void
    {
        $this->service->queueTags($sku, $slugs);
    }
}
