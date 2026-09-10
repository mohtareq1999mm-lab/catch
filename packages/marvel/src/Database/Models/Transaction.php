<?php

namespace Marvel\Database\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class Transaction extends Model
{
    protected $table = 'transactions';

    public $fillable = [
        'order_id',
        'invoice_id',
        'payment_method',
        'user_id',
        'uuid',
        'status',
        'amount',
        'currency',
        'gateway_transaction_id',
        'gateway_response',
        'error_message',
        'qr_code_url',
        'paid_at',
    ];

    protected $casts = [
        'gateway_response' => 'array',
        'paid_at' => 'datetime',
        'amount' => 'float',
    ];

    protected static function boot()
    {
        parent::boot();

        static::creating(function (Transaction $transaction) {
            if (!$transaction->uuid) {
                $transaction->uuid = (string) Str::uuid();
            }
        });
    }

    public function order()
    {
        return $this->belongsTo(Order::class);
    }

    public function scopePending(Builder $query): Builder
    {
        return $query->where('status', 'pending');
    }

    public function scopePaid(Builder $query): Builder
    {
        return $query->where('status', 'paid');
    }

    public function scopeFailed(Builder $query): Builder
    {
        return $query->where('status', 'failed');
    }
}
