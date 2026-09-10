<?php

namespace Marvel\Database\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Spatie\Translatable\HasTranslations;
use Spatie\Permission\Models\Role as SpatieRole;

class Role extends SpatieRole
{
    use HasFactory, HasTranslations;

    protected $fillable = [
        'name',
        'display_name',
        'guard_name',
    ];

    public $translatable = ['display_name'];
}
