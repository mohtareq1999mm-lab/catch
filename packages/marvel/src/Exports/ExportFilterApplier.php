<?php

namespace Marvel\Exports;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Marvel\Enums\ItemType;

/**
 * One owner for the export filter contract: each export sheet declares which
 * product filters it supports (in the workbook schema) and this applier
 * applies them to the sheet's query in the sheet's declared shape.
 *
 * Every sheet's query is product-constrained but built differently:
 * join sheets filter products.* columns directly (pivot whereExists for
 * taxonomy, safe on a plain query builder), the products sheet is Eloquent
 * and uses relations, and the variants sheet constrains everything through
 * the parent product relation.
 *
 * Adding a filter is one method here plus adding it to the declaring sheets'
 * supported list in the workbook schema.
 */
final class ExportFilterApplier
{
    /**
     * Join-builder sheets: filters address products.* columns; taxonomy
     * constraints go through a pivot whereExists (no Eloquent API needed).
     */
    public const MODE_PIVOT_EXISTS = 'pivot_exists';

    /**
     * The Eloquent products sheet: filters address the products table,
     * taxonomy constraints through the model relations.
     */
    public const MODE_PRODUCT_COLUMNS = 'product_columns';

    /**
     * Product-child sheets (variants): every constraint applies to the parent
     * product through the product relation.
     */
    public const MODE_VIA_PRODUCT_RELATION = 'via_product_relation';

    /**
     * Apply the declared product filters to an export query.
     *
     * @param  Builder|\Illuminate\Database\Eloquent\Builder  $query
     * @param  array<string, mixed>  $filters
     * @param  array<int, string>  $supported
     * @param  self::MODE_*  $mode
     */
    public static function apply($query, array $filters, array $supported, string $mode = self::MODE_PIVOT_EXISTS): void
    {
        foreach ([
            'status' => static function ($query, $value) use ($mode): void {
                self::constrainProductColumn($query, $mode, 'status', $value);
            },
            'product_type' => static function ($query, $value) use ($mode): void {
                self::constrainProductColumn($query, $mode, 'product_type', $value);
            },
            'item_type' => static function ($query, $value) use ($mode): void {
                if (!in_array($value, ItemType::getValues(), true)) {
                    // Invalid values filter to nothing rather than applying raw
                    // input (never trust user input).
                    self::constrainImpossible($query, $mode);
                    return;
                }

                self::constrainProductColumn($query, $mode, 'item_type', $value);
            },
            'category_id' => static function ($query, $value) use ($mode): void {
                self::constrainTaxonomy($query, $mode, 'categories', 'category_product', 'category_id', $value);
            },
            'brand_id' => static function ($query, $value) use ($mode): void {
                self::constrainTaxonomy($query, $mode, 'brands', 'brand_product', 'brand_id', $value);
            },
        ] as $filter => $apply) {
            if (!in_array($filter, $supported, true) || !isset($filters[$filter])) {
                continue;
            }

            $apply($query, $filters[$filter]);
        }
    }

    /**
     * Constrain a plain products.* column in the sheet's declared shape.
     *
     * @param  Builder|\Illuminate\Database\Eloquent\Builder  $query
     */
    private static function constrainProductColumn($query, string $mode, string $column, $value): void
    {
        if ($mode === self::MODE_VIA_PRODUCT_RELATION) {
            $query->whereHas('product', static function ($product) use ($column, $value): void {
                $product->where($column, $value);
            });

            return;
        }

        $query->where("products.{$column}", $value);
    }

    /**
     * Make the row unmatchable in the sheet's declared shape (invalid filter
     * input must never fall through to a raw unconstrained query).
     *
     * @param  Builder|\Illuminate\Database\Eloquent\Builder  $query
     */
    private static function constrainImpossible($query, string $mode): void
    {
        if ($mode === self::MODE_VIA_PRODUCT_RELATION) {
            $query->whereHas('product', static function ($product): void {
                $product->whereRaw('1 = 0');
            });

            return;
        }

        $query->whereRaw('1 = 0');
    }

    /**
     * Constrain a taxonomy relation in the sheet's declared shape.
     *
     * @param  Builder|\Illuminate\Database\Eloquent\Builder  $query
     */
    private static function constrainTaxonomy($query, string $mode, string $relation, string $pivot, string $foreignKey, $value): void
    {
        if ($mode === self::MODE_VIA_PRODUCT_RELATION) {
            $query->whereHas('product', static function ($product) use ($relation, $foreignKey, $value): void {
                $product->whereHas($relation, static function ($related) use ($foreignKey, $value): void {
                    $related->where($foreignKey, $value);
                });
            });

            return;
        }

        if ($mode === self::MODE_PRODUCT_COLUMNS) {
            $query->whereHas($relation, static function ($related) use ($foreignKey, $value): void {
                $related->where($foreignKey, $value);
            });

            return;
        }

        // MODE_PIVOT_EXISTS: the sheet's query may be a plain query builder
        // without the relation API, so constrain through a pivot whereExists.
        $query->whereExists(static function ($exists) use ($pivot, $foreignKey, $value): void {
            $exists->select(DB::raw(1))->from($pivot)
                ->whereColumn($pivot . '.product_id', 'products.id')
                ->where($pivot . '.' . $foreignKey, $value);
        });
    }
}
