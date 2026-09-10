<?php

namespace Marvel\Database\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Spatie\Translatable\HasTranslations;

class StaticPage extends Model
{
    use HasFactory, HasTranslations;

    public array $translatable = ['title'];

    protected $fillable = [
        'slug',
        'title',
        'is_active',
    ];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    public function staticSections()
    {
        return $this->hasMany(StaticSection::class)->orderBy('order');
    }
}