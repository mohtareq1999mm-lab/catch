<?php

namespace App\Models;

use App\Enums\RateMode;
use App\Services\Currency\CurrencyService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Spatie\Translatable\HasTranslations;

class Currency extends Model
{
    use HasTranslations, SoftDeletes;

    public array $translatable = ['name', 'symbol', 'country_name'];

    protected $fillable = [
        'code',
        'name',
        'symbol',
        'country_name',
        'numeric_code',
        'decimal_places',
        'icon',
        'is_active',
        'sort_order',
        'rate_mode',
        'manual_rate',
        'provider_rate',
        'provider',
        'last_synced_at',
        'provider_rate_at',
        'effective_rate_updated_at',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'decimal_places' => 'integer',
        'sort_order' => 'integer',
        'rate_mode' => RateMode::class,
        'manual_rate' => 'string',
        'provider_rate' => 'string',
        'last_synced_at' => 'datetime',
        'provider_rate_at' => 'datetime',
        'effective_rate_updated_at' => 'datetime',
    ];

    public function setCodeAttribute(string $value): void
    {
        $this->attributes['code'] = strtoupper($value);
    }

    public function rates(): HasMany
    {
        return $this->hasMany(CurrencyRate::class);
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    public function isBaseCurrency(): bool
    {
        return $this->code === app(CurrencyService::class)->getBaseCode();
    }

    public function isCatalogCurrency(): bool
    {
        return $this->code === app(CurrencyService::class)->getCatalogCode();
    }

    public function effectiveRate(): ?string
    {
        $mode = $this->rate_mode instanceof RateMode
            ? $this->rate_mode
            : RateMode::tryFrom((string) $this->rate_mode);

        return ($mode ?? RateMode::MANUAL) === RateMode::MANUAL
            ? $this->manual_rate
            : $this->provider_rate;
    }

    public function isRateStale(): bool
    {
        if (!$this->last_synced_at) {
            return true;
        }

        return $this->last_synced_at->lt(now()->subHours((int) config('currency.sync.stale_after_hours', 12)));
    }
}
