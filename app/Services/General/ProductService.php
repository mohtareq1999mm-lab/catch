<?php

namespace App\Services\General;

use App\Services\Tax\ProductTaxPresenter;
use App\Traits\HasChannelFilter;
use Carbon\Carbon;
use Exception;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Marvel\Database\Models\Banner;
use Marvel\Database\Models\Brand;
use Marvel\Database\Models\Category;
use Marvel\Database\Models\Attribute;
use Marvel\Database\Models\FlashSale;
use Marvel\Database\Models\Product;
use Marvel\Database\Models\Review;
use Marvel\Database\Models\Slider;
use Marvel\Database\Models\Tag;
use Marvel\Services\Pricing\ProductPricingService;
use Marvel\Traits\MediaManager;
use Symfony\Component\HttpKernel\Exception\HttpException;

class ProductService
{
    use HasChannelFilter;
    use MediaManager;

    public function __construct(private ProductTaxPresenter $productTaxPresenter) {}

    private function pricingService(): ProductPricingService
    {
        return app(ProductPricingService::class);
    }

    public function enrichProductWithPricing(Product $product): Product
    {
        $pricingService = $this->pricingService();
        $flashSale = $pricingService->resolveActiveFlashSale($product);
        $pricing = $pricingService->calculateProductPricing($product, $flashSale);

        $product->setAttribute(
            'current_price',
            $this->productTaxPresenter->applyTo($product, (float) $pricing['final_price'])
        );
        $product->setAttribute('discount_active', $pricingService->isDiscountActive($product));
        $product->setAttribute('flash_sale_active', $flashSale !== null);

        if ($product->relationLoaded('variations')) {
            foreach ($product->variations as $variant) {
                $variantPrice = $pricingService->calculateVariantCurrentPrice($product, $variant, $flashSale);
                // Variants inherit the product's tax. sale_price keeps parity with current_price.
                $variantCurrentPrice = $variantPrice !== null
                    ? $this->productTaxPresenter->applyTo($product, (float) $variantPrice)
                    : null;
                $variant->setAttribute('current_price', $variantCurrentPrice);
                $variant->setAttribute('sale_price', $variantCurrentPrice);
            }
        }

        return $product;
    }

    public function enrichCollectionWithPricing(Collection $products): Collection
    {
        return $products->map(fn(Product $product) => $this->enrichProductWithPricing($product));
    }

    private function productRelations(): array
    {
        return ['categories.parent', 'variations', 'brands', 'media', 'flash_sales' => fn($q) => $q->valid(), 'tags'];
    }

    /**
     * Build a filtered base query with active products, relations, reviews aggregation, search, and relation filters.
     */
    public function buildFilteredBaseQuery(Request $request): Builder
    {
        $query = Product::query()->active()
            ->with($this->productRelations())
            ->withAvg(['reviews' => fn(Builder $builder) => $builder->approved()], 'rating')
            ->withCount(['reviews' => fn(Builder $builder) => $builder->approved()]);

        $this->applyChannelHomeFilter($query);
        $this->applyProductFilters($query, $request);
        $this->applyIdsFilter($query, $request, 'productsId');
        $this->applyRelationIdsFilters($query, $request);

        $term = trim((string) $request->get('search', ''));
        if ($term !== '') {
            $this->applyProductSearch($query, $term, app()->getLocale());
        }

        return $query;
    }

    /**
     * Build a Scout (Meilisearch) search query. Returns null if search term is empty or Scout fails.
     */
    public function buildScoutSearchQuery(Request $request): ?Builder
    {
        $term = trim((string) $request->get('search', ''));

        if ($term === '') {
            return null;
        }

        try {
            $scoutIds = Product::search($term)->keys()->toArray();
        } catch (Exception $e) {
            return null;
        }

        $query = Product::query()->active()
            ->with($this->productRelations())
            ->withAvg(['reviews' => fn(Builder $b) => $b->approved()], 'rating')
            ->withCount(['reviews' => fn(Builder $b) => $b->approved()]);

        $this->applyChannelHomeFilter($query);
        $this->applyProductFilters($query, $request);
        $this->applyIdsFilter($query, $request, 'productsId');
        $this->applyRelationIdsFilters($query, $request);

        if (!empty($scoutIds)) {
            $query->whereIn('products.id', $scoutIds);
            $idOrder = implode(',', array_map('intval', $scoutIds));
            $query->orderByRaw("FIELD(products.id, {$idOrder})");
        } else {
            $query->whereRaw('1 = 0');
        }

        return $query;
    }

