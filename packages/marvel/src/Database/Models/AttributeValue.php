<?php

namespace Marvel\Database\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Cviebrock\EloquentSluggable\Sluggable;
use Spatie\Translatable\HasTranslations;

class AttributeValue extends Model
{
    use HasTranslations, Sluggable;
    public array $translatable = ['value'];
    protected $table = 'attribute_values';

    public $fillable = ['value', 'slug', 'attribute_id'];





    /**
     * Return the sluggable configuration array for this model.
     *
     * @return array
     */
    public function sluggable(): array
    {
        return [
            'slug' => [
                'source' => 'slug_source'
            ]
        ];
    }

    public function getSlugSourceAttribute(): string
    {
        return $this->getTranslation('value', 'en', false) ?? $this->value;
    }

    /**
     * @return BelongsTo
     */
    public function attribute(): BelongsTo
    {
        return $this->belongsTo(Attribute::class, 'attribute_id');
    }


    /**
     * @return BelongsToMany
     */
    public function productVariants(): BelongsToMany
    {
        return $this->belongsToMany(ProductVariant::class, 'attribute_product', 'attribute_value_id', 'product_variant_id');
    }
}
