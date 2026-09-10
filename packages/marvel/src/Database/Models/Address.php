<?php

namespace Marvel\Database\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Address extends Model
{
    protected $table = 'address';

    public $fillable = [
        'title',
        'default',
        'address',
        'customer_id',
        'location'
    ];

    protected $casts = [
        'address' => 'array',
        'location' => 'array'
    ];

    /**
     * @return BelongsTo
     */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'customer_id');
    }
}
