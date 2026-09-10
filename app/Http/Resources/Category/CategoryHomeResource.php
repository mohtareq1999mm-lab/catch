<?php

namespace App\Http\Resources\Category;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CategoryHomeResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id'                   => $this->id,
            'name'                 => $this->getTranslation('name', app()->getLocale()),
            'slug'                 => $this->slug,
            'image'                => [
                'desktop' =>   $this->getFirstMediaUrl('categories-desktop'),
                'mobile' =>   $this->getFirstMediaUrl('categories-mobile'),
            ],
            'products_count'       => $this->whenCounted('products'),
            $this->mergeWhen($this->getTranslation('details', app()->getLocale()), [
                'details' => $this->getTranslation('details', app()->getLocale()),
            ]),
        ];
    }
}