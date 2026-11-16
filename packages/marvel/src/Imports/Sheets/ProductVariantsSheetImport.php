<?php

namespace Marvel\Imports\Sheets;

use Marvel\Imports\ProductWorkbookSchema;

class ProductVariantsSheetImport extends RowSheetImport
{
    protected const SHEET = ProductWorkbookSchema::PRODUCT_VARIANTS;

    protected function processRow(array $row, int $excelRow): void
    {
        $this->service->processVariantRow($row, $excelRow);
    }
}
