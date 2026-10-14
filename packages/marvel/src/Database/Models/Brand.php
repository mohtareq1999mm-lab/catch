<?php

namespace Marvel\Database\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Spatie\EloquentSortable\Sortable;
use Spatie\EloquentSortable\SortableTrait;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\InteractsWithMedia;
use Spatie\Translatable\HasTranslations;
use Illuminate\Support\Str;


class Brand extends Model implements HasMedia, Sortable
{
    use HasTranslations, InteractsWithMedia, SortableTrait, SoftDeletes;

    protected $table = 'brands';

    public array $translatable = ['name', 'details'];

    public $sortable = [
        'order_column_name' => 'order',
        'sort_when_creating' => true,
    ];

    protected $fillable = ['name', 'details', 'slug', 'status', 'order'];



    public function scopeActive($query)
    {
        return $query->where('status', 1);
    }

    public function scopeInactive($query)
    {
        return $query->where('status', 0);
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

    /**
     * @return BelongsToMany
     */
    public function products(): BelongsToMany
    {
        return $this->belongsToMany(Product::class, 'brand_product', 'brand_id', 'product_id');
    }

    protected static function booted()
    {
        static::saving(function ($brand) {
            if ($brand->isDirty('name') && !$brand->isDirty('slug')) {
                $enName = $brand->getTranslation('name', 'en', false);
                $brand->slug = $enName ? Str::slug($enName) : null;
            }
        });
    }
}