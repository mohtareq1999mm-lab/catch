<?php

namespace Marvel\Imports\Sheets;

use Marvel\Workbook\ProductWorkbookSchema;

class ImagesSheetImport extends RowSheetImport
{
    protected const SHEET = ProductWorkbookSchema::IMAGES;

    protected function processRow(array $row, int $excelRow): void
    {
        $keyColumn = ProductWorkbookSchema::keyColumn(static::SHEET);
        $sku = $row[$keyColumn] ?? '';

        if (empty($sku)) {
            return;
        }

        foreach (ProductWorkbookSchema::imageUrls($row) as $imageUrl) {
            $this->service->processProductImage($sku, $imageUrl, $excelRow);
        }
    }
}
