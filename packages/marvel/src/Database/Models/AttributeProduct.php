<?php

namespace Marvel\Database\Models;

use Illuminate\Database\Eloquent\Model;


class AttributeProduct extends Model
{
    protected $table = "attribute_product";
    protected $fillable = ['product_variant_id', 'attribute_value_id'];

    public function productVariant()
    {
        return $this->belongsTo(ProductVariant::class);
    }

    public function attributeValue()
    {
        return $this->belongsTo(AttributeValue::class);
    }
} 
