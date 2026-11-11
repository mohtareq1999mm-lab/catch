<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Marvel\Database\Models\Order;

/**
 * One-time claim credential for a guest order (OTP-gated).
 * Only the keyed hash is stored; the raw token is never persisted.
 */
class GuestOrderClaimToken extends Model
{
    protected $table = 'guest_order_claim_tokens';

    protected $fillable = [
        'guest_id',
        'order_id',
        'user_id',
        'token_hash',
        'expires_at',
        'used_at',
        'revoked_at',
    ];

    protected $casts = [
        'expires_at' => 'datetime',
        'used_at' => 'datetime',
        'revoked_at' => 'datetime',
    ];

    protected $hidden = [
        'token_hash',
    ];

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function guest(): BelongsTo
    {
        return $this->belongsTo(GuestIdentity::class, 'guest_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(\Marvel\Database\Models\User::class);
    }

    public function isUsable(): bool
    {
        return $this->used_at === null
            && $this->revoked_at === null
            && $this->expires_at !== null
            && $this->expires_at->isFuture();
    }
}
