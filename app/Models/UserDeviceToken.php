<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Marvel\Database\Models\User;

class UserDeviceToken extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'token',
        'platform',
        'device_name',
        'device_id',
        'is_active',
        'last_used_at',
        'expires_at',
        'app_version',
        'os_version',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'last_used_at' => 'datetime',
        'expires_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function touchLastUsed(): bool
    {
        $this->last_used_at = now();
        return $this->save();
    }

    public function deactivate(): void
    {
        $this->update(['is_active' => false]);
    }

    public static function getActiveForUser(int $userId): \Illuminate\Database\Eloquent\Collection
    {
        return static::where('user_id', $userId)
            ->where('is_active', true)
            ->where(function ($query) {
                $query->whereNull('expires_at')
                    ->orWhere('expires_at', '>', now());
            })
            ->get();
    }
}
