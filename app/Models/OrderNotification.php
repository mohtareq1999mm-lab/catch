<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Marvel\Database\Models\Order;
use Marvel\Database\Models\User;

class OrderNotification extends Model
{
    use HasFactory;

    protected $fillable = [
        'order_id',
        'user_id',
        'event_type',
        'channel',
        'status',
        'subject',
        'message',
        'sent_at',
        'delivered_at',
        'failed_at',
        'failure_reason',
        'provider',
        'provider_message_id',
        'provider_response',
        'metadata',
    ];

    protected $casts = [
        'sent_at' => 'datetime',
        'delivered_at' => 'datetime',
        'failed_at' => 'datetime',
        'provider_response' => 'array',
        'metadata' => 'array',
    ];

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function markAsSent(string $providerId = null, array $providerResponse = null): void
    {
        $this->update([
            'status' => 'sent',
            'sent_at' => now(),
            'provider_message_id' => $providerId,
            'provider_response' => $providerResponse,
        ]);
    }

    public function markAsDelivered(): void
    {
        $this->update([
            'status' => 'delivered',
            'delivered_at' => now(),
        ]);
    }

    public function markAsFailed(string $reason, array $providerResponse = null): void
    {
        $this->update([
            'status' => 'failed',
            'failed_at' => now(),
            'failure_reason' => $reason,
            'provider_response' => $providerResponse,
        ]);
    }

    public function markAsSkipped(string $reason): void
    {
        $this->update([
            'status' => 'skipped',
            'failure_reason' => $reason,
        ]);
    }
}
