<?php

namespace Marvel\Database\Models;

use App\Services\General\CategoryHierarchyService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\InteractsWithMedia;
use Spatie\Translatable\HasTranslations;
use Str;

class Category extends Model implements HasMedia
{
    use HasTranslations, InteractsWithMedia, SoftDeletes;


    protected $table = 'categories';
    public array $translatable = ['name', 'details'];

    public $fillable = ['name', 'details', 'slug','is_featured', 'parent_id', 'level', 'status'];

    protected $casts = [
        'parent_id' => 'integer',
        'level' => 'integer',
        'status' => 'boolean',
        'is_featured' => 'boolean',
    ];

    protected static function booted(): void
    {
        static::saving(function (self $category) {
            app(CategoryHierarchyService::class)->syncHierarchy($category);
        });
        static::saving(function ($category) {
            if ($category->isDirty('name') && !$category->isDirty('slug')) {
                $enName = $category->getTranslation('name', 'en', false);
                $category->slug = $enName ? Str::slug($enName) : null;
            }
        });

        static::saved(function (self $category) {
            if ($category->wasChanged('parent_id')) {
                app(CategoryHierarchyService::class)->updateDescendantLevels($category);
            }
        });

        static::retrieved(function ($category) {
            if (is_string($category->slug) && str_starts_with($category->slug, '{')) {
                $decoded = json_decode($category->slug, true);
                if (is_array($decoded) && isset($decoded['en'])) {
                    $category->slug = $decoded['en'];
                }
            }
        });
    }






    public  function scopeActive($query)
    {
        return $query->where('status', 1);
    }
    public  function scopeInactive($query)
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
        return $this->belongsToMany(Product::class, 'category_product');
    }


    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id', 'id');
    }


    /**
     * @return BelongsTo
     */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id', 'id');
    }
}
