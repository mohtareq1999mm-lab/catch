<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Durable guest idempotency claim (checkout / payment initiation).
 * Scoped to (guest_id, scope, idempotency_key); the unique index is the
 * concurrency arbiter.
 */
class GuestIdempotencyKey extends Model
{
    public const STATUS_PROCESSING = 'processing';
    public const STATUS_COMPLETED = 'completed';
    public const STATUS_FAILED = 'failed';

    /** A stalled claim may be re-taken after this window. */
    public const STALE_AFTER_MINUTES = 10;

    protected $table = 'guest_idempotency_keys';

    protected $fillable = [
        'guest_id',
        'scope',
        'idempotency_key',
        'request_hash',
        'status',
        'order_id',
        'response',
        'claimed_at',
        'completed_at',
        'expires_at',
    ];

    protected $casts = [
        'response' => 'array',
        'claimed_at' => 'datetime',
        'completed_at' => 'datetime',
        'expires_at' => 'datetime',
    ];

    public function isCompleted(): bool
    {
        return $this->status === self::STATUS_COMPLETED;
    }

    public function isStale(): bool
    {
        if ($this->status !== self::STATUS_PROCESSING) {
            return false;
        }

        return $this->claimed_at === null
            || $this->claimed_at->lt(now()->subMinutes(self::STALE_AFTER_MINUTES));
    }
}
