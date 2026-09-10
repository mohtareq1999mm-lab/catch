<?php

namespace Marvel\Database\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Marvel\Enums\PaymentStatus;

class Order extends Model
{
    use SoftDeletes;

    public const ORDER_STATUS_PENDING = 'pending';
    public const ORDER_STATUS_PROCESSING = 'processing';
    public const ORDER_STATUS_COMPLETED = 'completed';
    public const ORDER_STATUS_CANCELLED = 'cancelled';
    public const ORDER_STATUS_DELIVERED = 'delivered';

    /**
     * Order-owned inventory reservation states.
     * Valid transitions: none->active, active->committed, active->released, committed->restored.
     */
    public const INVENTORY_STATE_NONE = 'none';
    public const INVENTORY_STATE_ACTIVE = 'active';
    public const INVENTORY_STATE_RELEASED = 'released';
    public const INVENTORY_STATE_COMMITTED = 'committed';
    public const INVENTORY_STATE_RESTORED = 'restored';

    public const PAYMENT_STATUS_PENDING = 'payment-pending';
    public const PAYMENT_STATUS_SUCCESS = 'payment-success';
    public const PAYMENT_STATUS_FAILED = 'payment-failed';
    public const PAYMENT_STATUS_REFUNDED = 'payment-refunded';

    public const FULFILLMENT_STATUS_PENDING = 'pending';
    public const FULFILLMENT_STATUS_PROCESSING = 'processing';
    public const FULFILLMENT_STATUS_READY_FOR_PICKUP = 'ready_for_pickup';
    public const FULFILLMENT_STATUS_OUT_FOR_DELIVERY = 'out_for_delivery';
    public const FULFILLMENT_STATUS_DELIVERED = 'delivered';
    public const FULFILLMENT_STATUS_CANCELLED = 'cancelled';

    protected $table = 'orders';

    public $fillable = [
        'order_number',
        'user_id',
        'governorate_id',
        'name',
        'user_phone',
        'user_email',
        'address',
        'notes',
        'shipping_method',
        'expected_delivery_at',
        'fast_shipping_fee',
        'fulfillment_type',
        'payment_method',
        'payment_gateway',
        'pickup_location_id',
        'parent_id',
        'pickup_location_name',
        'pickup_location_address',
        'pickup_location_phone',
        'pickup_location_coordinates',
        'price',
        'shipping_price',
        'total_price',
        'currency_code',
        'base_currency_code',
        'catalog_currency_code',
        'currency_rate',
        'currency_rate_date',
        'converted_total_price',
        'coupon',
        'coupon_discount',
        'coupon_discount_type',
        'coupon_discount_max_amount',
        'promotion_id',
        'promotion_code',
        'promotion_type',
        'promotion_discount',
        'product_taxable_amount',
        'product_tax_amount',
        'order_tax_rate',
        'order_taxable_amount',
        'order_tax_amount',
        'status',
        'payment_status',
        'fulfillment_status',
        'inventory_state',
        'inventory_reserved_at',
        'reservation_expires_at',
        'inventory_state_restored_at',
        'coupon_consumed',
        'promotion_consumed',
        'paid_at',
        'completed_at',
        'cancelled_at',
    ];

    protected $casts = [
        'address' => 'array',
        'expected_delivery_at' => 'datetime',
        'price' => 'float',
        'shipping_price' => 'float',
        'total_price' => 'float',
        'converted_total_price' => 'float',
        'currency_rate' => 'string',
        'currency_rate_date' => 'date',
        'fast_shipping_fee' => 'float',
        'coupon_consumed' => 'boolean',
        'promotion_consumed' => 'boolean',
        'paid_at' => 'datetime',
        'completed_at' => 'datetime',
        'cancelled_at' => 'datetime',
        'inventory_reserved_at' => 'datetime',
        'reservation_expires_at' => 'datetime',
        'inventory_state_restored_at' => 'datetime',
        'product_taxable_amount' => 'float',
        'product_tax_amount' => 'float',
        'order_tax_rate' => 'float',
        'order_taxable_amount' => 'float',
        'order_tax_amount' => 'float',
    ];

    protected $hidden = [
        'deleted_at'
    ];

