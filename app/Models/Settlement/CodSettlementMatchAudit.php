<?php

declare(strict_types=1);

namespace App\Models\Settlement;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Marvel\Database\Models\User;

/**
 * #156: append-only financial evidence. Statements and lines cannot be
 * deleted while an audit references them; actor deletion nulls the FK but
 * the actor name snapshot remains.
 */
class CodSettlementMatchAudit extends Model
{
    protected $table = 'cod_settlement_match_audits';

    protected $fillable = [
        'statement_id', 'line_id', 'actor_id', 'actor_name', 'statement_key', 'line_key',
        'order_id', 'previous_order_id', 'previous_match_status', 'previous_match_method',
        'new_match_status', 'match_method', 'reason', 'metadata',
        'awb', 'provider_shipment_id', 'order_number', 'provider_code', 'mode',
        'account_code', 'period_start', 'period_end',
    ];

    protected $casts = ['metadata' => 'array'];

    public function statement(): BelongsTo { return $this->belongsTo(CodSettlementStatement::class, 'statement_id'); }
    public function line(): BelongsTo { return $this->belongsTo(CodSettlementLine::class, 'line_id'); }
    public function actor(): BelongsTo { return $this->belongsTo(User::class, 'actor_id'); }
}
