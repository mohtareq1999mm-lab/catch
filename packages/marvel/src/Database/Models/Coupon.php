<?php

namespace Marvel\Database\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Str;
use Marvel\Enums\DiscountType;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\InteractsWithMedia;
use Spatie\Translatable\HasTranslations;

class Coupon extends Model implements HasMedia
{
    use HasTranslations, InteractsWithMedia;

    protected $translatable = ['name'];

    protected $table = 'coupons';

    public $fillable = [
        'code',
        'slug',
        'name',
        'discount_type',
        'discount',
        'max_discount_amount',
        'start_date',
        'end_date',
        'limiter',
        'used',
        'status',
        'border_color',
        'borderless',
    ];

    // protected $appends = ['is_valid'];

    protected $casts = [
        'status' => 'boolean',
        'start_date' => 'date',
        'end_date' => 'date',
        'borderless' => 'boolean',
    ];

    protected static function boot()
    {
        parent::boot();
        // Order by updated_at desc
        static::addGlobalScope('order', function (Builder $builder) {
            $builder->orderBy('updated_at', 'desc');
        });

        static::creating(function ($coupon) {
            if (!empty($coupon->code)) {
                return;
            }

            do {
                $code = strtoupper(Str::random(7));
            } while (self::where('code', $code)->exists());

            $coupon->code = strtolower(preg_replace('/\s+/', '_',  'coupon' . "_" . $code));
        });

        static::saving(function (Coupon $coupon) {
            try {
                $coupon->validateMultiUseConfiguration();
            } catch (\Throwable $e) {
                \Illuminate\Support\Facades\Log::warning("Coupon validation warning: " . $e->getMessage());
            }
        });
    }

    /**
     * Validate coupon configuration for multi-use scenarios
     *
     * Business Rules:
     * - Public coupons (no assignments) are ALWAYS single-use per user
     * - For multi-use per user, MUST use assignment flow
     */
    public function validateMultiUseConfiguration(): void
    {
        $hasAssignments = $this->assignments()->exists();

        if (!$hasAssignments) {
            return;
        }

        $assignmentsWithMultiUse = $this->assignments()
            ->where('max_uses', '>', 1)
            ->exists();

        if ($assignmentsWithMultiUse) {
            try {
                $targeting = $this->targeting;
                if (!$targeting) {
                    \Illuminate\Support\Facades\Log::warning("Coupon {$this->code} has multi-use assignments but no targeting configuration");
                }
            } catch (\Throwable $e) {
                // ignore if targeting table missing
            }
        }
    }

    /**
     * Get user-friendly usage description
     */
    public function getUsageDescription(): string
    {
        $hasAssignments = $this->assignments()->exists();

        if (!$hasAssignments) {
            $limiter = $this->limiter ?? 'unlimited';
            return "Public coupon: Single use per customer (global limit: {$limiter})";
        }

        $maxUses = $this->assignments()->max('max_uses') ?? 1;
        return "Assigned coupon: Up to {$maxUses} uses per assigned customer";
    }

    /**
     * Check if coupon is configured for multi-use
     */
    public function isMultiUsePerUser(): bool
    {
        return $this->assignments()->where('max_uses', '>', 1)->exists();
    }

    /**
     * Check if coupon is public (no assignments)
     */
    public function isPublic(): bool
    {
        return !$this->assignments()->exists();
    }

    /**
     * @return BelongsToMany
     */
    public function products(): BelongsToMany
    {
        return $this->belongsToMany(Product::class, 'coupon_product', 'coupon_id', 'product_id');
    }

    /**
     * @return HasMany
     */
    public function orders(): HasMany
    {
        return $this->hasMany(Order::class, 'coupon', 'code');
    }

    /**
     * @return BelongsToMany
     */
    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'coupon_usages')
            ->withPivot(['order_id', 'used_at'])
            ->withTimestamps();
    }

    /**
     * @return HasMany
     */
    public function couponUsages(): HasMany
    {
        return $this->hasMany(CouponUsage::class, 'coupon_id');
    }

    /**
     * @return HasMany
     */
    public function assignments(): HasMany
    {
        return $this->hasMany(CouponAssignment::class, 'coupon_id');
    }

    /**
     * @return HasOne
     */
    public function targeting(): HasOne
    {
        return $this->hasOne(CouponTargeting::class, 'coupon_id');
    }

    /**
     * @return HasMany
     */
    public function claims(): HasMany
    {
        return $this->hasMany(CouponClaim::class, 'coupon_id');
    }




    /**
     * @deprecated
     * Use CouponValidator::validate() instead. Will be removed in a future release.
     */
    public function isValid(): bool
    {

        $today = today();

        return $this->status
            && (!$this->start_date || $this->start_date->lte($today))
            && (!$this->end_date || $this->end_date->gte($today))
            && (is_null($this->limiter) || $this->used < $this->limiter);
    }
    public function scopeValid($query)
    {
        return $query
            ->where('status', true)
            ->where(function ($query) {
                $query->whereNull('limiter')
                    ->orWhereColumn('used', '<', 'limiter');
            })
            ->where(function ($query) {
                $query->whereNull('start_date')
                    ->orWhereDate('start_date', '<=', today());
            })
            ->where(function ($query) {
                $query->whereNull('end_date')
                    ->orWhereDate('end_date', '>=', today());
            });
    }
    public function scopeInvalid($query)
    {
        return $query
            ->where('status', false)
            ->orWhere(function ($query) {
                $query->whereNotNull('limiter')
                    ->whereColumn('used', '>=', 'limiter');
            })
            ->orWhere(function ($query) {
                $query->whereNotNull('start_date')
                    ->whereDate('start_date', '>', today());
            })
            ->orWhere(function ($query) {
                $query->whereNotNull('end_date')
                    ->whereDate('end_date', '<', today());
            });
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


    public function typeByLang()
    {
        $map = [
            'ar' => [
                'fixed_rate' => 'خصم من السعر بالقيمة',
                'percentage' => 'خصم بالنسبة المئوية',

            ],
            'en' => [
                'fixed_rate' => 'Fixed discount',
                'percentage' => 'Percentage discount',
            ],
        ];

        $locale = app()->getLocale();
        return $map[$locale][$this->discount_type] ?? $this->discount_type;
    }

    /**
     * @deprecated
     * Use CouponCalculator::calculate() instead. Will be removed in a future release.
     */
    public function calcPrice($price): ?float
    {
        if ($price === null) {
            return null;
        }

        $price = (float) $price;
        $discount = (float) $this->discount;

        if ($this->discount_type === DiscountType::PERCENTAGE) {

            $discountAmount = $price * ($discount / 100);

            if ($this->max_discount_amount !== null) {
                $discountAmount = min(
                    $discountAmount,
                    (float) $this->max_discount_amount
                );
            }

            return round(max(0, $price - $discountAmount), 2);
        }

        if ($this->discount_type === DiscountType::FIXED_RATE) {
            return round(max(0, $price - $discount), 2);
        }

        return round($price, 2);
    }
}
