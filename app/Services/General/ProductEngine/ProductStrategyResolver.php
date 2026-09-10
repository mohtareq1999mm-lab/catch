<?php


namespace App\Services\General\ProductEngine;

use App\Services\General\ProductEngine\Strategies\AllProduct;
use App\Services\General\ProductEngine\Strategies\AllProductHasDiscount;
use App\Services\General\ProductEngine\Strategies\BestProduct;
use App\Services\General\ProductEngine\Strategies\NewArrivals;
use App\Services\General\ProductEngine\Strategies\ProductDiscountEndingTodayOrLowStock;
use App\Services\General\ProductEngine\Strategies\ProductForBrand;
use App\Services\General\ProductEngine\Strategies\ProductForParentCategory;
use App\Services\General\ProductEngine\Strategies\ProductHasFlashSale;
use App\Services\General\ProductEngine\Strategies\ProductHasFlashSaleEndThisWeek;
use App\Services\General\ProductEngine\Strategies\ProductHasFlashSaleEndToday;

class ProductStrategyResolver
{
    private const STRATEGIES = [
        'index'                                             => AllProduct::class, // all products in the system
        'best_product_sales'                                => BestProduct::class, // pest product in the system
        'brands_product'                                    => ProductForBrand::class, // products for all active brand
        'new_arrivals'                                      => NewArrivals::class, // new arrivals products in the system
        'all_product_discounts'                             => AllProductHasDiscount::class, // all products that have discount in the system
        'product_discount_today_or_low_qty'                 => ProductDiscountEndingTodayOrLowStock::class, // products with discount ending today or low stock
        'flash_sales_product'                               => ProductHasFlashSale::class, // products that have flash sales is valid andreturn only product
        'flash_sales_end_today'                             => ProductHasFlashSaleEndToday::class, // products that have flash sales is valid and return product
        'product_for_parent_category'                       => ProductForParentCategory::class, // products for parent category
        'flash_sales_end_week'                              => ProductHasFlashSaleEndThisWeek::class, // products that have flash sales is valid and return  product
    ];

    public function resolve($type)
    {
        $strategy = self::STRATEGIES[$type] ?? null;

        if ($strategy === null) {
            throw new \InvalidArgumentException("Invalid product type: $type");
        }

        return app($strategy);
    }

    /**
     * The registered strategy type keys. The single source of truth for
     * valid product listing strategy types (used by validation and cache tags).
     *
     * @return array<int, string>
     */
    public function supportedTypes(): array
    {
        return array_keys(self::STRATEGIES);
    }
}