    protected static function boot()
    {
        parent::boot();
        static::addGlobalScope('order', function (Builder $builder) {
            $builder->orderBy('created_at', 'desc');
        });

        static::created(function (self $order) {
            if (empty($order->order_number)) {
                $order->order_number = 'ORD-' . str_pad((string) $order->id, 8, '0', STR_PAD_LEFT);
                $order->saveQuietly();
            }
        });
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function governorate(): BelongsTo
    {
        return $this->belongsTo(Governorate::class);
    }

    public function orderItems(): HasMany
    {
        return $this->hasMany(OrderProduct::class);
    }

    /**
     * Digital download entitlements granted for this order's DIGITAL lines.
     */
    public function digitalEntitlements(): HasMany
    {
        return $this->hasMany(\App\Models\DigitalEntitlement::class, 'order_id');
    }

    public function transactions(): HasMany
    {
        return $this->hasMany(Transaction::class);
    }

    public function pickupLocation(): BelongsTo
    {
        return $this->belongsTo(PickupLocation::class, 'pickup_location_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(Order::class, 'parent_id');
    }

    public function latestInvoice(): HasOne
    {
        return $this->hasOne(\App\Models\Invoice::class, 'order_id')->latestOfMany();
    }

    public function invoices(): HasMany
    {
        return $this->hasMany(\App\Models\Invoice::class, 'order_id');
    }

    public function statusHistory(): HasMany
    {
        return $this->hasMany(\App\Models\OrderStatusHistory::class, 'order_id')->orderBy('changed_at', 'desc');
    }

    /**
     * Record a status change in the immutable order_status_history table.
     * Safe to call inside or outside a transaction; ShouldDispatchAfterCommit
     * events remain unaffected.
     */
    public function recordStatusChange(
        ?string $oldStatus,
        string $newStatus,
        ?int $changedBy = null,
        string $changedByType = 'user',
        ?string $notes = null,
        ?array $metadata = null,
        ?string $oldPaymentStatus = null,
        ?string $newPaymentStatus = null,
        ?string $oldFulfillmentStatus = null,
        ?string $newFulfillmentStatus = null
    ): \App\Models\OrderStatusHistory {
        return \App\Models\OrderStatusHistory::create([
            'order_id' => $this->getKey(),
            'old_status' => $oldStatus,
            'new_status' => $newStatus,
            'old_payment_status' => $oldPaymentStatus ?? $this->getOriginal('payment_status'),
            'new_payment_status' => $newPaymentStatus ?? $this->payment_status,
            'old_fulfillment_status' => $oldFulfillmentStatus ?? $this->getOriginal('fulfillment_status'),
            'new_fulfillment_status' => $newFulfillmentStatus ?? $this->fulfillment_status,
            'changed_by' => $changedBy ?? (auth()->check() ? auth()->id() : null),
            'changed_by_type' => $changedByType,
            'notes' => $notes,
            'metadata' => $metadata,
            'changed_at' => now(),
        ]);
    }

    public function scopeForUser(Builder $query, int $userId): Builder
    {
        return $query->where('user_id', $userId);
    }

    public function scopeScheduled(Builder $query): Builder
    {
        return $query->where('shipping_method', 'SCHEDULED');
    }

    public function scopeFast(Builder $query): Builder
    {
        return $query->where('shipping_method', 'FAST');
    }

    public function scopeDelivery(Builder $query): Builder
    {
        return $query->where('fulfillment_type', 'delivery');
    }

    public function scopePickup(Builder $query): Builder
    {
        return $query->where('fulfillment_type', 'pickup');
    }

    public function getOrderNumberAttribute(): string
    {
        if (array_key_exists('order_number', $this->attributes) && $this->attributes['order_number'] !== null) {
            return $this->attributes['order_number'];
        }

        return 'ORD-' . str_pad((string) $this->id, 8, '0', STR_PAD_LEFT);
    }

    public function getPaymentStatusAttribute(): ?string
    {
        if (array_key_exists('payment_status', $this->attributes) && $this->attributes['payment_status'] !== null) {
            return $this->attributes['payment_status'];
        }

        $latestTransaction = $this->transactions()->latest()->first();
        if ($latestTransaction) {
            return match ($latestTransaction->status) {
                'paid' => PaymentStatus::SUCCESS,
                'failed' => PaymentStatus::FAILED,
                default => PaymentStatus::PENDING,
            };
        }

        return match ($this->status) {
            'completed', 'delivered' => PaymentStatus::SUCCESS,
            'cancelled' => PaymentStatus::FAILED,
            default => PaymentStatus::PENDING,
        };
    }
}
