<?php

namespace App\Models;

use App\Enums\InvoiceStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;
use Marvel\Database\Models\Order;
use Marvel\Database\Models\Transaction;
use Marvel\Database\Models\User;

class Invoice extends Model
{
    protected $table = 'invoices';

    protected $fillable = [
        'uuid',
        'order_id',
        'transaction_id',
        'user_id',
        'correction_to_id',
        'invoice_number',
        'invoice_series',
        'sequence_number',
        'sequence_year',
        'subtotal',
        'shipping_price',
        'coupon_discount',
        'promotion_discount',
        'total_discount',
        'total',
        'amount_paid',
        'currency',
        'payment_method',
        'payment_gateway',
        'status',
        'data',
        'snapshot_hash',
        'verification_hash',
        'pdf_generated_at',
        'pdf_regenerated_at',
        'pdf_path',
        'pdf_checksum',
        'generation_attempts',
        'last_generation_error',
        'is_correction',
        'correction_reason',
        'corrected_at',
        'cancelled_at',
        'cancellation_reason',
        'generated_at',
        'generated_by',
        'verified_at',
        'downloaded_at',
        'printed_at',
        'archived_at',
        'last_verified_at',
        'verify_count',
    ];

    protected $casts = [
        'data' => 'array',
        'is_correction' => 'boolean',
        'generation_attempts' => 'integer',
        'generated_at' => 'datetime',
        'pdf_generated_at' => 'datetime',
        'pdf_regenerated_at' => 'datetime',
        'corrected_at' => 'datetime',
        'cancelled_at' => 'datetime',
        'verified_at' => 'datetime',
        'downloaded_at' => 'datetime',
        'printed_at' => 'datetime',
        'archived_at' => 'datetime',
        'last_verified_at' => 'datetime',
        'verify_count' => 'integer',
    ];

    protected static function boot()
    {
        parent::boot();

        static::creating(function (self $invoice) {
            if (empty($invoice->uuid)) {
                $invoice->uuid = (string) Str::orderedUuid();
            }
        });

        static::saving(function (self $invoice) {
            if ($invoice->exists && $invoice->isDirty('status')) {
                $originalStatus = $invoice->getOriginal('status');
                $newStatus = $invoice->status;

                $from = InvoiceStatus::tryFrom($originalStatus);
                $to = InvoiceStatus::tryFrom($newStatus);

                if ($from && $to && !$from->canTransitionTo($to)) {
                    throw new \RuntimeException(
                        "Invalid invoice status transition: {$originalStatus} → {$newStatus}"
                    );
                }
            }
        });
    }

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function transaction(): BelongsTo
    {
        return $this->belongsTo(Transaction::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function correctionTo(): BelongsTo
    {
        return $this->belongsTo(self::class, 'correction_to_id');
    }

    public function corrections(): HasMany
    {
        return $this->hasMany(self::class, 'correction_to_id');
    }

    public function timeline(): HasMany
    {
        return $this->hasMany(InvoiceTimeline::class);
    }

    public function creditNotes(): HasMany
    {
        return $this->hasMany(CreditNote::class);
    }

    public function debitNotes(): HasMany
    {
        return $this->hasMany(DebitNote::class);
    }

    public function isStatusTransitionAllowed(string $from, string $to): bool
    {
        $fromEnum = InvoiceStatus::tryFrom($from);
        $toEnum = InvoiceStatus::tryFrom($to);

        if (!$fromEnum || !$toEnum) {
            return false;
        }

        return $fromEnum->canTransitionTo($toEnum);
    }
}
