<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class DebitNote extends Model
{
    protected $table = 'debit_notes';

    protected $fillable = [
        'uuid',
        'invoice_id',
        'debit_note_number',
        'debit_note_series',
        'sequence_number',
        'sequence_year',
        'reason',
        'type',
        'amount',
        'currency',
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
