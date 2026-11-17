<?php

namespace Marvel\Exports\Sheets;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Maatwebsite\Excel\Concerns\FromQuery;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;
use Maatwebsite\Excel\Concerns\WithTitle;
use Marvel\Exports\ExportFilterApplier;
use Marvel\Workbook\ProductWorkbookSchema;

class ImagesSheetExport implements FromQuery, WithTitle, WithHeadings, WithMapping
{
    protected array $filters;

    public function __construct(array $filters = [])
    {
        $this->filters = $filters;
    }

    public function title(): string
    {
        return ProductWorkbookSchema::title(ProductWorkbookSchema::IMAGES);
    }

    public function query()
    {
        $query = DB::table('media')
            ->join('products', function ($join) {
                $join->on('products.id', '=', 'media.model_id')
                    ->where('media.model_type', '=', 'Marvel\\Database\\Models\\Product')
                    ->where('media.collection_name', '=', 'products');
            })
            ->whereNull('products.deleted_at')
            ->select(['products.sku as product_sku', 'media.id as media_id', 'media.file_name', 'media.disk', 'media.conversions_disk']);

        ExportFilterApplier::apply($query, $this->filters, ProductWorkbookSchema::exportFilters(ProductWorkbookSchema::IMAGES));

        return $query->orderBy('media.model_id')->orderBy('media.id');
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
        return ProductWorkbookSchema::headings(ProductWorkbookSchema::IMAGES);
    }
}
