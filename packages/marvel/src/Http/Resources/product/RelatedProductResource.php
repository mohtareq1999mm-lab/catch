<?php

namespace Marvel\Http\Resources;

use Illuminate\Http\Request;

class RelatedProductResource extends Resource
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
            'id'                   => $this->id,
            'name'                 => $this->name,
            'slug'                 => $this->slug,
            'language'             => $this->language,
            'translated_languages' => $this->translated_languages,
            'product_type'         => $this->product_type,
            'current_price'        => $this->roundMoney($this->current_price),
            'max_price'            => $this->roundMoney($this->max_price),
            'min_price'            => $this->roundMoney($this->min_price),
            'image'                => $this->image,
            'video'                => $this->video,
            'price'                => $this->roundMoney($this->current_price),
            'unit'                 => $this->unit
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
