<?php

namespace Marvel\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Spatie\Translatable\HasTranslations;

class ContentPage extends Model
{
    use HasFactory, HasTranslations;
    public array $translatable = ['title'];

    protected $fillable = [
        'title',
        'slug',
        'is_active',
    ];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    public function sections()
    {
        return $this->hasMany(Section::class)->orderBy('order');
    }

    /**
     * Attach existing sections to this content page by their IDs.
     * This uses the Eloquent relationship to persist the foreign key.
     *
     * @param array $sectionIds
     * @return \Illuminate\Database\Eloquent\Collection
     */
    public function attachSectionsByIds(array $sectionIds)
    {
        $sections = Section::whereIn('id', $sectionIds)->get();
        $sections->each(function ($section) {
            $this->sections()->save($section);
        });

        return $this->sections()->whereIn('id', $sectionIds)->get();
    }
}