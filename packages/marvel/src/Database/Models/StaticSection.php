<?php

namespace Marvel\Database\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Spatie\EloquentSortable\Sortable;
use Spatie\EloquentSortable\SortableTrait;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\InteractsWithMedia;
use Spatie\MediaLibrary\MediaCollections\Models\Media;
use Spatie\Translatable\HasTranslations;

class StaticSection extends Model implements HasMedia, Sortable
{
    use InteractsWithMedia, SortableTrait, HasTranslations;

    public array $translatable = ['title', 'content'];

    public const COLLECTION_IMAGE = 'static-section-image';
    public const COLLECTION_VIDEO = 'static-section-video';

    public $sortable = [
        'order_column_name' => 'order',
        'sort_when_creating' => true,
    ];

    protected $fillable = [
        'static_page_id',
        'type',
        'title',
        'content',
        'config',
        'order',
        'is_active',
    ];

    protected $casts = [
        'order' => 'integer',
        'config' => 'array',
        'is_active' => 'boolean',
    ];

    public function staticPage(): BelongsTo
    {
        return $this->belongsTo(StaticPage::class);
    }

    /**
     * Ordering must be scoped to the owning page so reorder operations never
     * affect sections that belong to a different static page.
     */
    public function buildSortQuery()
    {
        return static::query()->where('static_page_id', $this->static_page_id);
    }

    public function registerMediaCollections(): void
    {
        $this->addMediaCollection(self::COLLECTION_IMAGE)
            ->useDisk('static-pages')
            ->singleFile();

        $this->addMediaCollection(self::COLLECTION_VIDEO)
            ->useDisk('static-pages')
            ->singleFile();
    }

    public function registerMediaConversions(Media $media = null): void
    {
        $this->addMediaConversion('thumb')
            ->width(368)
            ->height(232)
            ->nonQueued()
            ->performOnCollections(self::COLLECTION_IMAGE);
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    public function getMediaCollectionNameForType(?string $type): ?string
    {
        if (in_array($type, ['image', 'screenshot'], true)) {
            return self::COLLECTION_IMAGE;
        }
        if ($type === 'video') {
            return self::COLLECTION_VIDEO;
        }
        return null;
    }
}