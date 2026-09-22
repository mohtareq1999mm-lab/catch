<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class OrderTrackingEvent extends Model
{
    use HasFactory;

    protected $fillable = [
        'order_id',
        'event_type',
        'event_timestamp',
        'actor_type',
        'actor_id',
        'actor_name',
        'old_status',
        'new_status',
        'metadata',
        'customer_visible',
        'customer_label_key',
        'customer_description_key',
        'admin_notes',
        'source',
        'ip_address',
    ];

    protected $casts = [
        'event_timestamp' => 'datetime',
        'metadata' => 'array',
        'customer_visible' => 'boolean',
    ];

    /**
     * Relationship: belongs to Order
     */
    public function order()
    {
        return $this->belongsTo(Order::class);
    }

    /**
     * Scope: customer visible events only
     */
    public function scopeCustomerVisible($query)
    {
        return $query->where('customer_visible', true);
    }

    /**
     * Scope: order by event timestamp descending
     */
    public function scopeLatestFirst($query)
    {
        return $query->orderBy('event_timestamp', 'desc');
    }

    /**
     * Scope: for specific order
     */
    public function scopeForOrder($query, $orderId)
    {
        return $query->where('order_id', $orderId);
    }
}
