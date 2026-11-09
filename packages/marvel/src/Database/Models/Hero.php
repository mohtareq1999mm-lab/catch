<?php

namespace Marvel\Database\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\InteractsWithMedia;
use Spatie\MediaLibrary\MediaCollections\Models\Media;
use Spatie\Translatable\HasTranslations;

class Hero extends Model implements HasMedia
{
    use HasFactory, InteractsWithMedia, HasTranslations;

    public const TYPE_IMAGE = 'image';
    public const TYPE_VIDEO = 'video';

    public const COLLECTION_IMAGE = 'hero-image';
    public const COLLECTION_VIDEO = 'hero-video';

    protected $table = 'heroes';

    public array $translatable = ['description'];

    protected $fillable = [
        'type',
        'description',
        'is_active',
    ];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    public function registerMediaCollections(): void
    {
        $this->addMediaCollection(self::COLLECTION_IMAGE)
            ->useDisk('heroes')
            ->singleFile();

        $this->addMediaCollection(self::COLLECTION_VIDEO)
            ->useDisk('heroes')
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

    public function getMediaCollectionNameForType(?string $type = null): string
    {
        $resolvedType = $type ?? $this->type ?? self::TYPE_IMAGE;

        if ($resolvedType === self::TYPE_VIDEO) {
            return self::COLLECTION_VIDEO;
        }

        return self::COLLECTION_IMAGE;
    }
}

