<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Marvel\Database\Models\Product;

/**
 * Piece/carton barcode attributes for a product (one row per product).
 *
 * Identification-only model: no stock, pricing, or conversion factors live
 * here. All three attributes are independently nullable — NULL means
 * "unknown / not provided" and is never interpreted as 0 or 1.
 */
class ProductBarcode extends Model
{
    protected $fillable = [
        'product_id',
        'piece_barcode',
        'carton_barcode',
        'pieces_per_carton',
    ];

    protected $casts = [
        'piece_barcode' => 'string',
        'carton_barcode' => 'string',
        'pieces_per_carton' => 'integer',
    ];

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }
}
