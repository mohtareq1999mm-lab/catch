<?php

namespace Marvel\Database\Models;

use Cviebrock\EloquentSluggable\Sluggable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\InteractsWithMedia;
use Spatie\Translatable\HasTranslations;
use Illuminate\Database\Eloquent\SoftDeletes;
use Marvel\Database\Models\CategoryShop;
use Marvel\Database\Models\ProductShop;
use Marvel\Database\Models\CouponShop;
use Marvel\Database\Models\FlashSaleShop;

class Shop extends Model implements HasMedia
{
    use Sluggable, InteractsWithMedia, HasTranslations, SoftDeletes;

    protected $table = 'shops';

    public array $translatable = ['name', 'description', 'address'];
    public $fillable = ['name', 'slug', 'description', 'logo', 'cover_image', 'address', 'status'];
    public $hidden = ['deleted_at'];

    protected $casts = [
        // 'logo' => 'json',
        // 'cover_image' => 'json',
        'address' => 'array',
        // 'settings' => 'json',
    ];

    /**
     * Return the sluggable configuration array for this model.
     *
     * @return array
     */
    public function sluggable(): array
    {
        return [
            'slug' => [
                'source' => 'name'
            ]
        ];
    }

    public  function scopeActive($query)
    {
        return $query->where('status', 1);
    }
    public  function scopeInactive($query)
    {
        return $query->where('status', 0);
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
    /**
     * @return HasOne
     */
    public function balance(): HasOne
    {
        return $this->hasOne(Balance::class, 'shop_id');
    }

    /**
     * @return BelongsTo
     */
    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    /**
     * @return HasMany
     */
    public function orders(): HasMany
    {
        return $this->hasMany(Order::class, 'shop_id');
    }

    /**
     * @return HasMany
     */
    public function attributes(): HasMany
    {
        return $this->hasMany(Attribute::class, 'shop_id');
    }


    // public function products(): HasMany
    // {
    //     return $this->hasMany(Product::class, 'shop_id');
    // }




    /**
     * @return HasMany
     */
    public function withdraws(): HasMany
    {
        return $this->hasMany(Withdraw::class, 'shop_id');
    }

    /**
     * @return HasMany
     */
    public function staffs(): HasMany
    {
        return $this->hasMany(User::class, 'shop_id');
    }

    /**
     * @return HasMany
     */
    public function refunds(): HasMany
    {
        return $this->hasMany(User::class, 'shop_id');
    }

    /**
     * @return BelongsToMany
     */

    public function categories(): BelongsToMany
    {
        return $this->belongsToMany(Category::class, 'category_shop', 'shop_id', 'category_id')
            ->using(CategoryShop::class)
            ->withPivot(['deleted_at'])
            ->wherePivotNull('deleted_at');
    }

    public function products()
    {
        return $this->belongsToMany(Product::class, 'product_shop')
            ->using(ProductShop::class)
            ->withPivot(['deleted_at'])
            ->wherePivotNull('deleted_at');
    }

    public function coupons()
    {
        return $this->belongsToMany(Coupon::class, 'coupon_shop')
            ->using(CouponShop::class)
            ->withPivot(['deleted_at'])
            ->wherePivotNull('deleted_at');
    }

    public function flashSales()
    {
        return $this->belongsToMany(FlashSale::class, 'flash_sale_shop')
            ->using(FlashSaleShop::class)
            ->withPivot(['deleted_at'])
            ->wherePivotNull('deleted_at');
    }
    public function promotions()
    {
        return $this->belongsToMany(Promotion::class, 'promotion_shop')
            ->using(PromotionShop::class)
            ->withPivot(['deleted_at'])
            ->wherePivotNull('deleted_at');
    }

    /**
     * @return BelongsToMany
     */
    public function users(): BelongsToMany
    {
        return $this->BelongsToMany(User::class, 'user_shop');
    }

    /**
     * @return HasMany
     */
    public function conversations(): HasMany
    {
        return $this->hasMany(Conversation::class, 'shop_id');
    }

    /**
     * faqs
     *
     * @return HasMany
     */
    public function faqs(): HasMany
    {
        return $this->HasMany(Faqs::class);
    }

    /**
     * terms and conditions
     *
     * @return HasMany
     */
    public function terms_and_conditions(): HasMany
    {
        return $this->HasMany(TermsAndConditions::class);
    }
    /**
     * faqs
     *
     * @return HasMany
     */
    // public function coupons(): HasMany
    // {
    //     return $this->HasMany(Coupon::class);
    // }
    /**
     * ownership transfers
     *
     * @return HasOne
     */
    public function ownership_history(): HasOne
    {
        return $this->hasOne(OwnershipTransfer::class, 'shop_id');
    }
}
