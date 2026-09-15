<?php

namespace App\Services\General;

use Illuminate\Database\Eloquent\Builder;
use Marvel\Database\Models\Attribute;
use Marvel\Database\Models\AttributeValue;
use Marvel\Database\Models\Banner;
use Marvel\Database\Models\Brand;
use Marvel\Database\Models\Category;
use Marvel\Database\Models\Slider;
use Marvel\Database\Models\Tag;

class ProductFilter
{
    /**
     * Resolve model IDs by matching name (translated) or slug against given values.
     *
     * @param class-string $modelClass
     * @param array<int, string> $values
     * @return array<int, int>
     */
    private function resolveIds(string $modelClass, array $values): array
    {
        $locale = app()->getLocale();
        $query = $modelClass::where(function ($q) use ($values, $locale) {
            foreach ($values as $val) {
                $q->orWhere("name->{$locale}", $val)
                  ->orWhere('slug', $val);
            }
        });
        // Public visibility: only resolve active records when the model has an active scope.
        if (in_array($modelClass, [Brand::class, Category::class], true)) {
            $query->active();
        } elseif (in_array($modelClass, [Banner::class, Slider::class], true) && method_exists($modelClass, 'scopeActive')) {
            $query->active();
        }
        return $query->pluck('id')->toArray();
    }

    /**
     * Recursively expand a list of category IDs to include all descendant IDs.
     *
     * @param array<int, int> $ids
     * @return array<int, int>
     */
    public function expandWithDescendants(array $ids): array
    {
        $allIds = $ids;
        $children = Category::active()->whereIn('parent_id', $ids)->pluck('id')->toArray();
        if (!empty($children)) {
            $allIds = array_merge($allIds, $this->expandWithDescendants($children));
        }
        return array_unique($allIds);
    }

