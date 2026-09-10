<?php

// One-off generator: packages/marvel/resources/products/product-import-sample.xlsx
// Contract = ProductsImport sheet titles + ProductImportService consumed headers.
require __DIR__ . '/../../vendor/autoload.php';

$sp = new \PhpOffice\PhpSpreadsheet\Spreadsheet();

$mk = function (string $title, array $headers, array $rows = []) use ($sp) {
    $s = $sp->createSheet();
    $s->setTitle($title);
    $s->fromArray($headers, null, 'A1');
    $r = 2;
    foreach ($rows as $row) {
        $s->fromArray($row, null, 'A' . $r++);
    }
    return $s;
};

// Remove default sheet created with spreadsheet
$sp->removeSheetByIndex(0);

$mk('products',
    ['sku','name_en','name_ar','description_en','description_ar','price','product_type','item_type','quantity','status','in_stock','has_discount','discount_type','discount_amount','start_date','end_date','height','width','length','weight'],
    [
        ['PRD-SAMPLE-001','Wireless Headphones','سماعات لاسلكية','Over-ear wireless headphones','سماعات رأس لاسلكية',129.99,'simple','PHYSICAL',25,'1','1','0','','','','','','',''],
        ['PRD-SAMPLE-002','E-Book Reader','قارئ كتب إلكترونية','Digital e-book reader device','جهاز قراءة كتب إلكترونية',199.00,'simple','DIGITAL',0,'1','1','0','','','','','','',''],
        ['PRD-SAMPLE-003','Cotton T-Shirt','تي شيرت قطني','100% cotton t-shirt','تي شيرت قطن 100%',19.50,'simple','PHYSICAL',100,'1','1','1','percentage',10,'','','','',''],
    ]
);
$mk('product_variants',
    ['variant_sku','product_sku','price','sale_price','quantity','in_stock','height','width','length','weight','attributes'],
    [
        ['PRD-SAMPLE-001-BLK','PRD-SAMPLE-001',129.99,'119.99',10,'1','','','','','Color_en|Color_ar:Black|أسود-Size_en|Size_ar:Standard|قياسي'],
    ]
);
$mk('images',
    ['product_sku','image'],
    [
        ['PRD-SAMPLE-001','https://example.com/images/headphones-front.png'],
        ['PRD-SAMPLE-001','https://example.com/images/headphones-side.png'],
    ]
);
$mk('categories',
    ['product_sku','category_slug'],
    [ ['PRD-SAMPLE-001','electronics'], ['PRD-SAMPLE-002','electronics'] ]
);
$mk('brands',
    ['product_sku','brand_slug'],
    [ ['PRD-SAMPLE-001','acme-audio'] ]
);
$mk('flash_sales',
    ['product_sku','flash_sale_slug'],
    []
);
$mk('sliders',
    ['product_sku','slider_slug'],
    []
);
$mk('tags',
    ['product_sku','tag_slug'],
    [ ['PRD-SAMPLE-001','wireless'], ['PRD-SAMPLE-003','cotton'] ]
);

@mkdir(__DIR__ . '/../../packages/marvel/resources/products', 0755, true);
(new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($sp))->save(__DIR__ . '/../../packages/marvel/resources/products/product-import-sample.xlsx');
echo "sample regenerated\n";
