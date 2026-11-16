<?php

namespace Marvel\Imports\Sheets;

use Marvel\Workbook\ProductWorkbookSchema;

class ProductBarcodesSheetImport extends RowSheetImport
{
    protected const SHEET = ProductWorkbookSchema::PRODUCT_BARCODES;

    protected function processRow(array $row, int $excelRow): void
    {
        $this->service->processBarcodeRow($row, $excelRow);
    }
}