    /**
     * Paginate products using the filtered base query.
     */
    public function paginate(Request $request)
    {
        $limit = $this->getLimit($request);
        $order = $request->query('order', 'desc');
        $query = $this->buildFilteredBaseQuery($request);

        $products = $query->orderBy('id', $order)->paginate($limit);

        $products->setCollection(
            $products->getCollection()->map(fn(Product $product) => $this->enrichProductWithPricing($product))
        );

        return $products;
    }

    /**
     * Paginate products that belong to a valid flash sale.
     */
    public function paginateFlashSales(Request $request)
    {
        $limit = $this->getLimit($request);
        $order = $request->query('order', 'desc');

        $query = Product::query()
            ->with($this->productRelations())
            ->withAvg(['reviews' => fn(Builder $builder) => $builder->approved()], 'rating')
            ->withCount(['reviews' => fn(Builder $builder) => $builder->approved()]);

        $this->applyChannelHomeFilter($query);
        $this->applyProductFilters($query, $request);
        $this->applyFlashSaleFilter($query);

        $term = trim((string) $request->get('search', ''));
        if ($term !== '') {
            $this->applyProductSearch($query, $term, app()->getLocale());
        }

        $products = $query->orderBy('id', $order)->paginate($limit);

        $products->setCollection(
            $products->getCollection()->map(fn(Product $product) => $this->enrichProductWithPricing($product))
        );

        return $products;
    }

    /**
     * Get a single product by slug with related products, categories, variations, brands, and reviews.
     *
     * @return Product|null
     */
    public function getProductBySlug($slug, int $limit = 10): ?Product
    {
        $query = Product::query()
            ->active()
            ->search('slug', $slug, app()->getLocale())
            ->with(array_merge($this->productRelations(), [
                'banners',
                'sliders',
                'reviews' => fn($builder) => $builder->approved()->with('user'),
            ]))
            ->withAvg(['reviews' => fn($builder) => $builder->approved()], 'rating')
            ->withCount(['reviews' => fn($builder) => $builder->approved()]);

        $this->applyChannelHomeFilter($query);

        $product = $query->first();

        if (!$product) {
            return null;
        }

        $product->setRelation('related_products', $this->enrichCollectionWithPricing($this->fetchRelated($product, $limit)));

        return $this->enrichProductWithPricing($product);
    }

    /**
     * Get products whose discount ends today or have low stock (1-9 remaining).
     * Adds `badges` attribute to each product.
     *
     * @return Collection<int, Product>
     */
    public function getDiscountEndingTodayOrLowStockProducts($request)
    {
        $limit = $request->query('limit', 10);
        $query = Product::query()
            ->with($this->productRelations())
            ->withAvg(['reviews' => fn($q) => $q->approved()], 'rating')
            ->activeStatus()
            ->where(function ($query) {
                $query->where(function ($q) {
                    $q->where('has_discount', true)
                        ->whereDate('end_date', today());
                })
                    ->orWhere(function ($q) {
                        $q->whereBetween('stock_quantity', [1, 9]);
                    });
            });

        $this->applyChannelHomeFilter($query);

        $products = $query->limit($limit)->get();

        return $products->map(function (Product $product) {
            $badges = [];

            if (
                $product->has_discount &&
                $product->end_date &&
                Carbon::parse($product->end_date)->isToday()
            ) {
                $badges[] = 'discount_ending_today';
            }

            if ($product->stock_quantity >= 1 && $product->stock_quantity <= 9) {
                $badges[] = 'low_stock';
            }

            $product->setAttribute('badges', $badges);

            return $this->enrichProductWithPricing($product);
        })->values();
    }

