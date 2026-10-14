<?php

namespace Marvel\Database\Models;

use Cviebrock\EloquentSluggable\Sluggable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Marvel\Enums\PromotionMountType;
use Spatie\Translatable\HasTranslations;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\InteractsWithMedia;

class Promotion extends Model implements HasMedia
{
    use HasTranslations, InteractsWithMedia ,Sluggable;
    public  array $translatable = ['name'];

    protected $table = 'promotions';

    public $fillable = [
        'name',
        'slug',
        'type',
        'type_amount',
        'value',
        'discount',
        'max_discount_amount',
        'code',
        'required_quantity_type',
        'minimum_order_amount',
        'apply_to',
        'limiter',
        'usage',
        'start_at',
        'end_at',
        'status'
    ];

    protected $casts = [
        'start_at' => 'date',
        'end_at' => 'date',
        'status' => 'boolean',
        'usage' => 'integer',
        'limiter' => 'integer',
        'required_quantity_type' => 'integer',
        'value' => 'float',
        'discount' => 'float',
        'minimum_order_amount' => 'float',
        'max_discount_amount' => 'float',
    ];


    /**
     * Return the sluggable configuration array.
     *
     * @return array
     */
    public function sluggable(): array
    {
        return [
            'slug' => [
                'source' => 'name',
            ],
        ];
    }
    
    protected static function boot()
    {
        parent::boot();

        static::addGlobalScope('order', function (Builder $builder) {
            $builder->orderBy('promotions.created_at', 'desc');
        });

        static::creating(function (self $promotion) {
            if (empty($promotion->code)) {
                $promotion->code = self::generateUniqueCode($promotion);
            }
        });

        static::saving(function (self $promotion) {
            if (!Schema::hasColumn($promotion->getTable(), 'discount')) {
                return;
            }

            if ($promotion->discount !== null && ($promotion->value === null || !$promotion->isDirty('value'))) {
                $promotion->value = $promotion->discount;
            }

            if ($promotion->value !== null && ($promotion->discount === null || !$promotion->isDirty('discount'))) {
                $promotion->discount = $promotion->value;
            }
        });
        
    }

    public function products(): BelongsToMany
    {
        return $this->belongsToMany(Product::class, 'promotion_product', 'promotion_id', 'product_id');
    }

    public function giftProducts(): BelongsToMany
    {
        return $this->belongsToMany(Product::class, 'promotion_gift_products', 'promotion_id', 'product_id')
            ->withPivot('quantity', 'product_variant_id')
            ->withTimestamps();
    }

    public function appliesToAllProducts(): bool
    {
        return ($this->apply_to ?? 'all_products') === 'all_products';
    }



    public function typeByLang()
    {
        $map = [
            'ar' => [
                'fixed_rate' => 'خصم من السعر بالقيمة',
                'percentage' => 'خصم بالنسبة المئوية',
                'quantity' => 'خصم حسب الكمية',
                'gift' => 'هدية',
                'price' => 'خصم من السعر',
            ],
            'en' => [
                'fixed_rate' => 'Fixed discount',
                'percentage' => 'Percentage discount',
                'amount' => 'Amount discount',
                'quantity' => 'Quantity promotion',
                'gift' => 'Gift promotion',
                'price' => 'Price discount',
            ],
        ];

        $locale = app()->getLocale();
        return $map[$locale][$this->type] ?? $this->type;
    }

    public function scopeActive($query)
    {
        return $query->where('status', true);
    }


    public function scopeValid($query)
    {
        return $query
            ->where('status', true)
            ->where(function ($query) {
                $query->whereNull('limiter')
                    ->orWhereColumn('usage', '<', 'limiter');
            })
            ->where(function ($query) {
                $query->whereNull('start_at')
                    ->orWhereDate('start_at', '<=', today());
            })
            ->where(function ($query) {
                $query->whereNull('end_at')
                    ->orWhereDate('end_at', '>=', today());
            });
    }
    public function scopeSearch($query, $field, $term, $locale)
    {
        return $query->where($field, 'like', "%$term%");
    }

    public function isValid(): bool
    {
        $today = today();

        return $this->status
            && (!$this->start_at || $this->start_at->lte($today))
            && (!$this->end_at || $this->end_at->gte($today))
            && (is_null($this->limiter) || (int) $this->usage < (int) $this->limiter);
    }



    public function isGiftPromotion(): bool
    {
        return $this->type_amount === PromotionMountType::GIFT;
    }
    public function isPercentagePromotion(): bool
    {
        return $this->type_amount === PromotionMountType::PERCENTAGE;
    }
    public function isFixedRatePromotion(): bool
    {
        return $this->type_amount === PromotionMountType::FIXED_RATE;
    }

    public function isRequiredQuantityTrue($qty): bool
    {
        return is_null($this->required_quantity_type) || $qty >= $this->required_quantity_type;
    }


    public function discountAmount(float $price, int $qty = 1): float
    {
        if ($price === null || $price <= 0) {
            return 0.0;
        }
        if (!$this->isRequiredQuantityTrue($qty)) {
            return 0.0;
        }

        $price = (float) $price;
        $value = (float) ($this->discount ?? $this->value);
        $maxValue = $this->max_discount_amount !== null ? (float) $this->max_discount_amount : null;

        if ($this->isPercentagePromotion()) {
            $discount = $price * ($value / 100);

            if ($maxValue !== null) {
                $discount = min($discount, $maxValue);
            }

            return round(max(0.0, $discount), 2);
        }

        if ($this->isFixedRatePromotion()) {
            return round(max(0.0, min($price, $value)), 2);
        }

        if ($this->isGiftPromotion()) {
            return 0.0;
        }


        return 0.0;
    }


    public function calcPrice(float $price, int $qty = 1): float
    {
        return round(max(0.0, $price - $this->discountAmount($price, $qty)), 2);
    }
    public function applyGift(int $qty)
    {
        if (!$this->isGiftPromotion()) {
            return;
        }

        if (!$this->isRequiredQuantityTrue($qty)) {
            return;
        }

        return  $this->products()->get();
    }

    private static function generateUniqueCode(self $promotion, int $length = 10): string
    {
        $prefix = self::codePrefix($promotion);

        do {
            $code = $prefix . strtoupper(Str::random($length));
        } while (self::query()->where('code', $code)->exists());

        return $code;
    }

    private static function codePrefix(self $promotion): string
    {
        return match ($promotion->apply_to ?? 'specific_products') {
            'all_products' => 'ALL',
            'specific_products' => 'PRO',
            default => 'PRO',
        };
    }
}
