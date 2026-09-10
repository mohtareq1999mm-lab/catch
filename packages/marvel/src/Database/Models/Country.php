<?php

namespace Marvel\Database\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Spatie\Translatable\HasTranslations;

class Country extends Model
{
    use HasTranslations;

    protected $table = 'countries';

    protected $fillable = [
        'name',
        'phone_code',
        'status',
    ];

    public array $translatable = ['name'];

    protected $casts = [
        'status' => 'boolean',
    ];

    public function governorates(): HasMany
    {
        return $this->hasMany(Governorate::class, 'country_id');
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', true);
    }
}
