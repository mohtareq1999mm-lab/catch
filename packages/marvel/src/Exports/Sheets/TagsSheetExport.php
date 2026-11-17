<?php

namespace Marvel\Exports\Sheets;

use Illuminate\Support\Facades\DB;
use Maatwebsite\Excel\Concerns\FromQuery;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;
use Maatwebsite\Excel\Concerns\WithTitle;
use Marvel\Exports\ExportFilterApplier;
use Marvel\Workbook\ProductWorkbookSchema;

class TagsSheetExport implements FromQuery, WithTitle, WithHeadings, WithMapping
{
    protected array $filters;

    public function __construct(array $filters = [])
    {
        $this->filters = $filters;
    }

    public function title(): string
    {
        return ProductWorkbookSchema::title(ProductWorkbookSchema::TAGS);
    }

    public function query()
    {
        $query = DB::table('product_tag')
            ->join('products', 'products.id', '=', 'product_tag.product_id')
            ->join('tags', 'tags.id', '=', 'product_tag.tag_id')
            ->select(['products.sku as product_sku', 'tags.slug as tag_slug', 'product_tag.product_id']);

        ExportFilterApplier::apply($query, $this->filters, ProductWorkbookSchema::exportFilters(ProductWorkbookSchema::TAGS));

        return $query->orderBy('product_tag.product_id')->orderBy('tags.id');
    }

    public function map($row): array
    {
        return [
            'product_sku' => $row->product_sku,
            'tag_slug' => $row->tag_slug,
        ];
    }

    public function headings(): array
    {
        return ProductWorkbookSchema::headings(ProductWorkbookSchema::TAGS);
    }
}
