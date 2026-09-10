<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class InvoiceTimeline extends Model
{
    protected $table = 'invoice_timeline';

    protected $fillable = [
        'invoice_id',
        'event',
        'old_status',
        'new_status',
        'actor_type',
        'actor_id',
        'metadata',
        'ip_address',
    ];

    protected $casts = [
        'metadata' => 'array',
    ];

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    public function actor(): \Illuminate\Database\Eloquent\MorphTo
    {
        return $this->morphTo();
    }
}
