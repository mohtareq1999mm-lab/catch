<?php

namespace Marvel\Database\Models;

use Illuminate\Database\Eloquent\Model;
use Cviebrock\EloquentSluggable\Sluggable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Spatie\Translatable\HasTranslations;

class Attribute extends Model
{
    use Sluggable, HasTranslations;
    public array $translatable = ['name'];
    protected $table = 'attributes';


    public $fillable = ['name', 'slug'];

    /**
     * Return the sluggable configuration array for this model.
     *
     * @return array
     */
    public function sluggable(): array
    {
        $name = $this->name['en'] ?? $this->name;
        return [
            'slug' => [
                'source' => $name
            ]
        ];
    }

    // public function scopeWithUniqueSlugConstraints(Builder $query, Model $model): Builder
    // {
    //     return $query->where('language', $model->language);
    // }


    /**
     * @return HasMany
     */
    public function values(): HasMany
    {
        return $this->hasMany(AttributeValue::class, 'attribute_id');
    }

    /**
     * @return BelongsTo
     */
    // public function shop(): BelongsTo
    // {
    //     return $this->belongsTo(Shop::class, 'shop_id');
    // }
}
