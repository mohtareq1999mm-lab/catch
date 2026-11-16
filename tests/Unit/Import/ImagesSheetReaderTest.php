<?php

namespace Tests\Unit\Import;

use Marvel\Imports\ImagesSheetReader;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Tests\TestCase;

class ImagesSheetReaderTest extends TestCase
{
    public function test_reads_both_image_forms_and_reports_physical_rows(): void
    {
        $file = $this->workbook([
            ['images', 'product_sku', 'image'],
            ['', 'SKU-1', 'https://example.com/a.jpg'],
            ['https://example.com/b.jpg|https://example.com/c.jpg', 'SKU-2', ''],
            ['', '', ''],
            ['', '', 'https://example.com/d.jpg'],
        ]);

        try {
            $this->assertSame([
                ['product_sku' => 'SKU-1', 'image' => 'https://example.com/a.jpg', 'row' => 2],
                ['product_sku' => 'SKU-2', 'image' => 'https://example.com/b.jpg', 'row' => 3],
                ['product_sku' => 'SKU-2', 'image' => 'https://example.com/c.jpg', 'row' => 3],
            ], (new ImagesSheetReader())->rows($file));
        } finally {
            @unlink($file);
        }
    }

    public function test_missing_images_sheet_returns_no_rows(): void
    {
        $file = $this->workbook([['sku', 'name_en'], ['SKU-1', 'One']], 'products');

        try {
            $this->assertSame([], (new ImagesSheetReader())->rows($file));
        } finally {
            @unlink($file);
        }
    }

    public function test_a_sheet_without_the_key_column_is_rejected(): void
    {
        $file = $this->workbook([['sku', 'image'], ['SKU-1', 'https://example.com/a.jpg']]);

        try {
            $this->expectException(\InvalidArgumentException::class);
            $this->expectExceptionMessage("missing the 'product_sku' column");

            (new ImagesSheetReader())->rows($file);
        } finally {
            @unlink($file);
        }
    }

    /**
     * @param  array<int, array<int, string>>  $rows
     */
    private function workbook(array $rows, string $title = 'images'): string
    {
        $book = new Spreadsheet();
        $sheet = $book->getActiveSheet()->setTitle($title);
        $sheet->fromArray($rows, null, 'A1', true);

        $tmp = tempnam(sys_get_temp_dir(), 'imgreader');
        (new Xlsx($book))->save($tmp);
        $book->disconnectWorksheets();

        return $tmp;
    }
}
