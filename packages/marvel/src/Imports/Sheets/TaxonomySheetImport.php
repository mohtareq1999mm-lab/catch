<?php

namespace Marvel\Imports\Sheets;

use Illuminate\Support\Collection;
use Marvel\Workbook\ProductWorkbookSchema;

/**
 * A sheet that maps many rows per product onto one queued reference list.
 */
abstract class TaxonomySheetImport extends ChunkedSheetImport
{
    protected function handleChunk(Collection $rows): void
    {
        $keyColumn = ProductWorkbookSchema::keyColumn(static::SHEET);

        foreach ($rows->groupBy(fn ($row) => $row[$keyColumn] ?? '') as $sku => $skuRows) {
            if (empty($sku)) {
                continue;
            }

            $slugs = $skuRows->pluck($this->slugColumn())->filter()->toArray();
            $this->queue((string) $sku, $slugs);
        }
    }

    abstract protected function slugColumn(): string;

    /**
     * @param  array<int, string>  $slugs
     */
    abstract protected function queue(string $sku, array $slugs): void;
}
