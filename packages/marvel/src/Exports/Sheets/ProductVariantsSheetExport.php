<?php

namespace Marvel\Exports\Sheets;

use Maatwebsite\Excel\Concerns\FromQuery;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;
use Maatwebsite\Excel\Concerns\WithTitle;
use Marvel\Database\Models\ProductVariant;

class ProductVariantsSheetExport implements FromQuery, WithTitle, WithHeadings, WithMapping
{
    protected array $filters;

    public function __construct(array $filters = [])
    {
        $this->filters = $filters;
    }

    public function title(): string
    {
        return 'product_variants';
    }

    public function query()
    {
        $query = ProductVariant::query()
            ->with(['product', 'attributeProducts.attributeValue.attribute']);

        if (isset($this->filters['status'])) {
            $query->whereHas('product', fn($q) => $q->where('status', $this->filters['status']));
        }

        if (isset($this->filters['product_type'])) {
            $query->whereHas('product', fn($q) => $q->where('product_type', $this->filters['product_type']));
        }

        if (isset($this->filters['item_type']) && in_array($this->filters['item_type'], \Marvel\Enums\ItemType::getValues(), true)) {
            $query->whereHas('product', fn($q) => $q->where('item_type', $this->filters['item_type']));
        }

        if (isset($this->filters['category_id'])) {
            $query->whereHas('product.categories', fn($q) => $q->where('category_id', $this->filters['category_id']));
        }

        if (isset($this->filters['brand_id'])) {
            $query->whereHas('product.brands', fn($q) => $q->where('brand_id', $this->filters['brand_id']));
        }

        return $query->orderBy('id');
    }

    public function headings(): array
    {
        return [
            'product_sku',
            'price',
            'sale_price',
            'quantity',
            'height',
            'width',
            'length',
            'weight',
            'attributes',
        ];
    }

    public function map($variant): array
    {
        return [
            'product_sku' => $variant->product->sku ?? '',
            'price' => $variant->price,
            'sale_price' => $variant->sale_price ?? '',
            'quantity' => $variant->stock_quantity,
            'height' => $variant->height,
            'width' => $variant->width,
            'length' => $variant->length,
            'weight' => $variant->weight,
            'attributes' => $this->buildAttributesString($variant),
        ];
    }

    protected function buildAttributesString($variant): string
    {
        $parts = [];

        foreach ($variant->attributeProducts as $ap) {
            $attrValue = $ap->attributeValue;
            $attribute = $attrValue?->attribute;
            if (!$attribute) {
                continue;
            }

            $nameTranslations = $attribute->getTranslations('name');
            $valueTranslations = $attrValue->getTranslations('value');

            $enName = $nameTranslations['en'] ?? '';
            $arName = $nameTranslations['ar'] ?? '';
            $enValue = $valueTranslations['en'] ?? '';
            $arValue = $valueTranslations['ar'] ?? '';

            if (empty($enName) || empty($enValue)) {
                continue;
            }

            $namePart = $arName ? "{$enName}|{$arName}" : $enName;
            $valuePart = $arValue ? "{$enValue}|{$arValue}" : $enValue;

            $parts[] = "{$namePart}:{$valuePart}";
        }

        return implode('-', $parts);
    }
}
