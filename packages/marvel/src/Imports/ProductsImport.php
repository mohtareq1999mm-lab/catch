<?php

namespace Marvel\Imports;

use Maatwebsite\Excel\Concerns\WithMultipleSheets;
use Marvel\Imports\Sheets\ProductBrandsSheetImport;
use Marvel\Imports\Sheets\ProductCategoriesSheetImport;
use Marvel\Imports\Sheets\FlashSalesSheetImport;
use Marvel\Imports\Sheets\ImagesSheetImport;
use Marvel\Imports\Sheets\ProductsSheetImport;
use Marvel\Imports\Sheets\ProductVariantsSheetImport;
use Marvel\Imports\Sheets\SlidersSheetImport;
use Marvel\Imports\Sheets\TagsSheetImport;
use Marvel\Services\Import\ProductImportService;

class ProductsImport implements WithMultipleSheets
{
    protected ProductImportService $service;
    protected bool $withImages;

    public function __construct(ProductImportService $service, bool $withImages = true)
    {
        $this->service = $service;
        $this->withImages = $withImages;
    }

    public function sheets(): array
    {
        $sheets = [
            'products' => new ProductsSheetImport($this->service),
            'product_variants' => new ProductVariantsSheetImport($this->service),
            'categories' => new ProductCategoriesSheetImport($this->service),
            'brands' => new ProductBrandsSheetImport($this->service),
            'flash_sales' => new FlashSalesSheetImport($this->service),
            'sliders' => new SlidersSheetImport($this->service),
            'tags' => new TagsSheetImport($this->service),
        ];
        if ($this->withImages) {
            $sheets['images'] = new ImagesSheetImport($this->service);
        }
        return $sheets;
    }
}
