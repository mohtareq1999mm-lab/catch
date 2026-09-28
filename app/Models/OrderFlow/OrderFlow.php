<?php

namespace App\Models\OrderFlow;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Spatie\Translatable\HasTranslations;

class OrderFlow extends Model
{
    use HasTranslations;

    protected $table = 'order_flows';

    protected $fillable = [
        'code',
        'name',
        'shipping_type',
        'is_default',
        'is_active',
    ];

    public array $translatable = ['name'];

    protected $casts = [
        'is_default' => 'boolean',
        'is_active' => 'boolean',
    ];

    public function statuses(): BelongsToMany
    {
        return $this->belongsToMany(OrderStatus::class, 'order_flow_statuses', 'flow_id', 'status_id')
            ->withPivot('sort_order')
            ->withTimestamps()
            ->orderBy('order_flow_statuses.sort_order');
    }

    public function memberships(): HasMany
    {
        return $this->hasMany(OrderFlowStatus::class, 'flow_id')->orderBy('sort_order');
    }

    public function inputs(): HasMany
    {
        return $this->hasMany(FlowInput::class, 'flow_id')->orderBy('sort_order');
    }

    public function orders(): HasMany
    {
        return $this->hasMany(\Marvel\Database\Models\Order::class, 'flow_id');
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }
}
