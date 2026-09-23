<?php

namespace App\Models\Fulfillment;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Package extends Model
{
    use HasFactory;

    public const STATUS_OPEN = 'open';
    public const STATUS_SEALED = 'sealed';
    public const STATUS_HANDED_OFF = 'handed_off';
    public const STATUS_VOIDED = 'voided';

    protected $fillable = [
        'fulfillment_id',
        'order_id',
        'packing_task_id',
        'package_number',
        'barcode',
        'status',
        'weight',
        'dimensions',
        'notes',
        'sealed_at',
        'handed_off_at',
    ];

    protected $casts = [
        'dimensions' => 'array',
        'sealed_at' => 'datetime',
        'handed_off_at' => 'datetime',
    ];

    public function fulfillment(): BelongsTo
    {
        return $this->belongsTo(Fulfillment::class);
    }

    public function packingTask(): BelongsTo
    {
        return $this->belongsTo(PackingTask::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(PackageItem::class);
    }

    public function scopeOpen($query)
    {
        return $query->where('status', self::STATUS_OPEN);
    }
}
