<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Str;
use Marvel\Database\Models\Order;
use Marvel\Database\Models\OrderProduct;
use Marvel\Database\Models\User;

class DigitalEntitlement extends Model
{
    protected $table = 'digital_entitlements';

    public const STATUS_PENDING = 'pending';
    public const STATUS_DELIVERED = 'delivered';
    public const STATUS_REVOKED = 'revoked';

    protected $fillable = [
        'uuid',
        'order_id',
        'order_product_id',
        'user_id',
        'status',
        'delivered_at',
        'download_limit',
        'download_count',
        'revoked_at',
        'expires_at',
    ];

    protected $casts = [
        'delivered_at' => 'datetime',
        'revoked_at' => 'datetime',
        'expires_at' => 'datetime',
    ];

    protected static function boot(): void
    {
        parent::boot();

        static::creating(function (self $entitlement) {
            if (empty($entitlement->uuid)) {
                $entitlement->uuid = (string) Str::uuid();
            }

            if (empty($entitlement->download_limit)) {
                $entitlement->download_limit = max(1, (int) config('digital.download_limit', 5));
            }
        });
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function orderItem(): BelongsTo
    {
        return $this->belongsTo(OrderProduct::class, 'order_product_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Assets available through this entitlement — every asset of the
     * purchased product. Product-level association keeps new files
     * automatically available to existing entitlements.
     */
    /**
     * Fulfillment-time snapshot of granted assets (audit record).
     * BD1 Option B — live access is product-scoped: see currentAssets().
     */
    public function assets(): BelongsToMany
    {
        return $this->belongsToMany(
            DigitalAsset::class,
            'digital_asset_entitlement',
            'digital_entitlement_id',
            'digital_asset_id'
        );
    }

    /**
     * BD1 Option B — the entitlement is a license to the PRODUCT's digital
     * assets, not to a frozen file list. Assets uploaded after delivery
     * automatically become available; revocation/refund still blocks all.
     */
    public function currentAssets()
    {
        $product = $this->orderItem?->product;

        // Order pipelines eager-load digitalEntitlements.orderItem.product.digitalAssets;
        // reuse the loaded collection to avoid per-entitlement queries.
        if ($product && $product->relationLoaded('digitalAssets')) {
            return $product->digitalAssets
                ->sortBy([['sort_order', 'asc'], ['id', 'asc']])
                ->values();
        }

        return DigitalAsset::query()
            ->where('product_id', $this->orderItem?->product_id)
            ->where('status', \App\Models\DigitalAsset::STATUS_ACTIVE)   // W6: inactive assets leave the customer surface
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get();
    }
}
