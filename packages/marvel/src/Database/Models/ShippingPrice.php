<?php

namespace Marvel\Database\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ShippingPrice extends Model
{
    protected $table = 'shipping_prices';

    protected $fillable = [
        'governorate_id',
        'price',
        'estimated_days',
        'free_shipping_over',
        'status',
    ];

    protected $casts = [
        'governorate_id' => 'integer',
        'price' => 'decimal:2',
        'estimated_days' => 'integer',
        'free_shipping_over' => 'decimal:2',
        'status' => 'boolean',
    ];

    public function governorate(): BelongsTo
    {
        return $this->belongsTo(Governorate::class, 'governorate_id');
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', true);
    }
}
