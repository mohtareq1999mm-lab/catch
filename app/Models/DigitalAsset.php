<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;
use Marvel\Database\Models\Product;

class DigitalAsset extends Model
{
    protected $table = 'digital_assets';

    public const TYPE_FILE = 'FILE';
    public const TYPE_LICENSE = 'LICENSE';
    public const TYPE_ACTIVATION_CODE = 'ACTIVATION_CODE';

    // MVP ships FILE only; the remaining types are reserved extension points.
    // DEPRECATED for authorization decisions: the creatable-type whitelist
    // lives in App\Services\Digital\AssetTypeRegistry::creatableTypes().
    public const ACTIVE_TYPES = [self::TYPE_FILE];

    public const STATUS_ACTIVE = 'active';
    // Reserved future states (target schema): inactive, revoked, expired.

    protected $fillable = [
        'uuid',
        'product_id',
        'type',
        'disk',
        'path',
        'original_name',
        'display_name',
        'mime',
        'extension',
        'size',
        'checksum',
        'status',
        'metadata',
        'external_url',
        'secret',
        'sort_order',
        'expires_at',
    ];

    /**
     * Storage location and license secrets never leave the model through
     * serialization; resources must re-declare anything they expose.
     */
    protected $hidden = [
        'path',
        'disk',
        'secret',
    ];

    protected $casts = [
        'metadata' => 'array',
        'expires_at' => 'datetime',
        'secret' => 'encrypted',
    ];

    protected static function boot(): void
    {
        parent::boot();

        static::creating(function (self $asset) {
            if (empty($asset->uuid)) {
                $asset->uuid = (string) Str::uuid();
            }

            if (empty($asset->type)) {
                $asset->type = self::TYPE_FILE;
            }
        });
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }
}
