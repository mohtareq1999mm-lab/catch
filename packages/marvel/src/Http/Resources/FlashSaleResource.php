<?php

namespace Marvel\Http\Resources;

use Illuminate\Http\Request;

class FlashSaleResource extends Resource
{
    /**
     * Transform the resource into an array.
     *
     * @param  Request  $request
     * @return array
     */
    public function toArray($request)
    {
        return [
            "id" => $this->id,
            "title" => request()->routeIs('flash-sale.index') ? $this->getTranslation("title", app()->getLocale()) : [
                'ar' => $this->getTranslation('title', 'ar'),
                'en' => $this->getTranslation('title', 'en'),
            ],
            "slug" => $this->slug,
            "image" => [
                'desktop' => $this?->getFirstMediaUrl('flash-sales-desktop'),
                'mobile' => $this?->getFirstMediaUrl('flash-sales-mobile'),
            ],

            "description" => $this->getTranslation("description", app()->getLocale()),
            'start_date' => $this->start_date,
            'end_date'   => $this->end_date,
            "status" => (bool) $this->status,
            "is_valid" => $this->isValid(),
            "type" => $this->typeByLang(),
            "discount" => $this->discount,
            "max_discount_amount" => $this->roundMoney($this->max_discount_amount),
            "created_at" => $this->created_at->format('Y-m-d'),


            $this->mergeWhen($this->relationLoaded('products'), function () {
                return [
                    "products" => ProductResource::collection($this->products),
                ];
            }),
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