    /**
     * Get products from flash sales within a date range, flattened.
     *
     * @return Collection<int, Product>
     */
    public function getFlashSalesAndHereProductsByQtySet($request)
    {
        $qty = $request->query('limit', 5);
        $start_date = $request->query('start_date', '');
        $end_date = $request->query('end_date', '');

        $flashSales = FlashSale::query()->valid()
            ->when($start_date, function ($query) use ($start_date) {
                $query->where('created_at', '>=', $start_date);
            })
            ->when($end_date, function ($query) use ($end_date) {
                $query->where('created_at', '<=', $end_date);
            })
            ->with([
                'products' => function ($query) use ($qty) {
                    $query->with($this->productRelations())
                        ->withAvg(['reviews' => fn($q) => $q->approved()], 'rating')
                        ->limit($qty);
                }
            ])->get()
            ->pluck('products')
            ->flatten();

        return $this->enrichCollectionWithPricing($flashSales);
    }

    /**
     * Get products in flash sales ending this week.
     *
     * @return Collection<int, Product>
     */
    public function getFlashSaleProductsEndingThisWeek($request)
    {
        $limit = $request->query('limit', 10);
        $weekEnd = now()->endOfWeek();

        $query = Product::query()
            ->with($this->productRelations())
            ->withAvg(['reviews' => fn($q) => $q->approved()], 'rating')
            ->select([
                'id', 'name', 'slug', 'price', 'quantity',
                'has_discount', 'discount_type', 'discount_amount', 'discount_status',
                'start_date', 'end_date',
            ])
            ->whereNull('deleted_at')
            ->activeStatus()
            ->where('has_flash_sale', true)
            ->whereExists(function ($query) use ($weekEnd) {
                $query->select(DB::raw(1))
                    ->from('flash_sale_products')
                    ->join('flash_sales', 'flash_sale_products.flash_sale_id', '=', 'flash_sales.id')
                    ->whereColumn('flash_sale_products.product_id', 'products.id')
                    ->whereNull('flash_sales.deleted_at')
                    ->where('flash_sales.status', true)
                    ->whereNotNull('flash_sales.end_date')
                    ->whereBetween('flash_sales.end_date', [today(), $weekEnd]);
            });

        $this->applyChannelHomeFilter($query);

        $products = $query
            ->orderByDesc('id')
            ->limit($limit)
            ->get();

        return $this->enrichCollectionWithPricing($products)->values();
    }

    /**
     * Get products in flash sales ending today.
     *
     * @return Collection<int, Product>
     */
    public function getFlashSaleProductsEndingToday($request)
    {
        $limit = $request->query('limit', 10);

        $query = Product::query()
            ->with($this->productRelations())
            ->withAvg(['reviews' => fn($q) => $q->approved()], 'rating')
            ->select([
                'id', 'name', 'slug', 'price', 'quantity',
                'has_discount', 'discount_type', 'discount_amount', 'discount_status',
                'start_date', 'end_date',
            ])
            ->whereNull('deleted_at')
            ->activeStatus()
            ->where('has_flash_sale', true)
            ->whereExists(function ($query) {
                $query->select(DB::raw(1))
                    ->from('flash_sale_products')
                    ->join('flash_sales', 'flash_sale_products.flash_sale_id', '=', 'flash_sales.id')
                    ->whereColumn('flash_sale_products.product_id', 'products.id')
                    ->whereNull('flash_sales.deleted_at')
                    ->where('flash_sales.status', true)
                    ->whereNotNull('flash_sales.end_date')
                    ->whereDate('flash_sales.end_date', today());
            });

        $this->applyChannelHomeFilter($query);

        $products = $query
            ->orderByDesc('id')
            ->limit($limit)
            ->get();

        return $this->enrichCollectionWithPricing($products)->values();
    }

