<?php

namespace Marvel\Exports;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Marvel\Enums\ItemType;

/**
 * One owner for the export filter contract: each export sheet declares which
 * product filters it supports and this applier applies them to its query.
 *
 * Adding a filter is one method here plus adding it to the declaring sheets'
 * supported list in the workbook schema.
 */
final class ExportFilterApplier
{
    /**
     * Apply the declared product filters to an export query.
     *
     * @param  Builder|\Illuminate\Database\Eloquent\Builder  $query
     * @param  array<string, mixed>  $filters
     * @param  array<int, string>  $supported
     */
    public static function apply($query, array $filters, array $supported): void
    {
        foreach ([
            'status' => static function ($query, $value): void {
                $query->where('products.status', $value);
            },
            'product_type' => static function ($query, $value): void {
                $query->where('products.product_type', $value);
            },
            'item_type' => static function ($query, $value): void {
                if (!in_array($value, ItemType::getValues(), true)) {
                    // Invalid values filter to nothing rather than applying raw
                    // input (never trust user input).
                    $query->whereRaw('1 = 0');
                    return;
                }

                $query->where('products.item_type', $value);
            },
            'category_id' => static function ($query, $value): void {
                $query->whereExists(static function ($q) use ($value): void {
                    $q->select(DB::raw(1))->from('category_product')
                        ->whereColumn('category_product.product_id', 'products.id')
                        ->where('category_product.category_id', $value);
                });
            },
            'brand_id' => static function ($query, $value): void {
                $query->whereExists(static function ($q) use ($value): void {
                    $q->select(DB::raw(1))->from('brand_product')
                        ->whereColumn('brand_product.product_id', 'products.id')
                        ->where('brand_product.brand_id', $value);
                });
            },
        ] as $filter => $apply) {
            if (!in_array($filter, $supported, true) || !isset($filters[$filter])) {
                continue;
            }

            $apply($query, $filters[$filter]);
        }
    }
}
