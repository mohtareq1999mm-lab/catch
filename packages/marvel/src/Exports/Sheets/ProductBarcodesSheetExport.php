<?php

namespace Marvel\Exports\Sheets;

use Illuminate\Support\Facades\DB;
use Maatwebsite\Excel\Concerns\FromQuery;
use Maatwebsite\Excel\Concerns\WithCustomValueBinder;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;
use Maatwebsite\Excel\Concerns\WithTitle;
use PhpOffice\PhpSpreadsheet\Cell\Cell;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Cell\DefaultValueBinder;

class ProductBarcodesSheetExport extends DefaultValueBinder implements FromQuery, WithTitle, WithHeadings, WithMapping, WithCustomValueBinder
{
    protected array $filters;

    public function __construct(array $filters = [])
    {
        $this->filters = $filters;
    }

    public function title(): string
    {
        return 'product_barcodes';
    }

    public function query()
    {
        $query = DB::table('product_barcodes')
            ->join('products', 'products.id', '=', 'product_barcodes.product_id')
            ->whereNull('products.deleted_at')
            ->select([
                'products.sku as product_sku',
                'product_barcodes.piece_barcode',
                'product_barcodes.carton_barcode',
                'product_barcodes.pieces_per_carton',
                'product_barcodes.product_id',
            ]);

        $this->applyProductFilters($query);

        return $query->orderBy('product_barcodes.product_id');
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
            $query->whereExists(function ($q) {
                $q->select(DB::raw(1))->from('category_product')
                    ->whereColumn('category_product.product_id', 'products.id')
                    ->where('category_product.category_id', $this->filters['category_id']);
            });
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
            'product_sku' => $row->product_sku ?? '',
            'piece_barcode' => $row->piece_barcode ?? '',
            'carton_barcode' => $row->carton_barcode ?? '',
            'pieces_per_carton' => $row->pieces_per_carton ?? '',
        ];
    }

    public function headings(): array
    {
        return [
            'product_sku',
            'piece_barcode',
            'carton_barcode',
            'pieces_per_carton',
        ];
    }

    public function bindValue(Cell $cell, $value): bool
    {
        if (in_array($cell->getColumn(), ['B', 'C'], true) && $cell->getRow() > 1 && $value !== null && $value !== '') {
            $cell->setValueExplicit((string) $value, DataType::TYPE_STRING);

            return true;
        }

        return parent::bindValue($cell, $value);
    }
}

