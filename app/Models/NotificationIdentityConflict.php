<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class NotificationIdentityConflict extends Model
{
    protected $table = 'notification_identity_conflicts';

    protected $fillable = [
        'channel',
        'key_version',
        'fingerprint_hash',
        'candidate_recipient_ids',
        'status',
        'resolved_by',
        'resolved_at',
        'resolution_note',
        'metadata',
    ];

    protected $casts = [
        'candidate_recipient_ids' => 'array',
        'metadata' => 'array',
        'resolved_at' => 'datetime',
    ];

    public function resolver(): BelongsTo
    {
        return $this->belongsTo(\Marvel\Database\Models\User::class, 'resolved_by');
    }
}