    /**
     * Get all products with active discounts.
     *
     * @return Collection<int, Product>
     */
    public function getAllDiscountProducts($request)
    {
        $limit = $request->query('limit', 10);
        $query = Product::query()
            ->select([
                'id', 'name', 'slug', 'price', 'quantity',
                'has_discount', 'discount_type', 'discount_amount', 'discount_status',
                'start_date', 'end_date',
            ])
            ->with(array_merge($this->productRelations(), ['reviews']))
            ->withAvg(['reviews' => fn($q) => $q->approved()], 'rating')
            ->whereNull('deleted_at')
            ->activeStatus()
            ->where('has_discount', true);

        $this->applyChannelHomeFilter($query);

        $products = $query->orderByDesc('id')->limit($limit)->get();

        return $this->enrichCollectionWithPricing($products)
            ->filter(fn(Product $product) => $this->pricingService()->isDiscountActive($product))
            ->values();
    }

    /**
     * Get products grouped by brand, limited per brand.
     *
     * @return Collection<int, Product>
     */
    public function getBrandsProductsByQtySet($request)
    {
        $qty = $request->query('limit', 10);
        $start_date = $request->query('start_date', '');
        $end_date = $request->query('end_date', '');

        $brands = Brand::active()
            ->when(!empty($start_date), function ($query) use ($start_date) {
                $query->where('created_at', '>=', $start_date);
            })
            ->when(!empty($end_date), function ($query) use ($end_date) {
                $query->where('created_at', '<=', $end_date);
            })
            ->with(['products' => function ($query) use ($qty) {
                $this->applyChannelHomeFilter($query);
                $query->with($this->productRelations())
                    ->withAvg(['reviews' => fn($q) => $q->approved()], 'rating')
                    ->limit($qty);
            }])
            ->get()
            ->pluck('products')
            ->flatten();

        return $this->enrichCollectionWithPricing($brands);
    }

    /**
     * Get newly arrived products (created within last 15 days, no flash sale).
     *
     * @return Collection<int, Product>
     */
    public function getNewArrivals($request)
    {
        $limit = $request->get('limit', 10);
        $query = Product::query()
            ->select([
                'id', 'name', 'slug', 'price', 'quantity',
                'has_discount', 'discount_type', 'discount_amount', 'discount_status',
                'start_date', 'end_date',
            ])
            ->with(array_merge($this->productRelations(), ['reviews']))
            ->withAvg(['reviews' => fn($q) => $q->approved()], 'rating')
            ->whereNull('deleted_at')
            ->activeStatus()
            ->where('has_flash_sale', false)
            ->whereDate('created_at', '>=', now()->subDays(15));

        $this->applyChannelHomeFilter($query);

        $products = $query->orderByDesc('created_at')->limit($limit)->get();

        return $this->enrichCollectionWithPricing($products)->values();
    }

    /**
     * Add a review to a product with optional image uploads.
     *
     * @return Review|null Null if product not found.
     */
    public function addProductReview($request, $id)
    {
        try {
            DB::beginTransaction();
            $product = Product::find($id);
            if (!$product) {
                return null;
            }
            $reviewData = $request->only(['rating', 'comment']);
            $reviewData['user_id'] = auth()->id();
            $reviewData['product_id'] = $id;

            $review = $product->reviews()->create($reviewData);
            if ($request->has('images')) {
                if (!$this->uploadImages($request, 'images', $review, 'reviews', 'reviews')) {
                    throw new HttpException(422, 'Logo upload failed, please check the file format or size.');
                }
            }
            DB::commit();
            return $review;
        } catch (Exception $e) {
            DB::rollBack();
            throw $e;
        }
    }

