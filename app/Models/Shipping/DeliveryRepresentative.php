<?php

declare(strict_types=1);

namespace App\Models\Shipping;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

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
        'is_active' => 'bool',
        'metadata' => 'array',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(\Marvel\Database\Models\User::class, 'user_id');
    }

    public function assignments(): HasMany
    {
        return $this->hasMany(ShipmentAssignment::class);
    }
}
