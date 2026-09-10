<?php

namespace Marvel\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Marvel\Database\Models\SectionType;
use Spatie\EloquentSortable\Sortable;
use Spatie\EloquentSortable\SortableTrait;
use Spatie\Translatable\HasTranslations;

class Section extends Model implements Sortable
{
    use SortableTrait, HasTranslations;

    protected $table = 'sections';
    public array $translatable = ['title'];
    public $sortable = [
        'order_column_name' => 'order',
        'sort_when_creating' => true,
    ];

    protected $fillable = [
        'type',
        'title',
        'order',
        'endpoint',
        'is_active',
        'content_page_id',
        'title_visible',
        'setting'
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'order' => 'integer',
        'title_visible' => 'boolean',
        'setting' => 'array',
    ];
    public function contentPage()
    {
        return $this->belongsTo(ContentPage::class);
    }

    public function sectionType(): BelongsTo
    {
        return $this->belongsTo(SectionType::class, 'type', 'type');
    }
}
