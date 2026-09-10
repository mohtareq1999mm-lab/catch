<?php

namespace Marvel\Database\Models;

use Marvel\Services\Pricing\ProductPricingService;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Database\Factories\ProductVariantFactory;
use Illuminate\Support\Str;



class ProductVariant extends Model
{
    use HasFactory;

    protected $appends = ['current_price', 'sale_price', 'final_price'];

    protected static function boot()
    {
        parent::boot();

        static::creating(function ($variant) {
            if (empty($variant->sku)) {
                $variant->sku = 'VAR-' . Str::random(6);
            }
        });
    }

    protected static function newFactory()
    {
        return ProductVariantFactory::new();
    }

    protected $table = 'product_variants';
    protected $fillable = ['sku', 'price', 'sale_price', 'stock_quantity', 'quantity', 'reserved_quantity', 'sold_quantity', 'height', 'width', 'length', 'weight', 'product_id', 'in_stock'];

    /**
     * Get the parent product that this variant belongs to.
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function product()
    {
        return $this->belongsTo(Product::class);
    }

    /**
     * Get the attribute product pivot records associated with this variant.
     *
     * @return \Illuminate\Database\Eloquent\Relations\HasMany
     */
    public function attributeProducts()
    {
        return $this->hasMany(AttributeProduct::class);
    }

    /**
     * Get the current price attribute (alias for sale price).
     *
     * @return float|null
     */
    public function getCurrentPriceAttribute()
    {
        if (array_key_exists('current_price', $this->attributes) && $this->attributes['current_price'] !== null) {
            return $this->attributes['current_price'];
        }

        return $this->getSalePriceAttribute();
    }

    /**
     * Get the final price attribute (alias for sale price).
     *
     * @return float|null
     */
    public function getFinalPriceAttribute()
    {
        if (array_key_exists('current_price', $this->attributes) && $this->attributes['current_price'] !== null) {
            return $this->attributes['current_price'];
        }

        return $this->getSalePriceAttribute();
    }

    /**
     * Get the computed sale price considering parent product discounts and active flash sales.
     *
     * @return float|null
     */
    public function getSalePriceAttribute()
    {
        if (array_key_exists('sale_price', $this->attributes) && $this->attributes['sale_price'] !== null) {
            return $this->attributes['sale_price'];
        }

        if (array_key_exists('current_price', $this->attributes) && $this->attributes['current_price'] !== null) {
            return $this->attributes['current_price'];
        }
        $product = $this->relationLoaded('product') ? $this->product : $this->product()->with('flash_sales')->first();

        if (!$product) {
            return $this->price;
        }

        return app(ProductPricingService::class)->calculateVariantCurrentPrice($product, $this);
    }

    /**
     * Get the available stock quantity (stock minus reserved).
     *
     * @return int
     */
    public function getAvailableStockAttribute(): int
    {
        return max(0, (int) $this->stock_quantity - (int) ($this->reserved_quantity ?? 0));
    }

    /**
     * Get the quantity attribute (delegates to available stock).
     *
     * @return int
     */
    public function getQuantityAttribute(): int
    {
        return $this->available_stock;
    }

    /**
     * Set the quantity attribute (maps to stock_quantity).
     *
     * @param  mixed $value
     * @return void
     */
    public function setQuantityAttribute($value): void
    {
        $this->attributes['stock_quantity'] = (int) $value;
    }
}
