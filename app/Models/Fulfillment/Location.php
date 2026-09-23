<?php

namespace App\Models\Fulfillment;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Location extends Model
{
    use HasFactory;

    // Phase 7: location types. Placeable types hold pickable placement;
    // quarantine/damaged/returns are non-sellable placement only and NEVER
    // affect central availability (locked §14 semantics).
    public const TYPE_RECEIVING = 'receiving';
    public const TYPE_STORAGE = 'storage';
    public const TYPE_PICKING = 'picking';
    public const TYPE_PACKING = 'packing';
    public const TYPE_STAGING = 'staging';
    public const TYPE_QUARANTINE = 'quarantine';
    public const TYPE_DAMAGED = 'damaged';
    public const TYPE_RETURNS = 'returns';

    public const PLACEABLE_TYPES = [
        self::TYPE_RECEIVING,
        self::TYPE_STORAGE,
        self::TYPE_PICKING,
        self::TYPE_PACKING,
        self::TYPE_STAGING,
    ];

    public const STATUS_ACTIVE = 'active';
    public const STATUS_INACTIVE = 'inactive';

    protected $fillable = [
        'warehouse_id',
        'parent_id',
        'code',
        'barcode',
        'name',
        'type',
        'status',
        'priority',
        'metadata',
    ];

    protected $casts = [
        'priority' => 'integer',
        'metadata' => 'array',
    ];

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(Location::class, 'parent_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(Location::class, 'parent_id');
    }

    public function productLocations(): HasMany
    {
        return $this->hasMany(ProductLocation::class);
    }

    public function scopeActive($query)
    {
        return $query->where('status', self::STATUS_ACTIVE);
    }

    /**
     * Phase 7: only active, placeable locations participate in allocation.
     */
    public function scopePlaceable($query)
    {
        return $query->where('status', self::STATUS_ACTIVE)
            ->whereIn('type', self::PLACEABLE_TYPES);
    }

    public function scopeByPriority($query)
    {
        return $query->orderBy('priority', 'desc');
    }
}
