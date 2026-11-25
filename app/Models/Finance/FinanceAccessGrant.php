<?php

declare(strict_types=1);

namespace App\Models\Finance;

use Illuminate\Database\Eloquent\Model;

/**
 * #191: durable record of an explicit, temporary finance permission grant.
 */
class FinanceAccessGrant extends Model
{
    protected $table = 'finance_access_grants';

    protected $fillable = [
        'user_id',
        'permission',
        'reason',
        'granted_by',
        'expires_at',
        'revoked_at',
    ];

    protected $casts = [
        'expires_at' => 'datetime',
        'revoked_at' => 'datetime',
    ];

    public function isActive(): bool
    {
        return $this->revoked_at === null && $this->expires_at !== null && $this->expires_at->isFuture();
    }
}
