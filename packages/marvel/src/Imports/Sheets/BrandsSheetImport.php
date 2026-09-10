<?php

namespace Marvel\Imports\Sheets;

use Marvel\Services\Import\ProductImportService;

/**
 * @deprecated Use ProductBrandsSheetImport — kept for backward compatibility.
 * This class represents the product→brands pivot sheet (product_sku/brand_slug),
 * not the standalone brand resource importer (BrandSheetImport).
 */
class BrandsSheetImport extends ProductBrandsSheetImport
{
    public function __construct(ProductImportService $service)
    {
        parent::__construct($service);
    }
}