    /**
     * Update a product review. Only the review author may update.
     *
     * @return Review|null Null if review not found or not owned by user.
     */
    public function updateProductReview($request, $id)
    {
        try {
            DB::beginTransaction();
            $review = Review::find($id);
            if (!$review || $review->user_id !== auth()->id()) {
                return null;
            }

            $reviewData = $request->only(['rating', 'comment']);
            $review->update($reviewData);
            if ($request->has('images')) {
                if (!$this->uploadImages($request, 'images', $review->fresh(), 'reviews', 'reviews')) {
                    throw new HttpException(422, 'Logo upload failed, please check the file format or size.');
                }
            }
            DB::commit();
            return $review;
        } catch (Exception $e) {
            DB::rollBack();
            throw $e;
        }
    }

    /**
     * Get best-selling products sorted by sold_quantity descending.
     *
     * @return Collection<int, Product>
     */
    public function getBestProductSales($request)
    {
        $limit = $request->get('limit', 10);

        $query = Product::query()
            ->active()
            ->with($this->productRelations())
            ->withAvg(['reviews' => fn($q) => $q->approved()], 'rating');

        $this->applyChannelHomeFilter($query);

        return $this->enrichCollectionWithPricing(
            $query->orderByDesc('sold_quantity')
                ->limit($limit)
                ->get()
        );
    }

    /**
     * Get products that belong to any top-level (parent) category.
     *
     * @return Collection<int, Product>
     */
    public function getProductForParentCategory($request)
    {
        $limit = $request->integer('limit', 10);
        $ParentCategories = Category::query()->whereNull('parent_id')->pluck('id');

        $query = Product::query()
            ->active()
            ->with($this->productRelations())
            ->withAvg(['reviews' => fn($q) => $q->approved()], 'rating')
            ->whereHas('categories', function (Builder $query) use ($ParentCategories) {
                $query->whereIn('categories.id', $ParentCategories);
            });

        $this->applyChannelHomeFilter($query);

        return $this->enrichCollectionWithPricing(
            $query->orderByDesc('id')
                ->limit($limit)
                ->get()
        );
    }

    /**
     * Fetch related products sharing the same categories, excluding the current product.
     *
     * @return Collection<int, Product>
     */
    private function fetchRelated(Product $product, int $limit = 10)
    {
        $categories = $product->categories->pluck('id');

        if ($categories->isEmpty()) {
            return collect();
        }

        $query = Product::query()
            ->active()
            ->with($this->productRelations())
            ->withAvg(['reviews' => fn($q) => $q->approved()], 'rating')
            ->whereHas('categories', function (Builder $query) use ($categories) {
                $query->whereIn('categories.id', $categories);
            })
            ->where('id', '!=', $product->id);

        $this->applyChannelHomeFilter($query);

        return $query->limit($limit)->get();
    }

    /**
     * Apply the global ProductFilter scope, dimension ranges, and rating filters.
     */
    private function applyProductFilters(Builder $query, Request $request): void
    {
        $query->filter($request->all());

        $this->applyDimensionFilters($query, $request);

        $ratingMin = $request->get('rating_min');
        $ratingMax = $request->get('rating_max');
        if ($ratingMin !== null || $ratingMax !== null) {
            $min = $ratingMin ?? 0;
            $max = $ratingMax ?? 5;
            $query->whereHas('reviews', function (Builder $builder) use ($min, $max) {
                $builder->whereBetween('rating', [$min, $max]);
            });
        }

        $rating = $request->get('rating');
        if ($rating !== null) {
            $query->whereIn('products.id', function ($sub) use ($rating) {
                $sub->select('product_id')
                    ->from('reviews')
                    ->where('approved', true)
                    ->groupBy('product_id')
                    ->havingRaw('ROUND(AVG(rating), 1) >= ?', [(float) $rating]);
            });
        }
    }

    /**
     * Apply range filters for dimension columns (height, width, length, weight).
     * Uses REGEXP_REPLACE to extract numeric values from string-based dimensions.
     */
    private function applyDimensionFilters(Builder $query, Request $request): void
    {
        $dimensions = [
            'height' => ['height_min', 'height_max'],
            'width' => ['width_min', 'width_max'],
            'length' => ['length_min', 'length_max'],
            'weight' => ['weight_min', 'weight_max'],
        ];

        foreach ($dimensions as $column => [$minKey, $maxKey]) {
            $this->applyDimensionRange(
                $query,
                $column,
                $request->get($minKey),
                $request->get($maxKey)
            );
        }
    }

