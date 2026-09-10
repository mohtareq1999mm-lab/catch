<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Marvel\Database\Models\Order;
use Marvel\Database\Models\User;

class OrderStatusHistory extends Model
{
    use HasFactory;

    protected $table = 'order_status_history';

    protected $fillable = [
        'order_id',
        'old_status',
        'new_status',
        'old_payment_status',
        'new_payment_status',
        'old_fulfillment_status',
        'new_fulfillment_status',
        'changed_by',
        'changed_by_type',
        'notes',
        'metadata',
        'changed_at',
    ];

    protected $casts = [
        'metadata' => 'array',
        'changed_at' => 'datetime',
    ];

    /**
     * Make history immutable - prevent updates and deletes
     */
    protected static function boot(): void
    {
        parent::boot();

        static::updating(function () {
            return false; // Prevent updates
        });

        static::deleting(function () {
            return false; // Prevent deletes
        });
    }

    // Relationships
    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function changedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'changed_by');
    }

    // Helper methods
    public function isSystemChange(): bool
    {
        return $this->changed_by_type === 'system';
    }

    public function isUserChange(): bool
    {
        return in_array($this->changed_by_type, ['user', 'admin'], true);
    }

    public function getStatusChangeDescription(): string
    {
        if ($this->old_status === null) {
            return "Order created with status: {$this->new_status}";
        }

        return "Status changed from {$this->old_status} to {$this->new_status}";
    }
}
