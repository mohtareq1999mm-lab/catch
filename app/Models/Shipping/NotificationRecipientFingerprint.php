<?php

declare(strict_types=1);

namespace App\Models\Shipping;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class NotificationRecipientFingerprint extends Model
{
    protected $table = 'notification_recipient_fingerprints';

    protected $fillable = [
        'notification_recipient_id',
        'version',
        'fingerprint',
        'metadata',
    ];

    protected $casts = [
        'metadata' => 'array',
    ];

    public function recipient(): BelongsTo
    {
        return $this->belongsTo(NotificationRecipient::class, 'notification_recipient_id');
    }
}
