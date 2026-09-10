<?php

namespace Marvel\Database\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Wishlist extends Model
{
    protected $table = 'wishlists';

    public $fillable = [
        'user_id',
        'product_id',
        'product_variant_id'
    ];

    protected $data_array = ['product_id', 'product_variant_id', 'user_id'];

    /**
     * Get the product that owns the wishlist.
     */
    public function product()
    {
        return $this->belongsTo(Product::class);
    }

    function variation()
    {
        return $this->belongsTo(ProductVariant::class);
    }

    /**
     * Get the user that owns the comment.
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}