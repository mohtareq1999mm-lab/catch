<?php

declare(strict_types=1);

namespace App\Models\Shipping;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class NotificationRecipient extends Model
{
    protected $table = 'notification_recipients';

    protected $fillable = [
        'recipient_type',
        'recipient_key',
        'contact_encrypted',
        'metadata',
    ];

    protected $casts = [
        'metadata' => 'array',
    ];

    protected $hidden = [
        'contact_encrypted',
    ];

    public function fingerprints(): HasMany
    {
        return $this->hasMany(NotificationRecipientFingerprint::class, 'notification_recipient_id');
    }

    public function shipmentNotifications(): HasMany
    {
        return $this->hasMany(ShipmentNotification::class, 'notification_recipient_id');
    }
}
