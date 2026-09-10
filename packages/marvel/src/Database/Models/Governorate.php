<?php

namespace Marvel\Database\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Spatie\Translatable\HasTranslations;

class Governorate extends Model
{
    use HasTranslations;

    protected $table = 'governorates';

    protected $fillable = [
        'country_id',
        'name',
        'status',
        'is_fast_shipping_enabled',
    ];

    public array $translatable = ['name'];

    protected $casts = [
        'status' => 'boolean',
        'is_fast_shipping_enabled' => 'boolean',
        'country_id' => 'integer',
    ];

    public function country(): BelongsTo
    {
        return $this->belongsTo(Country::class, 'country_id');
    }

    public function cities(): HasMany
    {
        return $this->hasMany(City::class, 'governorate_id');
    }

    public function shippingPrice(): HasOne
    {
        return $this->hasOne(ShippingPrice::class, 'governorate_id');
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', true);
    }

    public function scopeFastShippingEnabled(Builder $query): Builder
    {
        return $query->where('is_fast_shipping_enabled', true);
    }
}
