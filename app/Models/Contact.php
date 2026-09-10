<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Contact extends Model
{
    use SoftDeletes;

    protected $fillable = ['name', 'email', 'subject', 'message', 'is_read', 'is_replay'];

    protected $casts = [
        'is_read' => 'boolean',
        'is_replay' => 'boolean',
    ];
}
