<?php

namespace Marvel\Database\Models;

use App\Models\Scopes\FastShippingScope;
use Carbon\Carbon;
use Exception;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Marvel\Enums\DiscountType;
use Marvel\Enums\ItemType;
use Marvel\Enums\ProductStatus;
use Marvel\Traits\Excludable;
use Marvel\Services\Pricing\ProductPricingService;
use Spatie\MediaLibrary\HasMedia;
use Spatie\Translatable\HasTranslations;
use Spatie\MediaLibrary\InteractsWithMedia;
use Laravel\Scout\Searchable;


class Product extends Model implements HasMedia
{
    use HasTranslations, SoftDeletes, Excludable, InteractsWithMedia, Searchable;

    protected $table = 'products';
    protected $fillable = [
        'name',
        'slug',
        'description',
        'price',
        'product_type',
        'item_type',
        'type_id',
        'sku',
        'stock_quantity',
        'quantity',
        'reserved_quantity',
        'sold_quantity',
        'in_stock',
        'status',
        'height',
        'width',
        'length',
        'weight',
        'has_flash_sale',
        'is_fast_shipping_available',
        'has_discount',
        'pieces',
        'discount_type',
        'discount_amount',
        'discount_status',
        'start_date',
        'end_date',
        'price_after_discount',
        'price_after_flash_sale',
        'tax_enabled',
        'tax_rate',
    ];
    public array $translatable = ['name', 'description'];
    public $hideMeta = true;


    public function toSearchableArray()
    {
        $nameTranslations = $this->getTranslations('name');
        $descriptionTranslations = $this->getTranslations('description');

        return [
            'id' => $this->id,
            'name' => $this->name,
            'name_en' => $nameTranslations['en'] ?? $this->name,
            'name_ar' => $nameTranslations['ar'] ?? $this->name,
            'description' => $this->description,
            'description_en' => $descriptionTranslations['en'] ?? $this->description,
            'description_ar' => $descriptionTranslations['ar'] ?? $this->description,
        ];
    }


    protected $casts = [
        'discount_status' => 'boolean',
        'has_discount' => 'boolean',
        'has_flash_sale' => 'boolean',
        'is_fast_shipping_available' => 'boolean',
        'stock_quantity' => 'integer',
        'reserved_quantity' => 'integer',
        'sold_quantity' => 'integer',
        'price' => 'float',
        'tax_enabled' => 'boolean',
        'tax_rate' => 'float',
    ];

    protected $appends = [
        'current_price',
        'price_after_discount',
        'price_after_flash_sale',
        'final_price',
    ];


    protected static function boot()
    {
        parent::boot();

        static::creating(function ($product) {
            // Only set SKU if not already provided
            if (empty($product->sku)) {
                $lastId = static::max('id') + 1;

                $product->sku = 'PRD-' . str_pad($lastId, 3, '0', STR_PAD_LEFT);
            }
        });
    }

    protected static function booted(): void
    {
        static::addGlobalScope(new FastShippingScope);
    }


    public function isDiscountActive(): bool
    {
        // If discount_status is explicitly set, allow it to override auto behavior.
        if (!is_null($this->discount_status)) {
            if ($this->discount_status === false) {
                return false;
            }
        }

        if (!$this->has_discount) {
            return false;
        }

        $now = Carbon::now();

        if ($this->start_date && $now->lt(Carbon::parse($this->start_date))) {
            return false;
        }

        if ($this->end_date && $now->gt(Carbon::parse($this->end_date))) {
            return false;
        }

        return true;
    }

    public function getActiveFlashSale()
    {
        if (!$this->has_flash_sale) {
            return null;
        }

        if ($this->relationLoaded('flash_sales')) {
            return $this->flash_sales
                ->filter(fn(FlashSale $flashSale) => $flashSale->isValid())
                ->sortByDesc('start_date')
                ->first();
        }

        $now = Carbon::now();

        return $this->flash_sales()
            ->where('status', true)
            ->whereDate('start_date', '<=', $now)
            ->whereDate('end_date', '>=', $now)
            ->orderBy('start_date', 'desc')
            ->first();
    }

