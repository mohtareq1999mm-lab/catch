<?php

// One-off generator: packages/marvel/resources/brands/brand-import-sample.xlsx
require __DIR__ . '/../../vendor/autoload.php';

$spreadsheet = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
$sheet = $spreadsheet->getActiveSheet();
$sheet->setTitle('brands');
$headers = ['name_en', 'name_ar', 'details_en', 'details_ar', 'status', 'image_desktop_url', 'image_mobile_url'];
$sheet->fromArray($headers, null, 'A1');
$examples = [
    ['Acme Audio', 'أكست للصوتيات', 'Premium audio equipment', 'معدات صوتية فاخرة', 1, 'https://example.com/images/acme-desktop.png', 'https://example.com/images/acme-mobile.png'],
    ['Nordic Home', 'نورديك هوم', 'Minimalist furniture brand', 'علامة أثاث بسيط', 1, '', ''],
];
$r = 2;
foreach ($examples as $row) {
    $sheet->fromArray($row, null, 'A' . $r++);
}
@mkdir(__DIR__ . '/../../packages/marvel/resources/brands', 0755, true);
(new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($spreadsheet))->save(__DIR__ . '/../../packages/marvel/resources/brands/brand-import-sample.xlsx');
echo "sample written\n";
