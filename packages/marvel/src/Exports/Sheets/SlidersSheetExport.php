<?php

namespace Marvel\Exports\Sheets;

use Illuminate\Support\Facades\DB;
use Maatwebsite\Excel\Concerns\FromQuery;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;
use Maatwebsite\Excel\Concerns\WithTitle;
use Marvel\Exports\ExportFilterApplier;
use Marvel\Workbook\ProductWorkbookSchema;

class SlidersSheetExport implements FromQuery, WithTitle, WithHeadings, WithMapping
{
    protected array $filters;

    public function __construct(array $filters = [])
    {
        $this->filters = $filters;
    }

    public function title(): string
    {
        return ProductWorkbookSchema::title(ProductWorkbookSchema::SLIDERS);
    }

    public function query()
    {
        $query = DB::table('slider_product')
            ->join('products', 'products.id', '=', 'slider_product.product_id')
            ->join('sliders', 'sliders.id', '=', 'slider_product.slider_id')
            ->select(['products.sku as product_sku', 'sliders.slug as slider_slug', 'slider_product.product_id']);

        ExportFilterApplier::apply($query, $this->filters, ProductWorkbookSchema::exportFilters(ProductWorkbookSchema::SLIDERS));

        return $query->orderBy('slider_product.product_id')->orderBy('sliders.id');
    }

    public function map($row): array
    {
        return [
            'product_sku' => $row->product_sku,
            'slider_slug' => $row->slider_slug,
        ];
    }

    public function headings(): array
    {
        return ProductWorkbookSchema::headings(ProductWorkbookSchema::SLIDERS);
    }
}
