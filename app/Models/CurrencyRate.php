<?php

namespace App\Models;

use App\Enums\RateSource;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CurrencyRate extends Model
{
    protected $fillable = [
        'currency_id',
        'exchange_rate',
        'effective_date',
        'source',
        'provider',
    ];

    protected $casts = [
        'exchange_rate' => 'string',
        'effective_date' => 'date',
        'source' => RateSource::class,
    ];

    public function currency(): BelongsTo
    {
        return $this->belongsTo(Currency::class);
    }

    public function scopeEffectiveOn($query, string $date)
    {
        return $query->whereDate('effective_date', '<=', $date)
            ->orderByDesc('effective_date');
    }
}
