<?php
require 'vendor/autoload.php';
$app=require 'bootstrap/app.php';
$kernel=$app->make(Illuminate\Contracts\Console\Kernel::class);$kernel->bootstrap();
$src='import/products_export_2026-09-01_scraped.xlsx';
$reader=\PhpOffice\PhpSpreadsheet\IOFactory::createReaderForFile($src);
$reader->setReadDataOnly(true);
$spreadsheet=$reader->load($src);
$new=new \PhpOffice\PhpSpreadsheet\Spreadsheet();
foreach($spreadsheet->getSheetNames() as $sn){
  $srcSheet=$spreadsheet->getSheetByName($sn);
  $newSheet=$new->createSheet(); $newSheet->setTitle($sn);
  $maxRow=$srcSheet->getHighestDataRow();
  $maxCol=$srcSheet->getHighestDataColumn();
  if($sn==='images'){
    // only header
    for($c='A';$c<=$maxCol;$c++) $newSheet->setCellValue($c.'1', $srcSheet->getCell($c.'1')->getValue());
  } else {
    for($r=1;$r<=$maxRow;$r++) for($c='A';$c<=$maxCol;$c++) $newSheet->setCellValue($c.$r, $srcSheet->getCell($c.$r)->getValue());
  }
}
$sh=$new->getSheetByName('Worksheet'); if($sh) $new->removeSheetByIndex($new->getIndex($sh));
$spreadsheet->disconnectWorksheets();
$path=storage_path('app/imports/full_no_images.xlsx');
$writer=new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($new);
$writer->save($path);
echo "Created $path\n";
echo "Sheet counts:\n";
$reader2=\PhpOffice\PhpSpreadsheet\IOFactory::createReaderForFile($path);
$reader2->setReadDataOnly(true);
$ss2=$reader2->load($path);
foreach($ss2->getSheetNames() as $sn){ $s=$ss2->getSheetByName($sn); echo "$sn: ".$s->getHighestDataRow()." rows\n"; }
