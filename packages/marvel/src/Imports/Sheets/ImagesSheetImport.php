<?php

namespace Marvel\Imports\Sheets;

use Marvel\Imports\ProductWorkbookSchema;

class ImagesSheetImport extends RowSheetImport
{
    protected const SHEET = ProductWorkbookSchema::IMAGES;

    protected function processRow(array $row, int $excelRow): void
    {
        $sku = $row['product_sku'] ?? '';

        if (empty($sku)) {
            return;
        }

        foreach (ProductWorkbookSchema::imageUrls($row) as $imageUrl) {
            $this->service->processProductImage($sku, $imageUrl, $excelRow);
        }
    }
}
