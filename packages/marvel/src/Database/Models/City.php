<?php

namespace Marvel\Database\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Spatie\Translatable\HasTranslations;

class City extends Model
{
    use HasTranslations;

    protected $table = 'cities';

    protected $fillable = [
        'governorate_id',
        'name',
    ];

    public array $translatable = ['name'];

    protected $casts = [
        'governorate_id' => 'integer',
    ];

    public function governorate(): BelongsTo
    {
        return $this->belongsTo(Governorate::class, 'governorate_id');
    }
}