    /**
     * Apply a single dimension range filter with numeric extraction from string values.
     */
    private function applyDimensionRange(Builder $query, string $column, mixed $min, mixed $max): void
    {
        $allowed = ['height', 'width', 'length', 'weight'];
        if (!in_array($column, $allowed, true)) {
            return;
        }

        $hasMin = $min !== null && $min !== '';
        $hasMax = $max !== null && $max !== '';

        if (!$hasMin && !$hasMax) {
            return;
        }

        $minValue = $hasMin ? (float) $min : null;
        $maxValue = $hasMax ? (float) $max : null;

        $productNumericSql = "CAST(REGEXP_REPLACE(COALESCE(products.{$column}, ''), '[^0-9.]', '') AS DECIMAL(12,4))";

        $query->where(function (Builder $outer) use ($column, $minValue, $maxValue, $productNumericSql, $hasMin, $hasMax) {
            $outer->where(function (Builder $productQuery) use ($productNumericSql, $minValue, $maxValue, $hasMin, $hasMax) {
                if ($hasMin) {
                    $productQuery->whereRaw("{$productNumericSql} >= ?", [$minValue]);
                }
                if ($hasMax) {
                    $productQuery->whereRaw("{$productNumericSql} <= ?", [$maxValue]);
                }
            })->orWhereHas('variations', function (Builder $variantQuery) use ($column, $minValue, $maxValue, $hasMin, $hasMax) {
                if ($hasMin) {
                    $variantQuery->where($column, '>=', $minValue);
                }
                if ($hasMax) {
                    $variantQuery->where($column, '<=', $maxValue);
                }
            });
        });
    }

    /**
     * Filter products to only those currently in a valid flash sale.
     */
    private function applyFlashSaleFilter(Builder $query): void
    {
        $now = now();

$query->where('has_flash_sale', true)
            ->whereHas('flash_sales', function (Builder $builder) use ($now) {
                $builder->where('status', true)
                    ->whereDate('start_date', '<=', $now)
                    ->whereDate('end_date', '>=', $now);
            });
    }

    /**
     * Apply a multi-field search across product name, description, dimensions, reviews, shops, and categories.
     */
    private function applyProductSearch(Builder $query, string $term, string $locale): void
    {
        $query->where(function (Builder $builder) use ($term, $locale) {
            $this->applyTranslatableLike($builder, 'name', $term, $locale);

            $builder->orWhere(function (Builder $sub) use ($term, $locale) {
                $this->applyTranslatableLike($sub, 'description', $term, $locale);
            });

            if (is_numeric($term)) {
                $builder->orWhere('price', $term)
                    ->orWhere('sold_quantity', $term);
            }

            foreach (['height', 'width', 'length', 'weight'] as $dimension) {
                $builder->orWhere($dimension, 'like', '%' . $term . '%');
            }

            $builder->orWhereHas('variations', function (Builder $variantQuery) use ($term) {
                $variantQuery->where(function (Builder $dimensions) use ($term) {
                    foreach (['height', 'width', 'length', 'weight'] as $dimension) {
                        $dimensions->orWhere($dimension, 'like', '%' . $term . '%');
                    }
                });
            });

            $builder->orWhere('sku', 'like', "%{$term}%");

            $builder->orWhereHas('variations', function (Builder $variantQuery) use ($term) {
                $variantQuery->where('sku', 'like', "%{$term}%");
            });

            $builder->orWhereHas('reviews', function (Builder $reviewQuery) use ($term) {
                $reviewQuery->where('comment', 'like', '%' . $term . '%');
            });

            $builder->orWhereHas('categories', function (Builder $categoryQuery) use ($term, $locale) {
                $this->applyTranslatableLike($categoryQuery, 'name', $term, $locale);
            });

        });
    }

