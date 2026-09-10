<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * License key pool entry for a LICENSE-type digital asset (decision A2).
 *
 * SCHEMA REPRESENTATION ONLY in Workstream 3: allocation/reveal/consume
 * business logic belongs to the future license service workstream. The
 * encrypted_key column is hidden from all serialization by default; keys
 * are only ever persisted through the 'encrypted' cast — never plaintext.
 */
class DigitalLicenseKey extends Model
{
    protected $table = 'digital_license_keys';

    public const STATUS_AVAILABLE = 'available';
    public const STATUS_ASSIGNED = 'assigned';
    public const STATUS_CONSUMED = 'consumed';
    public const STATUS_REVOKED = 'revoked';

    protected $fillable = [
        'uuid',
        'asset_id',
        'encrypted_key',
        'status',
        'allocated_entitlement_id',
        'assigned_at',
        'revealed_at',
        'consumed_at',
        'revoked_at',
    ];

    protected $hidden = [
        'encrypted_key',
    ];

    protected $casts = [
        'assigned_at' => 'datetime',
        'revealed_at' => 'datetime',
        'consumed_at' => 'datetime',
        'revoked_at' => 'datetime',
        'encrypted_key' => 'encrypted',
    ];

    protected static function boot(): void
    {
        parent::boot();

        static::creating(function (self $key) {
            if (empty($key->uuid)) {
                $key->uuid = (string) Str::uuid();
            }

            if (empty($key->status)) {
                $key->status = self::STATUS_AVAILABLE;
            }
        });
    }

    public function asset(): BelongsTo
    {
        return $this->belongsTo(DigitalAsset::class, 'asset_id');
    }

    public function allocatedEntitlement(): BelongsTo
    {
        return $this->belongsTo(DigitalEntitlement::class, 'allocated_entitlement_id');
    }
}
