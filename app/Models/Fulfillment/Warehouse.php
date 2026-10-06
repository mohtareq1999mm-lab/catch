<?php

namespace App\Models\Fulfillment;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Warehouse extends Model
{
    use HasFactory, SoftDeletes;

    protected static function booted(): void
    {
        // D-WH-DEL (Phase 2): warehouses are never physically deleted and the
        // system must never end with a soft-deleted effective default. This
        // mirrors the deactivate() block in WarehouseService: deleting the
        // current default is rejected — promote another active warehouse
        // first. Non-default warehouses may be soft-deleted; their history
        // survives via the row itself plus fulfillment snapshots.
        static::deleting(function (Warehouse $warehouse) {
            if ($warehouse->isForceDeleting()) {
                return;
            }
            // Authoritative DB state, never the possibly-stale in-memory
            // instance (e.g. default moved via another instance after load).
            $isDefault = (bool) Warehouse::whereKey($warehouse->getKey())->value('is_default');
            if ($isDefault) {
                throw new \RuntimeException(
                    "Cannot delete default warehouse #{$warehouse->getKey()} ({$warehouse->code}): promote another active warehouse first"
                );
            }
        });
    }
    protected $fillable = [
        'code',
        'name',
        'address',
        'city',
        'country',
        'status',
        'is_default',
        'metadata',
    ];

    protected $casts = [
        'is_default' => 'boolean',
        'metadata' => 'array',
    ];

    public function locations(): HasMany
    {
        return $this->hasMany(Location::class);
    }

    public function productLocations(): HasMany
    {
        return $this->hasMany(ProductLocation::class);
    }

    public function scopeActive($query)
    {
        return $query->where('status', 'active');
    }

    public function scopeDefault($query)
    {
        return $query->where('is_default', true);
    }
}