    /**
     * Apply a LIKE search on a translatable JSON field for the given locale.
     */
    private function applyTranslatableLike(Builder $query, string $field, string $term, string $locale): void
    {
        $query->where(function ($q) use ($field, $term, $locale) {
            $q->where($field . '->' . $locale, 'like', "%$term%")
                ->orWhere($field, 'like', "%$term%");
        });
    }

    /**
     * Filter by a comma-separated or array list of product IDs.
     */
    private function applyIdsFilter(Builder $query, Request $request, string $paramName): void
    {
        $ids = $request->query($paramName);
        if (!empty($ids)) {
            $ids = is_array($ids) ? $ids : explode(',', $ids);
            $ids = array_filter($ids, 'is_numeric');
            if (!empty($ids)) {
                $query->whereIn('id', $ids);
            }
        }
    }

    /**
     * Apply relationship-based filters (categories, brands, promotions, flash_sales, banners, coupons, sliders).
     */
    private function applyRelationIdsFilters(Builder $query, Request $request): void
    {
        $relations = [
            'categoriesId' => 'categories',
            'brandsId'     => 'brands',
            'tagsId'       => 'tags',
            'promotionsId' => 'promotions',
            'flashSalesId' => 'flash_sales',
            'bannersId'    => 'banners',
            'couponsId'    => 'coupons',
            'slidersId'    => 'sliders',
        ];

        foreach ($relations as $param => $relation) {
            $ids = $request->query($param);
            if (empty($ids)) {
                continue;
            }
            $ids = is_array($ids) ? $ids : explode(',', $ids);
            $ids = array_filter($ids, 'is_numeric');
            if (empty($ids)) {
                continue;
            }
            if ($relation === null) {
                $query->whereIn('products.id', $ids);
            } else {
                $query->whereHas($relation, fn($q) => $q->whereIn("{$relation}.id", $ids));
            }
        }
    }

    /**
     * Get the pagination limit from the request, bounded between 1 and 100.
     */
    public function getLimit(Request $request): int
    {
        $limit = (int) $request->get('limit', 15);
        if ($limit <= 0) {
            return 15;
        }

        return min($limit, 100);
    }

