<?php

namespace Marvel\Http\Resources\product;

use Illuminate\Http\Request;
use Marvel\Http\Resources\Resource;

class ProductVariantResource extends Resource
{
    public function toArray(Request $request)
    {
        return [
            'id' => $this->id,
            'product_id' => $this->product_id,
            'price' => $this->price !== null ? round((float) $this->price, 2) : null,
            'stock_quantity' => $this->stock_quantity,
            'reserved_quantity' => $this->reserved_quantity,
            'sold_quantity' => $this->sold_quantity,
            'in_stock' => $this->in_stock,
            'current_price' => $this->current_price !== null ? round((float) $this->current_price, 2) : null,
            'height' => $this->height,
            'width' => $this->width,
            'length' => $this->length,
            'weight' => $this->weight,
            'attributes' => $this->whenLoaded('attributeProducts', function () {
                return $this->attributeProducts->map(function ($ap) {
                    return [
                        'attribute' => $ap->attributeValue?->attribute?->name,
                        'attribute_id' => $ap->attributeValue?->attribute?->id,
                        'value' => $ap->attributeValue?->value,
                        'value_id' => $ap->attributeValue?->id,
                    ];
                });
            }),
        ];
    }
}
