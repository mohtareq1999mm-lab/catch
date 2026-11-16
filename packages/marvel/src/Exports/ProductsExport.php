<?php

namespace Marvel\Exports;

use Maatwebsite\Excel\Concerns\WithMultipleSheets;
use Marvel\Exports\Sheets\BrandsSheetExport;
use Marvel\Exports\Sheets\CategoriesSheetExport;
use Marvel\Exports\Sheets\FlashSalesSheetExport;
use Marvel\Exports\Sheets\ImagesSheetExport;
use Marvel\Exports\Sheets\ProductBarcodesSheetExport;
use Marvel\Exports\Sheets\ProductsSheetExport;
use Marvel\Exports\Sheets\ProductVariantsSheetExport;
use Marvel\Exports\Sheets\SlidersSheetExport;
use Marvel\Exports\Sheets\TagsSheetExport;
use Marvel\Imports\ProductWorkbookSchema;

class ProductsExport implements WithMultipleSheets
{
    protected array $filters;

    public function __construct(array $filters = [])
    {
        $this->filters = $filters;
    }

    public function sheets(): array
    {
        return [
            ProductWorkbookSchema::title(ProductWorkbookSchema::PRODUCTS) => new ProductsSheetExport($this->filters),
            ProductWorkbookSchema::title(ProductWorkbookSchema::PRODUCT_BARCODES) => new ProductBarcodesSheetExport($this->filters),
            ProductWorkbookSchema::title(ProductWorkbookSchema::PRODUCT_VARIANTS) => new ProductVariantsSheetExport($this->filters),
            ProductWorkbookSchema::title(ProductWorkbookSchema::IMAGES) => new ImagesSheetExport($this->filters),
            ProductWorkbookSchema::title(ProductWorkbookSchema::CATEGORIES) => new CategoriesSheetExport($this->filters),
            ProductWorkbookSchema::title(ProductWorkbookSchema::BRANDS) => new BrandsSheetExport($this->filters),
            ProductWorkbookSchema::title(ProductWorkbookSchema::FLASH_SALES) => new FlashSalesSheetExport($this->filters),
            ProductWorkbookSchema::title(ProductWorkbookSchema::SLIDERS) => new SlidersSheetExport($this->filters),
            ProductWorkbookSchema::title(ProductWorkbookSchema::TAGS) => new TagsSheetExport($this->filters),
        ];
    }

    public function download(string $filename)
    {
        return \Maatwebsite\Excel\Facades\Excel::download($this, $filename);
    }

    public function store(string $filename, string $disk)
    {
        return \Maatwebsite\Excel\Facades\Excel::store($this, $filename, $disk);
    }
}
