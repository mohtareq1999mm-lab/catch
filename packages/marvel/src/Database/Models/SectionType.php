<?php

namespace Marvel\Database\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SectionType extends Model
{
    protected $table = 'section_types';

    protected $fillable = ['type'];

    public function getRouteKeyName(): string
    {
        return 'type';
    }

    public function settings(): HasMany
    {
        return $this->hasMany(SectionTypeSetting::class, 'section_type_id', 'id');
    }
}
