<?php

namespace Marvel\Imports\Sheets;

use Marvel\Imports\ProductWorkbookSchema;

class ProductsSheetImport extends RowSheetImport
{
    protected const SHEET = ProductWorkbookSchema::PRODUCTS;

    protected function processRow(array $row, int $excelRow): void
    {
        $this->service->processProductRow($row, $excelRow);
    }
}
