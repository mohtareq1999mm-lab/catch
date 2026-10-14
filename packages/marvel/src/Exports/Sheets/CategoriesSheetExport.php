<?php

namespace Marvel\Exports\Sheets;

use Illuminate\Support\Facades\DB;
use Maatwebsite\Excel\Concerns\FromQuery;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;
use Maatwebsite\Excel\Concerns\WithTitle;

class CategoriesSheetExport implements FromQuery, WithTitle, WithHeadings, WithMapping
{
    protected array $filters;

    public function __construct(array $filters = [])
    {
        $this->filters = $filters;
    }

    public function title(): string
    {
        return 'categories';
    }

    public function query()
    {
        $query = DB::table('category_product')
            ->join('products', 'products.id', '=', 'category_product.product_id')
            ->join('categories', 'categories.id', '=', 'category_product.category_id')
            ->select(['products.sku as product_sku', 'categories.slug as category_slug', 'category_product.product_id']);

        $this->applyProductFilters($query);

        return $query->orderBy('category_product.product_id')->orderBy('categories.id');
    }

    protected function applyProductFilters($query): void
    {
        if (isset($this->filters['status'])) {
            $query->where('products.status', $this->filters['status']);
        }
        if (isset($this->filters['product_type'])) {
            $query->where('products.product_type', $this->filters['product_type']);
        }
        if (isset($this->filters['item_type']) && in_array($this->filters['item_type'], \Marvel\Enums\ItemType::getValues(), true)) {
            $query->where('products.item_type', $this->filters['item_type']);
        }
        if (isset($this->filters['category_id'])) {
            $query->where('category_product.category_id', $this->filters['category_id']);
        }
        if (isset($this->filters['brand_id'])) {
            $query->whereExists(function ($q) {
                $q->select(DB::raw(1))->from('brand_product')
                    ->whereColumn('brand_product.product_id', 'products.id')
                    ->where('brand_product.brand_id', $this->filters['brand_id']);
            });
        }
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
        return [
            'product_sku',
            'category_slug',
        ];
    }
}
