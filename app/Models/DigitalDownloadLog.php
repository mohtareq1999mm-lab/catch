<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DigitalDownloadLog extends Model
{
    protected $table = 'digital_download_logs';

    public $timestamps = false;

    protected $fillable = [
        'entitlement_id',
        'asset_id',
        'ip_hash',
        'ua_hash',
        'downloaded_at',
    ];

    protected $casts = [
        'downloaded_at' => 'datetime',
    ];

    public function entitlement(): BelongsTo
    {
        return $this->belongsTo(DigitalEntitlement::class);
    }

    public function asset(): BelongsTo
    {
        return $this->belongsTo(DigitalAsset::class);
    }
}
