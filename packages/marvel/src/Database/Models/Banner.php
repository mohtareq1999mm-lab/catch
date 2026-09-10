<?php

namespace Marvel\Database\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\InteractsWithMedia;
use Spatie\Translatable\HasTranslations;
use Spatie\EloquentSortable\Sortable;
use Spatie\EloquentSortable\SortableTrait;
use Illuminate\Support\Str;

use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Banner extends Model implements HasMedia, Sortable
{
    use InteractsWithMedia, HasTranslations, SortableTrait, SoftDeletes;
    protected $table = 'banners';
    public $sortable = [
        'order_column_name' => 'order',
        'sort_when_creating' => true,
    ];

    public $fillable = [
        'title',
        'slug',
        'description',
        'status',
        'order',
    ];
    public $translatable = ['title', 'description'];


    protected static function booted()
    {
        static::saving(function ($banner) {
            $enTitle = $banner->getTranslation('title', 'en', false);
            $banner->slug = $enTitle ? Str::slug($enTitle) : null;
        });
    }

    public function products(): BelongsToMany
    {
        return $this->belongsToMany(Product::class, 'banner_product', 'banner_id', 'product_id');
    }

    public function scopeActive($query)
    {
        return $query->where('status', true);
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
}
