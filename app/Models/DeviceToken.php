<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class DeviceToken extends Model
{
    protected $fillable = ['uuid', 'user_id', 'token', 'client', 'platform', 'last_used_at'];

    protected $casts = ['last_used_at' => 'datetime'];

    protected static function booted(): void
    {
        static::creating(function (self $device) {
            if (empty($device->uuid)) {
                $device->uuid = (string) Str::orderedUuid();
            }
        });
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}