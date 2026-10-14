<?php

namespace Marvel\Database\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Testing\Fluent\Concerns\Has;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\InteractsWithMedia;
use Spatie\EloquentSortable\Sortable;
use Spatie\EloquentSortable\SortableTrait;
use Spatie\Translatable\HasTranslations;
use Illuminate\Support\Str;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Slider extends Model implements HasMedia, Sortable
{
    use InteractsWithMedia, SortableTrait, SoftDeletes, HasTranslations;
    protected $table = 'sliders';

    public array $translatable = ['title'];
    public $sortable = [
        'order_column_name' => 'order',
        'sort_when_creating' => true,
    ];

    public $fillable = [
        'title',
        'slug',
        'order',
        'status'
    ];

    protected static function booted()
    {
        static::saving(function ($slider) {
            $enTitle = $slider->getTranslation('title', 'en', false);
            $slider->slug = $enTitle ? Str::slug($enTitle) : null;
        });
    }
    public function products(): BelongsToMany
    {
        return $this->belongsToMany(Product::class, 'slider_product');
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
