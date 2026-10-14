<?php

namespace Marvel\Database\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class PickupLocation extends Model
{
    use SoftDeletes;

    protected $table = 'pickup_locations';

    protected $fillable = [
        'store_name',
        'address',
        'phone',
        'email',
        'latitude',
        'longitude',
        'working_hours',
        'status',
        'display_order',
        'is_default',
    ];

    protected $casts = [
        'working_hours' => 'array',
        'status' => 'boolean',
        'is_default' => 'boolean',
    ];

    protected static function booted(): void
    {
        static::saving(function (PickupLocation $location) {
            if ($location->is_default && $location->isDirty('is_default')) {
                static::withTrashed()
                    ->where('is_default', true)
                    ->whereKeyNot($location->getKey() ?? 0)
                    ->update(['is_default' => false]);
            }
        });

        static::deleted(function (PickupLocation $location) {
            if ($location->is_default) {
                $replacement = static::query()
                    ->whereKeyNot($location->getKey())
                    ->orderBy('id')
                    ->first();

                if ($replacement) {
                    $replacement->update(['is_default' => true]);
                }
            }
        });
    }

    public function scopeDefault($query)
    {
        return $query->where('is_default', true);
    }

    public function scopeActive($query)
    {
        return $query->where('status', true);
    }

    public function scopeInactive($query)
    {
        return $query->where('status', false);
    }

    public function scopeOrdered($query)
    {
        return $query->orderBy('display_order')->orderBy('id');
    }

    public function orders(): HasMany
    {
        return $this->hasMany(Order::class, 'pickup_location_id');
    }
}
