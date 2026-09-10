<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Marvel\Database\Models\User;

class UserNotificationPreference extends Model
{
    use HasFactory;

    protected $table = 'user_notification_preferences';

    protected $fillable = [
        'user_id',
        'email_enabled',
        'sms_enabled',
        'push_enabled',
        'websocket_enabled',
        'event_preferences',
        'email_verified',
        'phone_verified',
        'email_verified_at',
        'phone_verified_at',
        'quiet_hours_start',
        'quiet_hours_end',
        'respect_quiet_hours',
        'notification_language',
    ];

    protected $casts = [
        'email_enabled' => 'boolean',
        'sms_enabled' => 'boolean',
        'push_enabled' => 'boolean',
        'websocket_enabled' => 'boolean',
        'email_verified' => 'boolean',
        'phone_verified' => 'boolean',
        'respect_quiet_hours' => 'boolean',
        'event_preferences' => 'array',
        'email_verified_at' => 'datetime',
        'phone_verified_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function isChannelEnabled(string $channel): bool
    {
        return match ($channel) {
            'email' => $this->email_enabled && $this->email_verified,
            'sms' => $this->sms_enabled && $this->phone_verified,
            'push' => $this->push_enabled,
            'websocket' => $this->websocket_enabled,
            default => false,
        };
    }

    public function isEventEnabled(string $eventType, string $channel): bool
    {
        if (!$this->isChannelEnabled($channel)) {
            return false;
        }

        $eventPrefs = $this->event_preferences ?? [];

        if (isset($eventPrefs[$eventType][$channel])) {
            return (bool) $eventPrefs[$eventType][$channel];
        }

        return true;
    }

    public function isInQuietHours(\DateTimeInterface $time = null): bool
    {
        if (!$this->respect_quiet_hours || !$this->quiet_hours_start || !$this->quiet_hours_end) {
            return false;
        }

        $time = $time ?? now();
        $currentTime = $time->format('H:i:s');

        $start = $this->quiet_hours_start instanceof \DateTimeInterface
            ? $this->quiet_hours_start->format('H:i:s')
            : (string) $this->quiet_hours_start;
        $end = $this->quiet_hours_end instanceof \DateTimeInterface
            ? $this->quiet_hours_end->format('H:i:s')
            : (string) $this->quiet_hours_end;

        if ($start > $end) {
            return $currentTime >= $start || $currentTime < $end;
        }

        return $currentTime >= $start && $currentTime < $end;
    }

    public static function forUser(int $userId): self
    {
        return static::firstOrCreate(
            ['user_id' => $userId],
            [
                'email_enabled' => true,
                'sms_enabled' => true,
                'push_enabled' => true,
                'websocket_enabled' => true,
                'notification_language' => 'en',
                'email_verified' => false,
                'phone_verified' => false,
            ]
        );
    }
}
