<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class NotificationRecipientFingerprint extends Model
{
    protected $table = 'notification_recipient_fingerprints';

    protected $fillable = [
        'recipient_id',
        'key_version',
        'fingerprint_hash',
        'metadata',
    ];

    protected $casts = [
        'metadata' => 'array',
    ];

    public function recipient(): BelongsTo
    {
        return $this->belongsTo(NotificationRecipient::class, 'recipient_id');
    }
}
