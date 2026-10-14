<?php

namespace Marvel\Database\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OrderProduct extends Model
{
    protected $table = 'order_products';

public $fillable = [
        'order_id',
        'product_id',
        'product_variant_id',
        'product_name',
        'product_sku',
        'attributes',
        'product_quantity',
        'product_price',
        'product_total_price',
        'product_discount_price',
        'promotion_discount_amount',
        'product_flash_sale_price',
        'is_gift',
        'item_type',
        'promotion_id',
        'currency_code',
        'catalog_currency_code',
        'catalog_price',
        'catalog_total_price',
        'product_tax_rate',
        'product_taxable_amount',
        'product_tax_amount',
    ];

    protected $casts = [
        'attributes' => 'array',
        'is_gift' => 'boolean',
        'promotion_discount_amount' => 'float',
        'product_price' => 'float',
        'product_total_price' => 'float',
        'product_discount_price' => 'float',
        'product_flash_sale_price' => 'float',
        'catalog_price' => 'float',
        'catalog_total_price' => 'float',
        'product_tax_rate' => 'float',
        'product_taxable_amount' => 'float',
        'product_tax_amount' => 'float',
    ];




    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function productVariant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class);
    }

    public function promotion(): BelongsTo
    {
        return $this->belongsTo(Promotion::class);
    }
}
