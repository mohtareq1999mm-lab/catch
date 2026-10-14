<?php

namespace Marvel\Http\Resources;

use Illuminate\Http\Request;

class PromotionResource extends Resource
{
    public function toArray(Request $request)
    {
        return [
            'id' => $this->id,
            'name' => request()->routeIs('promotions.index') ? $this->getTranslation('name',app()->getLocale()) : $this->getRawOriginal('name'),
            'slug' =>$this->slug,
            'type' => $this->typeByLang(),
            'discount_type' => $this->type_amount,
            'value' => $this->value,
            'discount' => $this->discount ?? $this->value,
            'code' => $this->code,
            'minimum_order_amount' => $this->roundMoney($this->minimum_order_amount),
            'required_quantity' => $this->required_quantity_type,
            'apply_to' => $this->apply_to,
            'products' => $this->whenLoaded('products'),
            'gift_products' => $this->whenLoaded('giftProducts'),
            'image' => [
                'desktop' => $this->getFirstMediaUrl('promotions-desktop') ?:null,
                'mobile' => $this->getFirstMediaUrl('promotions-mobile') ?: null,
            ],
            'start_at' => $this->start_at ? $this->start_at->toIso8601String() : null,
            'end_at' => $this->end_at ? $this->end_at->toIso8601String() : null,
            'status' => (bool) $this->status,
            'is_valid' => $this->isValid(),
            'created_at' => $this->created_at ? $this->created_at->toIso8601String() : null,
        ];
    }

    private function roundMoney($value): ?float
    {
        if ($value === null || $value === '') {
            return null;
        }
        return round((float) $value, 2);
    }
}
