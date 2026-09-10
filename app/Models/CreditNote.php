<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class CreditNote extends Model
{
    protected $table = 'credit_notes';

    protected $fillable = [
        'uuid',
        'invoice_id',
        'credit_note_number',
        'credit_note_series',
        'sequence_number',
        'sequence_year',
        'reason',
        'type',
        'amount',
        'currency',
        'refund_transaction_id',
        'created_by',
        'line_items',
        'notes',
        'issued_at',
    ];

    protected $casts = [
        'line_items' => 'array',
        'issued_at' => 'datetime',
    ];

    protected static function boot()
    {
        parent::boot();

        static::creating(function (self $note) {
            if (empty($note->uuid)) {
                $note->uuid = (string) Str::orderedUuid();
            }
        });
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
