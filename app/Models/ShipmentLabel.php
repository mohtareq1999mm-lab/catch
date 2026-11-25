<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class ShipmentLabel extends Model
{
    protected $table = 'shipment_labels';

    protected $fillable = [
        'uuid',
        'shipment_id',
        'provider_code',
        'provider_mode',
        'format',
        'mime_type',
        'size_bytes',
        'path',
        'sha256',
        'metadata',
    ];

    protected $casts = [
        'metadata' => 'array',
        'size_bytes' => 'integer',
    ];

    protected static function boot(): void
    {
        parent::boot();

        static::creating(function (self $label): void {
            if (empty($label->uuid)) {
                $label->uuid = (string) Str::orderedUuid();
            }
        });
    }

    public function shipment(): BelongsTo
    {
        return $this->belongsTo(Shipment::class);
    }
}
