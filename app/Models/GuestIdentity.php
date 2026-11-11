<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Marvel\Database\Models\Cart;
use Marvel\Database\Models\Order;

/**
 * Server-managed guest identity.
 *
 * The raw browser token is NEVER persisted: only its keyed SHA-256 hash lives
 * here. Resolution always happens from the verified HttpOnly cookie, never
 * from a client-supplied identifier.
 */
class GuestIdentity extends Model
{
    protected $table = 'guest_identities';

    protected $fillable = [
        'token_hash',
        'expires_at',
        'revoked_at',
    ];

    protected $casts = [
        'expires_at' => 'datetime',
        'revoked_at' => 'datetime',
    ];

    protected $hidden = [
        'token_hash',
    ];

    public function carts(): HasMany
    {
        return $this->hasMany(Cart::class, 'guest_id');
    }

    public function cart(): HasOne
    {
        return $this->hasOne(Cart::class, 'guest_id');
    }

    public function orders(): HasMany
    {
        return $this->hasMany(Order::class, 'guest_id');
    }

    public function isUsable(): bool
    {
        return $this->revoked_at === null
            && $this->expires_at !== null
            && $this->expires_at->isFuture();
    }
}
