<?php
require 'vendor/autoload.php';
$app=require 'bootstrap/app.php';$kernel=$app->make(Illuminate\Contracts\Console\Kernel::class);$kernel->bootstrap();
$path=storage_path('app/imports/real_import_1789024245.xlsx');
if(!file_exists($path)){
  // find latest real_import file
  $files=glob(storage_path('app/imports/real_import_*.xlsx'));
  echo "files:".implode(",", $files)."\n";
  $path=end($files);
}
echo "path $path exists ".(file_exists($path)?'yes':'no')."\n";
$reader=\PhpOffice\PhpSpreadsheet\IOFactory::createReaderForFile($path);
$reader->setReadDataOnly(true);
$ss=$reader->load($path);
$sheet=$ss->getSheetByName('images');
echo "images highest ".$sheet->getHighestDataRow()."\n";
echo "first 3 rows:\n";
for($r=2;$r<=4;$r++) echo "  A".$r."=". $sheet->getCell('A'.$r)->getValue()." B=".substr($sheet->getCell('B'.$r)->getValue(),0,80)."\n";
$cnt=0; for($r=2;$r<=$sheet->getHighestDataRow();$r++){ $sku=trim((string)$sheet->getCell('A'.$r)->getValue()); $img=trim((string)$sheet->getCell('B'.$r)->getValue()); if($sku!=='' && $img!=='') $cnt++; }
echo "valid image rows $cnt\n";
