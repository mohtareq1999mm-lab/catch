<?php

namespace Marvel\Exports\Sheets;

use Illuminate\Support\Facades\DB;
use Maatwebsite\Excel\Concerns\FromQuery;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;
use Maatwebsite\Excel\Concerns\WithTitle;
use Marvel\Exports\ExportFilterApplier;
use Marvel\Workbook\ProductWorkbookSchema;

class BrandsSheetExport implements FromQuery, WithTitle, WithHeadings, WithMapping
{
    protected array $filters;

    public function __construct(array $filters = [])
    {
        $this->filters = $filters;
    }

    public function title(): string
    {
        return ProductWorkbookSchema::title(ProductWorkbookSchema::BRANDS);
    }

    public function query()
    {
        $query = DB::table('brand_product')
            ->join('products', 'products.id', '=', 'brand_product.product_id')
            ->join('brands', 'brands.id', '=', 'brand_product.brand_id')
            ->select(['products.sku as product_sku', 'brands.slug as brand_slug', 'brand_product.product_id']);

        ExportFilterApplier::apply($query, $this->filters, ProductWorkbookSchema::exportFilters(ProductWorkbookSchema::BRANDS));

        return $query->orderBy('brand_product.product_id')->orderBy('brands.id');
    }

    public function map($row): array
    {
        return [
            'product_sku' => $row->product_sku,
            'brand_slug' => $row->brand_slug,
        ];
    }

    public function headings(): array
    {
        return ProductWorkbookSchema::headings(ProductWorkbookSchema::BRANDS);
    }
}
