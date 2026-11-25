<?php

namespace App\Models\Settlement;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * #135: read-only financial evidence header.
 *
 * No observers, events, or lifecycle hooks are intentionally registered.
 */
class CodSettlementStatement extends Model
{
    protected $table = 'cod_settlement_statements';

    protected $fillable = [
        'statement_key',
        'provider_code',
        'mode',
        'account_code',
        'period_start',
        'period_end',
        'currency_code',
        'decimal_places',
        'expected_minor',
        'received_minor',
        'variance_minor',
        'metadata',
        'acceptance_status',
        'accepted_at',
        'accepted_by',
        'supersedes_statement_id',
    ];

    protected $casts = [
        'period_start' => 'date',
        'period_end' => 'date',
        'decimal_places' => 'integer',
        'expected_minor' => 'integer',
        'received_minor' => 'integer',
        'variance_minor' => 'integer',
        'metadata' => 'array',
        'accepted_at' => 'datetime',
        'acceptance_status' => \App\Enums\Settlement\SettlementAcceptanceStatus::class,
    ];

    public function lines(): HasMany
    {
        return $this->hasMany(CodSettlementLine::class, 'statement_id');
    }

    public function supersededStatement(): BelongsTo
    {
        return $this->belongsTo(self::class, 'supersedes_statement_id');
    }
}