    /**
     * Apply all product filters to the given query.
     *
     * Supported filter keys:
     * - brand/brands, category/categories: Resolved by name or slug (singular canonical, plural alias).
     * - promotion, flash_sale: Filtered by slug.
     * - banner, slider: Filtered by slug or translated title.
     * - minPrice, maxPrice, price_min, price_max, min_price, max_price: Price range including variants.
     * - height, width, length, weight: Exact match dimension values.
     * - Dynamic attribute slugs: Filtered by attribute value.
     */
    public function apply(Builder $query, array $filters): Builder
    {
        // Normalize aliases so documented and frontend variants all reach the same logic.
        // price: accept snake_case guide variant min_price/max_price in addition to
        // the existing camelCase and price_min forms.
        if (!isset($filters['minPrice']) && isset($filters['min_price'])) {
            $filters['minPrice'] = $filters['min_price'];
        }
        if (!isset($filters['maxPrice']) && isset($filters['max_price'])) {
            $filters['maxPrice'] = $filters['max_price'];
        }
        // brand/categories plural aliases (frontend docs use `brands`).
        if (!isset($filters['brand']) && isset($filters['brands'])) {
            $filters['brand'] = $filters['brands'];
        }
        if (!isset($filters['category']) && isset($filters['categories'])) {
            $filters['category'] = $filters['categories'];
        }
        // 1. Filter by Brand
        if (!empty($filters['brand'])) {
            $brandNames = is_array($filters['brand']) ? $filters['brand'] : explode(',', $filters['brand']);
            $brandIds = $this->resolveIds(Brand::class, $brandNames);
            if (!empty($brandIds)) {
                $query->whereHas('brands', fn($q) => $q->whereIn('brands.id', $brandIds));
            } else {
                $query->whereRaw('1 = 0');
            }
        }

        // 2. Filter by Category (includes all descendant categories recursively)
        if (!empty($filters['category'])) {
            $categoryNames = is_array($filters['category']) ? $filters['category'] : explode(',', $filters['category']);
            $categoryIds = $this->resolveIds(Category::class, $categoryNames);
            if (!empty($categoryIds)) {
                $expandedIds = $this->expandWithDescendants($categoryIds);
                $query->whereHas('categories', fn($q) => $q->whereIn('categories.id', $expandedIds));
            } else {
                $query->whereRaw('1 = 0');
            }
        }

        // 3. Filter by Promotion (query-only, not in available filters)
        if (!empty($filters['promotion'])) {
            $promoSlugs = is_array($filters['promotion']) ? $filters['promotion'] : explode(',', $filters['promotion']);
            $query->whereHas('promotions', function ($q) use ($promoSlugs) {
                $q->reorder()->where(function ($sub) use ($promoSlugs) {
                    foreach ($promoSlugs as $slug) {
                        $sub->orWhere('promotions.slug', $slug);
                    }
                });
            });
        }

        // 4. Filter by Flash Sale (query-only, not in available filters)
        if (!empty($filters['flash_sale'])) {
            $fsSlugs = is_array($filters['flash_sale']) ? $filters['flash_sale'] : explode(',', $filters['flash_sale']);
            $locale = app()->getLocale();
            $query->whereHas('flash_sales', function ($q) use ($fsSlugs, $locale) {
                $q->where(function ($sub) use ($fsSlugs, $locale) {
                    foreach ($fsSlugs as $slug) {
                        $sub->orWhere("flash_sales.title->{$locale}", $slug)
                            ->orWhere('flash_sales.slug', $slug);
                    }
                });
            });
        }

        // 5. Filter by Banner
        if (!empty($filters['banner'])) {
            $bannerSlugs = is_array($filters['banner']) ? $filters['banner'] : explode(',', $filters['banner']);
            $locale = app()->getLocale();
            $bannerIds = Banner::active()->where(function ($q) use ($bannerSlugs, $locale) {
                foreach ($bannerSlugs as $slug) {
                    $q->orWhere("title->{$locale}", $slug)
                      ->orWhere('slug', $slug);
                }
            })->pluck('id')->toArray();
            if (!empty($bannerIds)) {
                $query->whereHas('banners', fn($q) => $q->whereIn('banners.id', $bannerIds));
            } else {
                $query->whereRaw('1 = 0');
            }
        }

        // 5b. Filter by Tag (AND logic — product must have ALL specified tags)
        $tagFilterValues = $filters['tags'] ?? $filters['tag'] ?? null;
        if (!empty($tagFilterValues)) {
            $tagValues = is_array($tagFilterValues) ? $tagFilterValues : explode(',', $tagFilterValues);
            $tagIds = Tag::where(function ($q) use ($tagValues) {
                foreach ($tagValues as $val) {
                    $q->orWhere('slug', $val)
                      ->orWhere('id', is_numeric($val) ? $val : 0);
                }
            })->pluck('id')->toArray();
            if (!empty($tagIds)) {
                foreach ($tagIds as $tagId) {
                    $query->whereHas('tags', fn($q) => $q->where('tags.id', $tagId));
                }
            } else {
                $query->whereRaw('1 = 0');
            }
        }

        // 5c. Filter by Slider
        if (!empty($filters['slider'])) {
            $sliderSlugs = is_array($filters['slider']) ? $filters['slider'] : explode(',', $filters['slider']);
            $sliderIds = Slider::active()->where(function ($q) use ($sliderSlugs) {
                foreach ($sliderSlugs as $slug) {
                    $q->orWhere('slug', $slug);
                }
            })->pluck('id')->toArray();
            if (!empty($sliderIds)) {
                $query->whereHas('sliders', fn($q) => $q->whereIn('sliders.id', $sliderIds));
            } else {
                $query->whereRaw('1 = 0');
            }
        }

        // 6. Filter by Price (product price + variant prices)
        $minPrice = $filters['minPrice'] ?? $filters['price_min'] ?? null;
        $maxPrice = $filters['maxPrice'] ?? $filters['price_max'] ?? null;
        if ($minPrice !== null || $maxPrice !== null) {
            $query->where(function ($q) use ($minPrice, $maxPrice) {
                $q->where(function ($simpleQ) use ($minPrice, $maxPrice) {
                    if ($minPrice !== null) {
                        $simpleQ->where('products.price', '>=', $minPrice);
                    }
                    if ($maxPrice !== null) {
                        $simpleQ->where('products.price', '<=', $maxPrice);
                    }
                })->orWhereHas('variations', function ($variantQ) use ($minPrice, $maxPrice) {
                    if ($minPrice !== null) {
                        $variantQ->where('product_variants.price', '>=', $minPrice);
                    }
                    if ($maxPrice !== null) {
                        $variantQ->where('product_variants.price', '<=', $maxPrice);
                    }
                });
            });
        }

        // 7. Filter by Dimensions (exact match on products and their variants)
        $dimensions = ['height', 'width', 'length', 'weight'];
        foreach ($dimensions as $dimension) {
            if (!empty($filters[$dimension])) {
                $rawValues = is_array($filters[$dimension]) ? $filters[$dimension] : explode(',', $filters[$dimension]);
                $values = array_map(function ($v) {
                    return (string) (float) $v;
                }, $rawValues);
                $query->where(function ($q) use ($dimension, $values) {
                    $q->whereIn("products.{$dimension}", $values)
                      ->orWhereHas('variations', function ($varQ) use ($dimension, $values) {
                          $varQ->whereIn($dimension, $values);
                      });
                });
            }
        }

        // 8. Dynamic Attribute Filters
        $reservedFilterKeys = ['brand', 'brands', 'category', 'categories', 'promotion', 'flash_sale', 'banner', 'tag', 'tags', 'slider', 'minprice', 'maxprice', 'price_min', 'price_max', 'min_price', 'max_price', 'search', 'limit', 'pagination', 'order', 'order_price', 'cursor', 'page', 'type', 'rating', 'rating_min', 'rating_max', 'height', 'width', 'length', 'weight', 'height_min', 'height_max', 'width_min', 'width_max', 'length_min', 'length_max', 'weight_min', 'weight_max', 'categoriesid', 'brandsid', 'promotionsid', 'flashsalesid', 'bannersid', 'slidersid', 'couponsid', 'tagsid', 'productsid'];
        $attributeSlugs = Attribute::pluck('slug')->toArray();

        foreach ($filters as $key => $value) {
            $lowerKey = strtolower($key);
            if (in_array($lowerKey, $reservedFilterKeys)) {
                continue;
            }
            if (in_array($lowerKey, $attributeSlugs) && !empty($value)) {
                $attrValues = is_array($value) ? $value : explode(',', $value);

                $locale = app()->getLocale();
                $attrValueIds = AttributeValue::whereHas('attribute', fn($q) => $q->where('slug', $lowerKey))
                    ->where(function ($q) use ($attrValues, $locale) {
                        foreach ($attrValues as $val) {
                            $q->orWhere('value', 'like', '%"' . $locale . '":"' . $val . '"%')
                              ->orWhere("slug", $val);
                        }
                    })
                    ->pluck('id')
                    ->toArray();

                if (!empty($attrValueIds)) {
                    $query->whereHas('variations.attributeProducts', fn($q) => $q->whereIn('attribute_value_id', $attrValueIds));
                } elseif ($value !== '' && $value !== null) {
                    $query->whereRaw('1 = 0');
                }
            }
        }

        return $query;
    }
}
