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

    /**
     * F-04: `used` is system-controlled only.
     * Enforcement is at the repository boundary (CouponRepository $dataArray
     * whitelists business fields; `used`/`code` never pass via generic admin
     * input — proven by `store_coupon_strips_system_managed_fields`).
     * `used` is mutated at runtime only via `increment('used')` in
     * OrderService::recordCouponUsage; admin configures capacity via `limiter`.
     * Kept in $fillable for internal/test/seed setup (forceFill alternative
     * would churn 20+ tests); external mass assignment must always go through
     * the repository whitelist, never Model::create($request->all()).
     */

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
            // B1: normalize BEFORE generation so every persisted code is
            // canonical. (saving fires before creating on insert, so a
            // saving-only normalization would miss generated codes.)
            if (!empty($coupon->code)) {
                $coupon->code = \App\Support\CouponCode::normalize($coupon->code);
            } else {
                do {
                    $code = strtoupper(Str::random(7));
                } while (self::byCode($code)->exists());

                $coupon->code = \App\Support\CouponCode::normalize(
                    preg_replace('/\s+/', '_', 'coupon' . '_' . $code)
                );
            }

            // M3: case-insensitive canonical duplicate guard (the DB unique
            // is collation-dependent; SQLite would allow SAVE10/save10).
            if (self::byCode($coupon->code)->exists()) {
                throw new \InvalidArgumentException('Coupon code is already taken.');
            }
        });

        static::creating(function ($coupon) {
            // CP-11 hardening: slug is server-managed (NOT NULL column).
            // A supplied slug is kept when it sanitizes cleanly and is
            // unused; otherwise a unique suffixed slug is generated (M5:
            // fully non-latin names sanitize to '' and must not yield
            // a bare '-xxxxxx' slug).
            $supplied = Str::slug((string) ($coupon->slug ?? ''));
            if ($supplied !== '' && !self::where('slug', $supplied)->exists()) {
                $coupon->slug = $supplied;

                return;
            }

            $slug = $supplied !== '' ? $supplied : 'coupon';
            if ($slug === 'coupon') {
                $name = $coupon->getAttribute('name');
                $base = is_array($name) ? ($name['en'] ?? reset($name) ?: 'coupon') : (string) ($name ?: 'coupon');
                $slug = Str::slug($base) !== '' ? Str::slug($base) : 'coupon';
            }
            $candidate = $slug . '-' . strtolower(Str::random(6));
            $tries = 0;
            while (self::where('slug', $candidate)->exists() && $tries < 5) {
                $candidate = $slug . '-' . strtolower(Str::random(6));
                $tries++;
            }
            $coupon->slug = $candidate;
        });

        static::saving(function (Coupon $coupon) {
            // CP-09: canonical normalization on every write. Historical ORDER
            // snapshots are never touched (normalization applies to the
            // coupons table only; order lookups match case-insensitively).
            if (!empty($coupon->code)) {
                $coupon->code = \App\Support\CouponCode::normalize($coupon->code);
            }

            // CP-05: required business constraints throw (fail-closed).
            // Advisory multi-use guidance stays warn-only inside
            // validateMultiUseConfiguration().
            $coupon->validateCouponConfiguration();

            try {
                $coupon->validateMultiUseConfiguration();
            } catch (\Throwable $e) {
                \Illuminate\Support\Facades\Log::warning("Coupon validation warning: " . $e->getMessage());
            }
        });
    }

    /**
     * CP-05 field enforcement matrix (fail-closed, enforced on every save):
     *
     * | field               | rule                                    |
     * |---------------------|-----------------------------------------|
     * | discount            | numeric, >= 0; percentage <= 100        |
     * | discount_type       | percentage | fixed_rate | free_shipping |
     * | max_discount_amount | null or >= 0                            |
     * | limiter             | null or integer >= 0                    |
     * | start/end dates     | end >= start when both set              |
     * | status              | boolean                                 |
     * | used                | system-managed (not admin-writable)     |
     *
     * @throws \InvalidArgumentException
     */
    public function validateCouponConfiguration(): void
    {
        $discount = $this->discount;

        if ($discount !== null && (!is_numeric($discount) || (float) $discount < 0)) {
            throw new \InvalidArgumentException('Coupon discount must be a number >= 0.');
        }

        $validTypes = \Marvel\Enums\DiscountType::getValues();
        if ($this->discount_type !== null && !in_array($this->discount_type, $validTypes, true)) {
            throw new \InvalidArgumentException('Coupon discount_type is invalid.');
        }

        if (
            $this->discount_type === \Marvel\Enums\DiscountType::PERCENTAGE
            && $discount !== null && (float) $discount > 100
        ) {
            throw new \InvalidArgumentException('Percentage coupon discount must be <= 100.');
        }

        if ($this->max_discount_amount !== null && (float) $this->max_discount_amount < 0) {
            throw new \InvalidArgumentException('Coupon max_discount_amount must be >= 0.');
        }

        if ($this->limiter !== null && (int) $this->limiter < 0) {
            throw new \InvalidArgumentException('Coupon limiter must be >= 0.');
        }

        if ($this->start_date && $this->end_date) {
            $start = $this->start_date instanceof \DateTimeInterface
                ? $this->start_date
                : new \DateTimeImmutable((string) $this->start_date);
            $end = $this->end_date instanceof \DateTimeInterface
                ? $this->end_date
                : new \DateTimeImmutable((string) $this->end_date);
            if ($end < $start) {
                throw new \InvalidArgumentException('Coupon end_date must be >= start_date.');
            }
        }
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
    /**
     * CP-09: canonical code lookup. Case-insensitive (UPPER) on every engine
     * so `save10`, ` SAVE10 ` and `SAVE10` resolve identically. Historical
     * order snapshots are matched the same way; snapshots are never rewritten.
     */
    public function scopeByCode($query, ?string $code)
    {
        return \App\Support\CouponCode::queryByCode($query, $code);
    }

    public function scopeSearch($query, $field, $term, $locale)    {
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