    public function isFlashSaleValid($flashSale = null): bool
    {
        $flashSale = $flashSale ?? $this->flash_sales()->orderBy('start_date', 'desc')->first();
        if (!$flashSale) {
            return false;
        }

        if (!$flashSale->status) {
            return false;
        }

        $now = Carbon::now();

        if ($flashSale->start_date && $now->lt(Carbon::parse($flashSale->start_date))) {
            return false;
        }

        if ($flashSale->end_date && $now->gt(Carbon::parse($flashSale->end_date))) {
            return false;
        }

        return true;
    }

    public function disableInvalidFlashSales(): int
    {
        return (int) $this->flash_sales()->where('status', false)->count();
    }

    public function getCurrentPrice()
    {
        return app(ProductPricingService::class)->calculateProductCurrentPrice($this);
    }

    public function getCurrentPriceAttribute()
    {
        // Storefront enrichment sets a tax-inclusive current_price via
        // setAttribute(). That authoritative value must be respected.
        if (array_key_exists('current_price', $this->attributes) && $this->attributes['current_price'] !== null) {
            return $this->attributes['current_price'];
        }

        return $this->getCurrentPrice();
    }

    public function getDiscountedPrice()
    {
        return app(ProductPricingService::class)->calculateProductPricing($this)['price_after_discount'];
    }

    public function getPriceAfterDiscountAttribute()
    {
        return $this->getDiscountedPrice();
    }

    public function getFlashSalePrice($basePrice = null)
    {
        return app(ProductPricingService::class)->calculateFlashSalePrice($this->getActiveFlashSale(), $basePrice ?? $this->price);
    }

    public function getPriceAfterFlashSaleAttribute()
    {
        return $this->getFlashSalePrice($this->price);
    }

    public function getFinalPriceAttribute()
    {
        return $this->getCurrentPrice();
    }

    // public function getSalePriceAttribute()
    // {
    //     return $this->getCurrentPrice();
    // }

    private function calculateDiscountedPrice($price)
    {
        return app(ProductPricingService::class)->calculateDiscountedPrice(
            $price,
            $this->discount_type ?? DiscountType::PERCENTAGE,
            $this->discount_amount ?? 0
        );
    }



    public function fetchBlockedDatesForAProduct()
    {
        return Availability::where('product_id', $this->id)->where('bookable_type', 'Marvel\Database\Models\Product')->whereDate('to', '>=', Carbon::now())->get();
    }

    /**
     * @return BelongsTo
     */
    public function type(): BelongsTo
    {
        return $this->belongsTo(Type::class, 'type_id');
    }

    /**
     * @return BelongsTo
     */
    public function shops(): BelongsToMany
    {
        return $this->belongsToMany(Shop::class, 'product_shop');
    }

    /**
     * @return BelongsTo
     */
    public function author(): BelongsTo
    {
        return $this->belongsTo(Author::class, 'author_id');
    }

    /**
     * @return BelongsTo
     */
    public function manufacturer(): BelongsTo
    {
        return $this->belongsTo(Manufacturer::class, 'manufacturer_id');
    }

    /**
     * @return BelongsTo
     */
    public function shipping(): BelongsTo
    {
        return $this->belongsTo(Shipping::class, 'shipping_class_id');
    }

    /**
     * @return BelongsToMany
     */
    public function categories(): BelongsToMany
    {
        return $this->belongsToMany(Category::class, 'category_product', 'product_id', 'category_id');
    }

    /**
     * @return BelongsToMany
     */
    public function brands(): BelongsToMany
    {
        return $this->belongsToMany(Brand::class, 'brand_product', 'product_id', 'brand_id');
    }

    /**
     * @return BelongsToMany
     */
    public function banners(): BelongsToMany
    {
        return $this->belongsToMany(Banner::class, 'banner_product', 'product_id', 'banner_id');
    }

    /**
     * @return BelongsToMany
     */
    public function tags(): BelongsToMany
    {
        return $this->belongsToMany(Tag::class, 'product_tag');
    }



