<?php

namespace App\Models\OrderFlow;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Spatie\Translatable\HasTranslations;

class OrderStatus extends Model
{
    use HasTranslations;

    protected $table = 'order_statuses';

    protected $fillable = [
        'code',
        'name',
        'description',
        'is_active',
    ];

    public array $translatable = ['name'];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    public function flows(): BelongsToMany
    {
        return $this->belongsToMany(OrderFlow::class, 'order_flow_statuses', 'status_id', 'flow_id')
            ->withPivot('sort_order')
            ->withTimestamps();
    }
}
