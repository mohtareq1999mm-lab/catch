<?php

namespace Marvel\Exports\Sheets;

use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithTitle;
use Marvel\Database\Models\Product;

class FlashSalesSheetExport implements FromCollection, WithTitle, WithHeadings
{
    protected array $filters;

    public function __construct(array $filters = [])
    {
        $this->filters = $filters;
    }

    public function title(): string
    {
        return 'flash_sales';
    }

    public function collection()
    {
        $query = Product::query()->with('flash_sales');

        if (isset($this->filters['status'])) {
            $query->where('status', $this->filters['status']);
        }

        if (isset($this->filters['product_type'])) {
            $query->where('product_type', $this->filters['product_type']);
        }

        if (isset($this->filters['item_type']) && in_array($this->filters['item_type'], \Marvel\Enums\ItemType::getValues(), true)) {
            $query->where('item_type', $this->filters['item_type']);
        }

        if (isset($this->filters['category_id'])) {
            $query->whereHas('categories', fn($q) => $q->where('category_id', $this->filters['category_id']));
        }

        if (isset($this->filters['brand_id'])) {
            $query->whereHas('brands', fn($q) => $q->where('brand_id', $this->filters['brand_id']));
        }

        return $query->lazy(1000)->flatMap(function (Product $product) {
            return $product->flash_sales->map(fn($flashSale) => [
                'product_sku' => $product->sku,
                'flash_sale_slug' => $flashSale->slug,
            ]);
        });
    }

    public function headings(): array
    {
        return [
            'product_sku',
            'flash_sale_slug',
        ];
    }
}
