<?php

namespace Marvel\Imports;

use InvalidArgumentException;
use Maatwebsite\Excel\Imports\HeadingRowFormatter;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use Marvel\Workbook\ProductWorkbookSchema;

/**
 * Reads the images sheet for the asynchronous image pipeline.
 *
 * The sheet identity, key column and image-cell contract come from
 * ProductWorkbookSchema, so this reader and the Excel sheet importer cannot
 * disagree about column names or the pipe-separated form.
 */
final class ImagesSheetReader
{
    /**
     * @return array<int, array{product_sku: string, image: string, row: int}>
     */
    public function rows(string $filePath): array
    {
        if (!is_file($filePath)) {
            return [];
        }

        $reader = IOFactory::createReaderForFile($filePath);
        $reader->setReadDataOnly(true);
        $spreadsheet = $reader->load($filePath);

        try {
            $sheet = $spreadsheet->getSheetByName(
                ProductWorkbookSchema::title(ProductWorkbookSchema::IMAGES)
            );

            return $sheet === null ? [] : $this->readSheet($sheet);
        } finally {
            $spreadsheet->disconnectWorksheets();
            unset($spreadsheet, $reader);
        }
    }

    /**
     * @return array<int, array{product_sku: string, image: string, row: int}>
     */
    private function readSheet(Worksheet $sheet): array
    {
        $highestRow = $sheet->getHighestDataRow();
        $highestColumn = $sheet->getHighestDataColumn();

        if ($highestRow < 2) {
            return [];
        }

        $columns = $this->columnMap($sheet->rangeToArray("A1:{$highestColumn}1", null, false, false)[0]);
        $keyColumn = ProductWorkbookSchema::keyColumn(ProductWorkbookSchema::IMAGES);

        if (!isset($columns[$keyColumn])) {
            throw new InvalidArgumentException("Images sheet is missing the '{$keyColumn}' column.");
        }

        $rows = [];

        for ($rowNumber = 2; $rowNumber <= $highestRow; $rowNumber++) {
            $row = $this->readRow($sheet, $rowNumber, $highestColumn, $columns);
            $sku = trim((string) ($row[$keyColumn] ?? ''));

            if ($sku === '') {
                continue;
            }

            foreach (ProductWorkbookSchema::imageUrls($row) as $imageUrl) {
                $rows[] = ['product_sku' => $sku, 'image' => $imageUrl, 'row' => $rowNumber];
            }
        }

        return $rows;
    }

    /**
     * @param  array<string, int>  $columns
     * @return array<string, mixed>
     */
    private function readRow(Worksheet $sheet, int $rowNumber, string $highestColumn, array $columns): array
    {
        $values = $sheet->rangeToArray("A{$rowNumber}:{$highestColumn}{$rowNumber}", null, false, false)[0];
        $row = [];

        foreach ($columns as $heading => $index) {
            $row[$heading] = $values[$index] ?? null;
        }

        return $row;
    }

    /**
     * @param  array<int, mixed>  $headerRow
     * @return array<string, int> heading => zero-based column index
     */
    private function columnMap(array $headerRow): array
    {
        $columns = [];

        foreach ($headerRow as $index => $heading) {
            $formatted = HeadingRowFormatter::format([(string) $heading]);
            $normalized = (string) ($formatted[0] ?? '');

            if ($normalized !== '') {
                $columns[$normalized] = $index;
            }
        }

        return $columns;
    }
}
