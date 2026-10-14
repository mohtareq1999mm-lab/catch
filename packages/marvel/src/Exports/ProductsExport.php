<?php

namespace Marvel\Exports;

use Maatwebsite\Excel\Concerns\WithMultipleSheets;
use Marvel\Exports\Sheets\BrandsSheetExport;
use Marvel\Exports\Sheets\CategoriesSheetExport;
use Marvel\Exports\Sheets\FlashSalesSheetExport;
use Marvel\Exports\Sheets\ImagesSheetExport;
use Marvel\Exports\Sheets\ProductsSheetExport;
use Marvel\Exports\Sheets\ProductVariantsSheetExport;
use Marvel\Exports\Sheets\SlidersSheetExport;
use Marvel\Exports\Sheets\TagsSheetExport;

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
            'products' => new ProductsSheetExport($this->filters),
            'product_variants' => new ProductVariantsSheetExport($this->filters),
            'images' => new ImagesSheetExport($this->filters),
            'categories' => new CategoriesSheetExport($this->filters),
            'brands' => new BrandsSheetExport($this->filters),
            'flash_sales' => new FlashSalesSheetExport($this->filters),
            'sliders' => new SlidersSheetExport($this->filters),
            'tags' => new TagsSheetExport($this->filters),
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
