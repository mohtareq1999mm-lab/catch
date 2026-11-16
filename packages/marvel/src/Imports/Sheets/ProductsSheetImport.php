<?php

namespace Marvel\Imports\Sheets;

class ProductsSheetImport extends RowSheetImport
{
    public function title(): string
    {
        return 'products';
    }

    public function chunkSize(): int
    {
        return 1000;
    }

    protected function processRow(array $row, int $excelRow): void
    {
        $this->service->processProductRow($row, $excelRow);
    }
}
