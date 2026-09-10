<?php

namespace Marvel\Database\Models;

use Illuminate\Database\Eloquent\Model;


class   CartItem extends Model
{


    protected $table = 'cart_items';

    public $fillable = [
        'cart_id',
        'product_id',
        'quantity',
        'product_variant_id',
        'price',
        'total_price',
        'attributes',
        'reserved_quantity',
        'discount_amount',
        'shipping_method',
        'is_gift',
        'promotion_id',
    ];
    protected $casts = [
        'attributes' => 'array',
        'is_gift' => 'boolean',
        'shipping_method' => 'string',
        'price' => 'float',
        'total_price' => 'float',
        'discount_amount' => 'float',
    ];






    public function cart()
    {
        return $this->belongsTo(Cart::class);
    }

    public function product()
    {
        return $this->belongsTo(Product::class)->withTrashed();
    }
    public function productVariant()
    {
        return $this->belongsTo(ProductVariant::class);
    }

    public function promotion()
    {
        return $this->belongsTo(Promotion::class);
    }
}
