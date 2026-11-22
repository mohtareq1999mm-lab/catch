<?php

namespace App\Models\Settlement;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * #135: per-order financial evidence line. Read-only by convention.
 */
class CodSettlementLine extends Model
{
    protected $table = 'cod_settlement_lines';

    protected $fillable = [
        'statement_id',
        'line_key',
        'order_id',
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
    ];

    protected $casts = [
        'period_start' => 'date',
        'period_end' => 'date',
        'decimal_places' => 'integer',
        'expected_minor' => 'integer',
        'received_minor' => 'integer',
        'variance_minor' => 'integer',
    ];

    public function statement(): BelongsTo
    {
        return $this->belongsTo(CodSettlementStatement::class, 'statement_id');
    }
}
