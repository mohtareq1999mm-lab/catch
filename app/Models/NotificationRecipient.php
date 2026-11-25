<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class NotificationRecipient extends Model
{
    protected $table = 'notification_recipients';

    protected $fillable = [
        'recipient_type',
        'recipient_key',
        'user_id',
        'guest_id',
        'status',
        'contact_encrypted',
        'metadata',
    ];

    protected $hidden = [
        'contact_encrypted',
    ];

    protected $casts = [
        'metadata' => 'array',
    ];

    public function fingerprints(): HasMany
    {
        return $this->hasMany(NotificationRecipientFingerprint::class, 'recipient_id');
    }
}
