<?php

declare(strict_types=1);

namespace Marvel\Services;

use Carbon\Carbon;
use Marvel\Database\Models\Category;
use Marvel\Database\Models\FlashSale;
use Marvel\Database\Models\Product;
use Marvel\Database\Models\Type;

/**
 * Service to provide data for Puck page builder components.
 *
 * These endpoints are optimized for frontend component rendering,
 * returning only the fields needed for each component type.
 */
class ComponentDataService
{
    /**
     * Get active flash sale products for ProductFlashSaleBlock.
     *
     * @param int $limit Maximum number of products to return
     * @param string|null $language Language filter
     * @return array<int, array>
     */
    public function getFlashSaleProducts(int $limit = 10, ?string $language = null): array
    {
        $now = Carbon::now();

        $query = FlashSale::query()
            ->where('start_date', '<=', $now)
            ->where('end_date', '>=', $now);

        if ($language) {
            $query->where('language', $language);
        }

        $flashSale = $query->with([
            'products' => function ($q) use ($limit) {
                $q->with(['shop:id,name,slug', 'type:id,name,slug'])
                    ->select([
                        'products.id',
                        'products.name',
                        'products.slug',
                        'products.price',
                        'products.sale_price',
                        'products.min_price',
                        'products.max_price',
                        'products.product_type',
                        'products.quantity',
                        'products.image',
                        'products.shop_id',
                        'products.type_id',
                    ])
                    ->activeStatus()
                    ->limit($limit);
            }
        ])->first();

        if (!$flashSale) {
            return [];
        }

        return [
            'flash_sale' => [
                'id' => $flashSale->id,
                'title' => $flashSale->title,
                'slug' => $flashSale->slug,
                'start_date' => $flashSale->start_date,
                'end_date' => $flashSale->end_date,
                'rate' => $flashSale->rate,
            ],
            'products' => $flashSale->products->toArray(),
        ];
    }

    /**
     * Get categories for CategoryBlock.
     *
     * @param int $limit Maximum number of categories to return
     * @param string|null $language Language filter
     * @param bool $topLevelOnly Only return top-level categories (no parent)
     * @return array<int, array>
     */
    public function getCategories(
        int $limit = 10,
        ?string $language = null,
        bool $topLevelOnly = true
    ): array {
        $query = Category::query()
            ->select([
                'id',
                'name',
                'slug',
                'icon',
                'image',
                'details',
                'parent_id',
                'level',
                'type_id',
            ]);

        if ($topLevelOnly) {
            $query->whereNull('parent_id');
        }

        if ($language) {
            $query->where('language', $language);
        }

        return $query
            ->limit($limit)
            ->get()
            ->toArray();
    }

    /**
     * Get collections (Types) for CollectionBlock.
     *
     * @param int $limit Maximum number of collections to return
     * @param string|null $language Language filter
     * @return array<int, array>
     */
    public function getCollections(int $limit = 10, ?string $language = null): array
    {
        $query = Type::query()
            ->select([
                'id',
                'name',
                'slug',
                'icon',
                'promotional_sliders',
                'images',
                'settings',
            ])
            ->with(['banners:id,type_id,title,description,image']);

        if ($language) {
            $query->where('language', $language);
        }

        return $query
            ->limit($limit)
            ->get()
            ->toArray();
    }

    /**
     * Get featured/popular products.
     *
     * @param int $limit Maximum number of products to return
     * @param string|null $language Language filter
     * @return array<int, array>
     */
    public function getPopularProducts(int $limit = 10, ?string $language = null): array
    {
        $query = Product::query()
            ->select([
                'id',
                'name',
                'slug',
                'price',
                'sale_price',
                'min_price',
                'max_price',
                'product_type',
                'quantity',
                'image',
                'shop_id',
                'type_id',
            ])
            ->with(['shop:id,name,slug', 'type:id,name,slug'])
            ->activeStatus()
            ->orderByDesc('orders_count')
            ->limit($limit);

        if ($language) {
            $query->where('language', $language);
        }

        return $query->get()->toArray();
    }

    /**
     * Get best selling products.
     *
     * @param int $limit Maximum number of products to return
     * @param string|null $language Language filter
     * @return array<int, array>
     */
    public function getBestSellingProducts(int $limit = 10, ?string $language = null): array
    {
        $query = Product::query()
            ->select([
                'id',
                'name',
                'slug',
                'price',
                'sale_price',
                'min_price',
                'max_price',
                'product_type',
                'quantity',
                'image',
                'shop_id',
                'type_id',
            ])
            ->with(['shop:id,name,slug', 'type:id,name,slug'])
            ->activeStatus()
            ->orderByDesc('sold_quantity')
            ->limit($limit);

        if ($language) {
            $query->where('language', $language);
        }

        return $query->get()->toArray();
    }
}
