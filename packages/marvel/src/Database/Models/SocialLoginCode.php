<?php

namespace Marvel\Database\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SocialLoginCode extends Model
{
    protected $table = 'social_login_codes';

    protected $fillable = [
        'user_id',
        'code',
        'expires_at',
        'used',
        'used_at',
    ];

    protected $casts = [
        'expires_at' => 'datetime',
        'used' => 'boolean',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
