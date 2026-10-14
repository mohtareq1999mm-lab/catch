<?php

namespace Marvel\Http\Resources;

class WishlistResource extends Resource
{
    public function toArray($request): array
    {
        return [
            'id'                     => $this->id,
            'name'                   => $this->getTranslation('name', app()->getLocale()),
            'slug'                   => $this->slug,
            'price'                  => $this->product_type === 'simple'
                ? $this->roundMoney($this->current_price)
                : $this->roundMoney($this->variations[0]->current_price ?? $this->variations[0]->price ?? null),
            'current_price'          => $this->roundMoney($this->current_price),
            'price_after_discount'   => $this->roundMoney($this->price_after_discount),
            'price_after_flash_sale' => $this->roundMoney($this->price_after_flash_sale),
            'in_stock'               => $this->in_stock,
            'has_flash_sale'         => $this->has_flash_sale,
            'has_discount'           => $this->has_discount,
            "images"                 => $this->getmedia('products') ? $this->getmediaImages('products') : [],
            "variants"                => $this->whenLoaded('variations', function () {
                return $this->variations->map(function ($variant) {
                    return [
                        'id' => $variant->id,
                        'price' => $this->roundMoney($variant->price),
                        'current_price' => $this->roundMoney($variant->current_price),
                        'height' => $variant->height,
                        'width' => $variant->width,
                        'length' => $variant->length,
                        'weight' => $variant->weight,
                        'attributes' => $variant->attributeProducts->map(function ($attrProduct) {
                            return [
                                'attribute_name' => optional(optional($attrProduct->attributeValue)->attribute)->name,
                                'value' => optional($attrProduct->attributeValue)->value,
                            ];
                        }),
                    ];
                });
            }),
        ];
    }

    private function getmediaImages($collection)
    {
        return $this->getmedia($collection)->map(function ($media) {
            return $media->getUrl();
        });
    }

    private function roundMoney($value): ?float
    {
        if ($value === null || $value === '') {
            return null;
        }
        return round((float) $value, 2);
    }
}
