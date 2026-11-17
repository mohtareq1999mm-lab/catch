<?php

namespace Marvel\Exports\Sheets;

use Illuminate\Support\Facades\DB;
use Maatwebsite\Excel\Concerns\FromQuery;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;
use Maatwebsite\Excel\Concerns\WithTitle;
use Marvel\Exports\ExportFilterApplier;
use Marvel\Workbook\ProductWorkbookSchema;

class FlashSalesSheetExport implements FromQuery, WithTitle, WithHeadings, WithMapping
{
    protected array $filters;

    public function __construct(array $filters = [])
    {
        $this->filters = $filters;
    }

    public function title(): string
    {
        return ProductWorkbookSchema::title(ProductWorkbookSchema::FLASH_SALES);
    }

    public function query()
    {
        $query = DB::table('flash_sale_products')
            ->join('products', 'products.id', '=', 'flash_sale_products.product_id')
            ->join('flash_sales', 'flash_sales.id', '=', 'flash_sale_products.flash_sale_id')
            ->select(['products.sku as product_sku', 'flash_sales.slug as flash_sale_slug', 'flash_sale_products.product_id']);

        ExportFilterApplier::apply($query, $this->filters, ProductWorkbookSchema::exportFilters(ProductWorkbookSchema::FLASH_SALES));

        return $query->orderBy('flash_sale_products.product_id')->orderBy('flash_sales.id');
    }

    public function map($row): array
    {
        return [
            'product_sku' => $row->product_sku,
            'flash_sale_slug' => $row->flash_sale_slug,
        ];
    }

    public function headings(): array
    {
        return ProductWorkbookSchema::headings(ProductWorkbookSchema::FLASH_SALES);
    }
}
