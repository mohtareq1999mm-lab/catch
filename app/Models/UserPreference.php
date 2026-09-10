<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Marvel\Database\Models\User;

class UserPreference extends Model
{
    protected $table = 'user_preferences';

    public $fillable = [
        'user_id',
        'currency_code',
    ];

    protected $casts = [
        'currency_code' => 'string',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}