    /**
     * Build the dynamic filters array for the frontend based on the current query results.
     * Extracts available brands, categories, banners, sliders, dimension values, ratings, and attributes.
     */
    public function getDynamicFilters(Builder $query): array
    {
        $filters = [];

        $filteredIds = (clone $query)->select('products.id')->pluck('id');

        if ($filteredIds->isEmpty()) {
            return $filters;
        }

        $displayLabels = [
            'brand'    => ['en' => 'Brand', 'ar' => 'العلامة التجارية'],
            'tag'      => ['en' => 'Tag', 'ar' => 'العلامة'],
            'category' => ['en' => 'Category', 'ar' => 'الفئة'],
            'banner'   => ['en' => 'Banner', 'ar' => 'اللافتة'],
            'slider'   => ['en' => 'Slider', 'ar' => 'السلايدر'],
            'height'   => ['en' => 'Height', 'ar' => 'الارتفاع'],
            'width'    => ['en' => 'Width', 'ar' => 'العرض'],
            'length'   => ['en' => 'Length', 'ar' => 'الطول'],
            'weight'   => ['en' => 'Weight', 'ar' => 'الوزن'],
            'rating'   => ['en' => 'Rating', 'ar' => 'التقييم'],
        ];

        $brands = Brand::active()
            ->whereHas('products', fn($q) => $q->whereIn('products.id', $filteredIds))
            ->get()
            ->map(fn($b) => $b->name)
            ->filter()
            ->values()
            ->toArray();
        if (!empty($brands)) {
            $filters[] = [
                'display' => $displayLabels['brand'][app()->getLocale()],
                'key'     => 'brand',
                'data'    => $brands,
            ];
        }

        $tags = Tag::whereHas('products', fn($q) => $q->whereIn('products.id', $filteredIds))
                ->get()
                ->map(fn($t) => $t->slug)
                ->filter()
                ->values()
                ->toArray();
            if (!empty($tags)) {
                $filters[] = [
                    'display' => $displayLabels['tag'][app()->getLocale()],
                    'key'     => 'tag',
                    'data'    => $tags,
                ];
            }

        $categories = Category::active()
            ->whereHas('products', fn($q) => $q->whereIn('products.id', $filteredIds))
            ->get()
            ->map(fn($c) => $c->name)
            ->filter()
            ->values()
            ->toArray();
        if (!empty($categories)) {
            $filters[] = [
                'display' => $displayLabels['category'][app()->getLocale()],
                'key'     => 'category',
                'data'    => $categories,
            ];
        }

        $banners = Banner::active()
            ->whereHas('products', fn($q) => $q->whereIn('products.id', $filteredIds))
            ->get()
            ->map(fn($b) => $b->slug)
            ->filter()
            ->values()
            ->toArray();
        if (!empty($banners)) {
            $filters[] = [
                'display' => $displayLabels['banner'][app()->getLocale()],
                'key'     => 'banner',
                'data'    => $banners,
            ];
        }

        $sliders = Slider::active()
            ->whereHas('products', fn($q) => $q->whereIn('products.id', $filteredIds))
            ->get()
            ->map(fn($s) => $s->slug)
            ->filter()
            ->values()
            ->toArray();
        if (!empty($sliders)) {
            $filters[] = [
                'display' => $displayLabels['slider'][app()->getLocale()],
                'key'     => 'slider',
                'data'    => $sliders,
            ];
        }

        $dimensions = ['height', 'width', 'length', 'weight'];
        foreach ($dimensions as $column) {
            $productValues = (clone $query)
                ->whereNotNull($column)->where($column, '!=', '')
                ->distinct()->pluck($column)->toArray();

            $variantValues = DB::table('product_variants')
                ->whereIn('product_id', $filteredIds)
                ->whereNotNull($column)->where($column, '!=', '')
                ->distinct()->pluck($column)->toArray();

            $values = array_values(array_unique(array_merge($productValues, $variantValues)));
            $values = array_map('strval', $values);
            $values = array_values(array_filter($values, fn($v) => $v !== ''));
            sort($values, SORT_NATURAL | SORT_FLAG_CASE);

            if (!empty($values)) {
                $filters[] = [
                    'display' => $displayLabels[$column][app()->getLocale()],
                    'key'     => $column,
                    'data'    => $values,
                ];
            }
        }

        $ratingValues = Review::approved()
            ->whereIn('product_id', $filteredIds)
            ->select('product_id', DB::raw('ROUND(AVG(rating)) as avg_rating'))
            ->groupBy('product_id')
            ->get()
            ->pluck('avg_rating')
            ->unique()
            ->sort()
            ->values()
            ->toArray();
        if (!empty($ratingValues)) {
            $filters[] = [
                'display' => $displayLabels['rating'][app()->getLocale()],
                'key'     => 'rating',
                'data'    => $ratingValues,
            ];
        }

        $attributes = Attribute::with(['values' => fn($q) => $q->whereHas('productVariants', fn($pq) => $pq->whereIn('product_id', $filteredIds))])->get();
        foreach ($attributes as $attribute) {
            $values = $attribute->values->map(fn($v) => $v->value)->filter()->values()->toArray();
            if (!empty($values)) {
                $filters[] = [
                    'display' => $attribute->getTranslations('name'),
                    'key'     => $attribute->slug,
                    'data'    => $values,
                ];
            }
        }

        return $filters;
    }

    /**
     * Round a monetary value to 2 decimal places. Returns null if input is null/empty.
     */
    private function moneyValue($value): ?float
    {
        if ($value === null || $value === '') {
            return null;
        }

        return round((float) $value, 2);
    }
}
