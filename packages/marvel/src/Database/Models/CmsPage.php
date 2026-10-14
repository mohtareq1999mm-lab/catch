<?php

declare(strict_types=1);

namespace Marvel\Database\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class CmsPage extends Model
{
    use SoftDeletes;

    protected $table = 'cms_pages';

    protected $guarded = [];

    protected $fillable = ['path', 'slug', 'title', 'content', 'data', 'meta'];

    protected $casts = [
        'content' => 'array',
        'data' => 'array',
        'meta' => 'array',
    ];

    /**
     * Get page data in Puck format.
     * Falls back to legacy content structure if data is not set.
     *
     * @return array<string, mixed>
     */
    public function getPuckDataAttribute(): array
    {
        if ($this->data) {
            return $this->data;
        }

        // Fallback to legacy format
        return [
            'root' => ['props' => []],
            'content' => $this->content ?? [],
            'zones' => [],
        ];
    }
}

