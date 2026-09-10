<?php

namespace App\Http\Resources\Order;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class OrderProductVariantResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'price' => $this->roundMoney($this->price),
            'current_price' => $this->roundMoney($this->current_price ?? $this->price),
            'in_stock' => (bool) $this->in_stock,
            'attributes' => $this->when(
                $this->relationLoaded('attributeProducts'),
                fn () => $this->attributeProducts->map(fn ($attr) => [
                    'value_id' => $attr->attribute_value_id,
                    'value' => $attr->attributeValue?->getTranslation('value', app()->getLocale()),
                ])->values()
            ),
        ];
    }

    private function roundMoney(mixed $value): ?float
    {
        if ($value === null || $value === '') {
            return null;
        }

        return round((float) $value, 2);
    }
}