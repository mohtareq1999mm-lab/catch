<?php

namespace Marvel\Exports\Sheets;

use Illuminate\Support\Facades\Schema;
use Maatwebsite\Excel\Concerns\FromQuery;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;
use Maatwebsite\Excel\Concerns\WithTitle;
use Marvel\Database\Models\Product;

class ProductsSheetExport implements FromQuery, WithTitle, WithHeadings, WithMapping
{
    protected array $filters;

    public function __construct(array $filters = [])
    {
        $this->filters = $filters;
    }

    public function title(): string
    {
        return 'products';
    }

    public function query()
    {
        $query = Product::query()
            ->with(['variations', 'categories', 'brands', 'flash_sales', 'sliders']);

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

        return $query->orderBy('id');
    }

    public function headings(): array
    {
        return [
            'sku',
            'name_en',
            'name_ar',
            'description_en',
            'description_ar',
            'price',
            'product_type',
            'item_type',
            'quantity',
            'status',
            'in_stock',
            'has_discount',
            'discount_type',
            'discount_amount',
            'start_date',
            'end_date',
            'height',
            'width',
            'length',
            'weight',
            'tax_enabled',
            'tax_rate',
        ];
    }

    public function map($product): array
    {
        $translations = $product->getTranslations('name');
        $descTranslations = $product->getTranslations('description');

        return [
            'sku' => $product->sku,
            'name_en' => $translations['en'] ?? '',
            'name_ar' => $translations['ar'] ?? '',
            'description_en' => $descTranslations['en'] ?? '',
            'description_ar' => $descTranslations['ar'] ?? '',
            'price' => $product->price,
            'product_type' => $product->product_type,
            'item_type' => $product->item_type ?? \Marvel\Enums\ItemType::PHYSICAL,
            'quantity' => $product->stock_quantity,
            'status' => $product->status ? '1' : '0',
            'in_stock' => $product->in_stock ? '1' : '0',
            'has_discount' => $product->has_discount ? '1' : '0',
            'discount_type' => $product->discount_type,
            'discount_amount' => $product->discount_amount,
            'start_date' => $product->start_date,
            'end_date' => $product->end_date,
            'height' => $product->height,
            'width' => $product->width,
            'length' => $product->length,
            'weight' => $product->weight,
            'tax_enabled' => $product->tax_enabled ? '1' : '0',
            'tax_rate' => $product->tax_rate,
        ];
    }
}
