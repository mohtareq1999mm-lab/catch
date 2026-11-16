<?php

namespace Marvel\Imports\Sheets;

class ProductVariantsSheetImport extends RowSheetImport
{
    public function title(): string
    {
        return 'product_variants';
    }

    public function chunkSize(): int
    {
        return 1000;
    }

    protected function processRow(array $row, int $excelRow): void
    {
        $this->service->processVariantRow($row, $excelRow);
    }
}
