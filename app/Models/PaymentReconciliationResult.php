<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Marvel\Database\Models\Order;
use Marvel\Database\Models\Transaction;

class PaymentReconciliationResult extends Model
{
    protected $table = 'payment_reconciliation_results';

    protected $fillable = [
        'transaction_id',
        'order_id',
        'gateway',
        'mismatch_type',
        'expected_value',
        'actual_value',
        'notes',
        'resolved_at',
    ];

    protected $casts = [
        'resolved_at' => 'datetime',
    ];

    public function transaction(): BelongsTo
    {
        return $this->belongsTo(Transaction::class);
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function scopeUnresolved(Builder $query): Builder
    {
        return $query->whereNull('resolved_at');
    }

    public function scopeResolved(Builder $query): Builder
    {
        return $query->whereNotNull('resolved_at');
    }

    public function scopeByType(Builder $query, string $type): Builder
    {
        return $query->where('mismatch_type', $type);
    }
}
