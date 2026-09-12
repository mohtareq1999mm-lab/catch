<?php

namespace Marvel\Exports\Sheets;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Maatwebsite\Excel\Concerns\FromQuery;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;
use Maatwebsite\Excel\Concerns\WithTitle;

class ImagesSheetExport implements FromQuery, WithTitle, WithHeadings, WithMapping
{
    protected array $filters;

    public function __construct(array $filters = [])
    {
        $this->filters = $filters;
    }

    public function title(): string
    {
        return 'images';
    }

    public function query()
    {
        $query = DB::table('media')
            ->join('products', function ($join) {
                $join->on('products.id', '=', 'media.model_id')
                    ->where('media.model_type', '=', 'Marvel\\Database\\Models\\Product')
                    ->where('media.collection_name', '=', 'products');
            })
            ->select(['products.sku as product_sku', 'media.id as media_id', 'media.file_name', 'media.disk', 'media.conversions_disk']);

        $this->applyProductFilters($query);

        return $query->orderBy('media.model_id')->orderBy('media.id');
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
        // Avoid per-row Media::find N+1 — build URL from stored file_name/disk.
        // Spatie Media::getUrl() for products collection resolves to APP_URL/storage/products/{path}.
        // For local disks this matches Storage::disk($disk)->url(file_name); fallback to file_name.
        $url = $row->file_name ?? '';
        if ($url !== '' && !empty($row->disk)) {
            try {
                // products media typically on 'public' or 'products' disk; try original disk first
                $disk = $row->disk;
                // Spatie may store uuid path; Storage::url handles it
                if (\Illuminate\Support\Facades\Storage::disk($disk)->exists($row->file_name)) {
                    $url = \Illuminate\Support\Facades\Storage::disk($disk)->url($row->file_name);
                } elseif (\Illuminate\Support\Facades\Storage::disk('products')->exists($row->file_name)) {
                    $url = \Illuminate\Support\Facades\Storage::disk('products')->url($row->file_name);
                } elseif (\Illuminate\Support\Facades\Storage::disk('public')->exists($row->file_name)) {
                    $url = \Illuminate\Support\Facades\Storage::disk('public')->url($row->file_name);
                }
            } catch (\Throwable $e) {
                $url = $row->file_name ?? '';
            }
        }

        return [
            'product_sku' => $row->product_sku,
            'image' => $url,
        ];
    }

    public function headings(): array
    {
        return [
            'product_sku',
            'image',
        ];
    }
}
