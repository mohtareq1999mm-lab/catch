<?php

namespace Marvel\Database\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Marvel\Enums\DiscountType;
class Discount extends Model
{


    protected $table = "discounts";

    protected $guarded = [];

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function getPriceAfterDiscount(Product $product): float
    {
        $price = (float) $product->price;
        $discount = (float) $this->discount;

        if ($this->discount_type == DiscountType::FIXED_RATE) {
            $finalPrice = $price - $discount;
        } elseif ($this->discount_type == DiscountType::PERCENTAGE) {
            $finalPrice = $price - ($price * ($discount / 100));
        } else {
            $finalPrice = $price;
        }

        $finalPrice = round(max(0, $finalPrice), 2);
        $this->price_after_discount = $finalPrice;
        $this->save();

        return $finalPrice;
    }
}
