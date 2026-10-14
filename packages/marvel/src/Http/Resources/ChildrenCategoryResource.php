<?php

namespace Marvel\Http\Resources;

use Illuminate\Http\Request;

class ChildrenCategoryResource extends Resource
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
            'name'                 => $this->getTranslation('name', app()->getLocale()),
            'slug'                 => $this->slug,
            'products_count'       => (int) ($this->products_count ?? $this->products()->count()),
            'image'                => [
                'desktop' => $this->getFirstMediaUrl('categories-desktop') ?: null,
                'mobile'  => $this->getFirstMediaUrl('categories-mobile') ?: null,
            ],
        ];
    }
}
