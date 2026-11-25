<?php

declare(strict_types=1);

namespace App\Models\Shipping;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Marvel\Database\Models\User;

class DeliveryRepresentative extends Model
{
    protected $table = 'delivery_representatives';

    protected $fillable = [
        'user_id',
        'name',
        'phone',
        'email',
        'is_active',
        'metadata',
    ];

    protected $casts = [
        'user_id' => 'integer',
        'is_active' => 'bool',
        'metadata' => 'array',
    ];

    protected static function booted(): void
    {
        static::saving(function (self $representative): void {
            if (empty($representative->user_id)) {
                throw new \InvalidArgumentException('Delivery representative requires a linked user.');
            }
        });
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function assignments(): HasMany
    {
        return $this->hasMany(ShipmentAssignment::class);
    }
}
