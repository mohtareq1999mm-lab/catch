<?php

namespace Marvel\Exports\Sheets;

use Maatwebsite\Excel\Concerns\FromQuery;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;
use Maatwebsite\Excel\Concerns\WithTitle;
use Marvel\Workbook\ProductWorkbookSchema;
use Marvel\Database\Models\ProductVariant;
use Marvel\Exports\ExportFilterApplier;

class ProductVariantsSheetExport implements FromQuery, WithTitle, WithHeadings, WithMapping
{
    protected array $filters;

    public function __construct(array $filters = [])
    {
        $this->filters = $filters;
    }

    public function title(): string
    {
        return ProductWorkbookSchema::title(ProductWorkbookSchema::PRODUCT_VARIANTS);
    }

    public function query()
    {
        $query = ProductVariant::query()
            ->with(['product', 'attributeProducts.attributeValue.attribute']);

        ExportFilterApplier::apply(
            $query,
            $this->filters,
            ProductWorkbookSchema::exportFilters(ProductWorkbookSchema::PRODUCT_VARIANTS),
            ExportFilterApplier::MODE_VIA_PRODUCT_RELATION
        );

        return $query->orderBy('id');
    }

    public function headings(): array
    {
        return ProductWorkbookSchema::headings(ProductWorkbookSchema::PRODUCT_VARIANTS);
    }

    public function map($variant): array
    {
        return [
            'variant_sku' => $variant->sku ?? '',
            'product_sku' => $variant->product->sku ?? '',
            'price' => $variant->price,
            'sale_price' => $variant->sale_price ?? '',
            'quantity' => $variant->stock_quantity,
            'in_stock' => $variant->in_stock ? '1' : '0',
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