    /**
     * @return belongsToMany
     */
    public function orders(): belongsToMany
    {
        return $this->belongsToMany(Order::class)->withTimestamps();
    }

    /**
     * @return HasMany
     */
    public function variations(): HasMany
    {
        return $this->hasMany(ProductVariant::class, 'product_id')->with('attributeProducts.attributeValue.attribute');
    }

    /**
     * @return HasMany
     */
    public function reviews(): HasMany
    {
        return $this->hasMany(Review::class, 'product_id');
    }

    /**
     * @return HasMany
     */
    public function questions(): HasMany
    {
        return $this->hasMany(Question::class, 'product_id');
    }

    /**
     * @return HasMany
     */
    public function wishlists(): HasMany
    {
        return $this->hasMany(Wishlist::class, 'product_id');
    }

    public function getRatingsAttribute()
    {
        return round($this->reviews()->avg('rating'), 2);
    }

    public function getTotalReviewsAttribute()
    {
        return $this->reviews()->count();
    }

    public function getRatingCountAttribute()
    {
        return $this->reviews()->orderBy('rating', 'DESC')->groupBy('rating')->select('rating', DB::raw('count(*) as total'))->get();
    }

    public function getMyReviewAttribute()
    {
        if (auth()->user() && !empty($this->reviews()->where('user_id', auth()->user()->id)->first())) {
            return $this->reviews()->where('user_id', auth()->user()->id)->get();
        }
        return null;
    }

    public function getInWishlistAttribute()
    {
        if (auth()->user() && !empty($this->wishlists()->where('user_id', auth()->user()->id)->first())) {
            return true;
        }
        return false;
    }

    public function availabilities()
    {
        return $this->morphMany(Availability::class, 'bookable');
    }


    /**
     * @return BelongsToMany
     */
    public function dropoff_locations(): BelongsToMany
    {
        return $this->belongsToMany(Resource::class, 'dropoff_location_product', 'product_id', 'resource_id');
    }
    /**
     * @return BelongsToMany
     */
    public function pickup_locations(): BelongsToMany
    {
        return $this->belongsToMany(Resource::class, 'pickup_location_product', 'product_id', 'resource_id');
    }
    /**
     * @return BelongsToMany
     */
    public function deposits(): BelongsToMany
    {
        return $this->belongsToMany(Resource::class, 'deposit_product', 'product_id', 'resource_id');
    }
    /**
     * @return BelongsToMany
     */
    public function persons(): BelongsToMany
    {
        return $this->belongsToMany(Resource::class, 'person_product', 'product_id', 'resource_id');
    }
    /**
     * @return BelongsToMany
     */
    public function features(): BelongsToMany
    {
        return $this->belongsToMany(Resource::class, 'feature_product', 'product_id', 'resource_id');
    }



    /**
     * @return BelongsToMany
     */
    public function flash_sales(): BelongsToMany
    {
        return $this->belongsToMany(FlashSale::class, 'flash_sale_products')->withPivot('flash_sale_id', 'product_id');
    }

    /**
     * Purchasable files of a DIGITAL product (private disk).
     */
    public function digitalAssets(): HasMany
    {
        return $this->hasMany(\App\Models\DigitalAsset::class, 'product_id');
    }

    /**
     * @return BelongsToMany
     */
    public function promotions(): BelongsToMany
    {
        return $this->belongsToMany(Promotion::class, 'promotion_product', 'product_id', 'promotion_id');
    }

    /**
     * @return BelongsToMany
     */
    public function coupons(): BelongsToMany
    {
        return $this->belongsToMany(Coupon::class, 'coupon_product', 'product_id', 'coupon_id');
    }

    /**
     * @return BelongsToMany
     */
    public function sliders(): BelongsToMany
    {
        return $this->belongsToMany(Slider::class, 'slider_product');
    }

    /**
     * flash_sale_requests
     *
     * @return BelongsToMany
     */
    public function flash_sale_requests(): BelongsToMany
    {
        return $this->belongsToMany(FlashSaleRequests::class, "flash_sale_requests_products");
    }

