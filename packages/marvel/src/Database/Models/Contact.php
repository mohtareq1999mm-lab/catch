<?php

namespace Marvel\Database\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Contact extends Model
{
    use SoftDeletes;
    protected $table = 'contacts';

    protected $fillable = [
        'name',
        'email',
        'subject',
        'message',
        'is_read',
        'is_replay',
    ];

    public function scopeRead($query)
    {
        return $query->where('is_read', true);
    }

    public function scopeUnread($query)
    {
        return $query->where('is_read', false);
    }

    public function scopeReplay($query)
    {
        return $query->where('is_replay', true);
    }
}
