<?php

namespace App\Models\Settlement;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Marvel\Database\Models\User;

/**
 * #135/#156: financial evidence header. Writes are limited to the audited
 * manual-match and close workflows in CodSettlementService.
 *
 * No observers, events, or lifecycle hooks are intentionally registered.
 */
class CodSettlementStatement extends Model
{
    protected $table = 'cod_settlement_statements';

    protected $fillable = [
        'statement_key',
        'file_hash',
        'statement_type',
        'supersedes_statement_id',
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
        'status',
        'acceptance_status',
        'reviewed_by',
        'reviewed_at',
        'review_reason',
        'superseded_at',
        'closed_by',
        'closed_at',
        'close_reason',
    ];

    protected $casts = [
        'period_start' => 'date',
        'period_end' => 'date',
        'decimal_places' => 'integer',
        'expected_minor' => 'integer',
        'received_minor' => 'integer',
        'variance_minor' => 'integer',
        'metadata' => 'array',
        'reviewed_at' => 'datetime',
        'superseded_at' => 'datetime',
        'closed_at' => 'datetime',
    ];

    public function lines(): HasMany
    {
        return $this->hasMany(CodSettlementLine::class, 'statement_id');
    }

    public function closer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'closed_by');
    }

    public function matchAudits(): HasMany
    {
        return $this->hasMany(CodSettlementMatchAudit::class, 'statement_id');
    }
}