    public function loadRelated($slug, $limit = 10, $language = DEFAULT_LANGUAGE)
    {
        $relatedProducts = [];
        try {
            $product = $this->where('slug', $slug)->firstOrFail();
            $categories = $product->categories()->pluck('id');

            $relatedProducts = $this->where('language', $language)
                ->whereHas('categories', function ($query) use ($categories) {
                    $query->whereIn('categories.id', $categories);
                })->with('type')->limit($limit)->get();
        } catch (Exception $e) {
            logger($e->getMessage()); // logging the error
        }
        $this->setRelation('related_products', $relatedProducts);
        return $this;
    }


    public function scopeActiveStatus($query)
    {
        return $query->where(function ($q) {
            $q->where('status', true)
                ->orWhere('status', ProductStatus::PUBLISH);
        });
    }

    public function scopeActive($query)
    {
        return $query->activeStatus()->where(function ($builder) {
            $builder->where('in_stock', true)
                ->orWhereRaw('(COALESCE(stock_quantity, 0) - COALESCE(reserved_quantity, 0)) > 0');
        });
    }

    public function scopeFastShippingAvailable($query)
    {
        return $query->where('is_fast_shipping_available', true);
    }

    /**
     * Physical products only. Rows created before the item_type feature
     * (NULL item_type) are legacy physical products.
     */
    public function scopePhysical($query)
    {
        return $query->where(function ($q) {
            $q->where('item_type', ItemType::PHYSICAL)->orWhereNull('item_type');
        });
    }

    /**
     * Digital products only (ItemType::DIGITAL).
     */
    public function scopeDigital($query)
    {
        return $query->where('item_type', ItemType::DIGITAL);
    }

    public function scopeFlashSaleWithinOneWeek($query)
    {
        $today = Carbon::today();
        $oneWeekFromNow = Carbon::today()->addWeek();

        return $query->where('has_flash_sale', true)
            ->whereHas('flash_sales', function ($flashSaleQuery) use ($today, $oneWeekFromNow) {
                $flashSaleQuery->where('status', true)
                    ->whereDate('start_date', '<=', $today)
                    ->whereDate('end_date', '>=', $oneWeekFromNow);
            });
    }

    public function scopeDiscountWithinOneWeek($query)
    {
        $today = Carbon::today();
        $oneWeekFromNow = Carbon::today()->addWeek();

        return $query->where('has_discount', true)
            ->where(function ($discountQuery) use ($today, $oneWeekFromNow) {
                $discountQuery->whereNull('discount_status')
                    ->orWhere('discount_status', true);
            })
            ->where(function ($discountQuery) use ($today, $oneWeekFromNow) {
                $discountQuery->whereNull('start_date')
                    ->orWhereDate('start_date', '<=', $today);
            })
            ->where(function ($discountQuery) use ($oneWeekFromNow) {
                $discountQuery->whereNull('end_date')
                    ->orWhereDate('end_date', '>=', $oneWeekFromNow);
            });
    }

    public function isSimple()
    {
        return $this->product_type === 'simple';
    }

    public function getAvailableStockAttribute(): int
    {
        return max(0, (int) $this->stock_quantity - (int) ($this->reserved_quantity ?? 0));
    }

    public function getQuantityAttribute(): int
    {
        return $this->available_stock;
    }

    public function setQuantityAttribute($value): void
    {
        $this->attributes['stock_quantity'] = (int) $value;
    }
    public function scopeSearch($query, $field, $term, $locale)
    {
        return $query->where(function ($q) use ($field, $term, $locale) {
            $translatable = $this->translatable ?? [];
            if (in_array($field, $translatable)) {
                $q->where($field . '->' . $locale, 'like', "%$term%")
                    ->orWhere($field, 'like', "%$term%");
            } else {
                $q->where($field, 'like', "%$term%");
            }
        });
    }

    public function scopeFilter($query, array $filters)
    {
        return app(\App\Services\General\ProductFilter::class)->apply($query, $filters);
    }
}
