<?php
require 'vendor/autoload.php';
$path = 'import/products_export_2026-09-01_scraped.xlsx';
$reader = \PhpOffice\PhpSpreadsheet\IOFactory::createReaderForFile($path);
$reader->setReadDataOnly(true);
$spreadsheet = $reader->load($path);
echo "Sheets: " . implode(", ", $spreadsheet->getSheetNames()) . PHP_EOL;
foreach ($spreadsheet->getSheetNames() as $name) {
    $sheet = $spreadsheet->getSheetByName($name);
    $highestRow = $sheet->getHighestDataRow();
    $highestCol = $sheet->getHighestDataColumn();
    echo "\n=== SHEET: $name (rows=$highestRow cols=$highestCol) ===" . PHP_EOL;
    // headers
    $headers = [];
    foreach ($sheet->getRowIterator(1,1) as $row) {
        foreach ($row->getCellIterator() as $cell) {
            $headers[] = $cell->getValue();
        }
    }
    echo "Headers: " . implode(" | ", $headers) . PHP_EOL;
    // first 2 data rows sample
    $rowNum=0;
    if ($highestRow >= 2) {
    foreach ($sheet->getRowIterator(2, min(4, $highestRow)) as $row) {
        $vals=[];
        foreach ($row->getCellIterator() as $cell) {
            $v = $cell->getValue();
            if (is_string($v) && strlen($v)>80) $v = substr($v,0,80)."...";
            $vals[] = $v;
        }
        echo "Row ".(++$rowNum+1).": ".implode(" | ", $vals).PHP_EOL;
    }
    }
    // count empty trailing detection
    echo "Data rows (excl header): " . ($highestRow-1) . PHP_EOL;
}
$spreadsheet->disconnectWorksheets();
