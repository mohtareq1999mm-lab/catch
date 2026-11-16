<?php

namespace Marvel\Imports\Sheets;

class ProductBarcodesSheetImport extends RowSheetImport
{
    public function title(): string
    {
        return 'product_barcodes';
    }

    public function chunkSize(): int
    {
        return 500;
    }

    protected function processRow(array $row, int $excelRow): void
    {
        $this->service->processBarcodeRow($row, $excelRow);
    }
}
