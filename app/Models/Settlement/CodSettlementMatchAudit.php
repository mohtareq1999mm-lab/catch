<?php

declare(strict_types=1);

namespace App\Models\Settlement;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Marvel\Database\Models\User;

class CodSettlementMatchAudit extends Model
{
    protected $table = 'cod_settlement_match_audits';

    protected $fillable = [
        'statement_id', 'line_id', 'actor_id', 'order_id',
        'previous_match_status', 'new_match_status', 'match_method', 'reason', 'metadata',
    ];

    protected $casts = ['metadata' => 'array'];

    public function statement(): BelongsTo { return $this->belongsTo(CodSettlementStatement::class, 'statement_id'); }
    public function line(): BelongsTo { return $this->belongsTo(CodSettlementLine::class, 'line_id'); }
    public function actor(): BelongsTo { return $this->belongsTo(User::class, 'actor_id'); }
}
