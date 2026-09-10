<?php

namespace Marvel\Database\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Cviebrock\EloquentSluggable\Sluggable;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\InteractsWithMedia;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

/**
 * @OA\Schema(
 *     schema="Attachment",
 *     type="object",
 *     title="Attachment",
 *     description="File attachment model with media library integration",
 *     required={"id"},
 *     @OA\Property(
 *         property="id",
 *         type="integer",
 *         description="Unique identifier",
 *         example=1
 *     ),
 *     @OA\Property(
 *         property="original",
 *         type="string",
 *         description="Original file URL",
 *         example="https://example.com/storage/uploads/image.jpg"
 *     ),
 *     @OA\Property(
 *         property="thumbnail",
 *         type="string",
 *         description="Thumbnail URL (368x232 for images, empty for non-image files)",
 *         example="https://example.com/storage/uploads/conversions/image-thumbnail.jpg"
 *     ),
 *     @OA\Property(
 *         property="created_at",
 *         type="string",
 *         format="date-time",
 *         description="Creation timestamp",
 *         example="2026-01-10T10:30:00.000000Z"
 *     ),
 *     @OA\Property(
 *         property="updated_at",
 *         type="string",
 *         format="date-time",
 *         description="Last update timestamp",
 *         example="2026-01-10T10:30:00.000000Z"
 *     )
 * )
 */
class Attachment extends Model implements HasMedia
{
    use InteractsWithMedia;

    protected $table = 'attachments';

    public $guarded = [];

    public function registerMediaConversions(Media $media = null): void
    {
        $this->addMediaConversion('thumbnail')
            ->width(368)
            ->height(232)
            ->nonQueued();
    }
}
