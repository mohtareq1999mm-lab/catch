<?php

namespace Marvel\Exports\Sheets;

use Illuminate\Support\Facades\DB;
use Maatwebsite\Excel\Concerns\FromQuery;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;
use Maatwebsite\Excel\Concerns\WithTitle;
use Marvel\Exports\ExportFilterApplier;
use Marvel\Workbook\ProductWorkbookSchema;

class CategoriesSheetExport implements FromQuery, WithTitle, WithHeadings, WithMapping
{
    protected array $filters;

    public function __construct(array $filters = [])
    {
        $this->filters = $filters;
    }

    public function title(): string
    {
        return ProductWorkbookSchema::title(ProductWorkbookSchema::CATEGORIES);
    }

    public function query()
    {
        $query = DB::table('category_product')
            ->join('products', 'products.id', '=', 'category_product.product_id')
            ->join('categories', 'categories.id', '=', 'category_product.category_id')
            ->select(['products.sku as product_sku', 'categories.slug as category_slug', 'category_product.product_id']);

        ExportFilterApplier::apply($query, $this->filters, ProductWorkbookSchema::exportFilters(ProductWorkbookSchema::CATEGORIES));

        return $query->orderBy('category_product.product_id')->orderBy('categories.id');
    }

    public function map($row): array
    {
        return [
            'product_sku' => $row->product_sku,
            'category_slug' => $row->category_slug,
        ];
    }

    public function headings(): array
    {
        return ProductWorkbookSchema::headings(ProductWorkbookSchema::CATEGORIES);
    }
}
