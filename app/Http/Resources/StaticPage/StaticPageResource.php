<?php

namespace App\Http\Resources\StaticPage;

use Illuminate\Http\Resources\Json\JsonResource;

class StaticPageResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     */
    public function toArray($request)
    {
        return [
            'id' => $this->id,
            'slug' => $this->slug,
            'title' => $this->getTranslation('title', app()->getLocale()),
            'is_active' => (bool) $this->is_active,
            'sections' => StaticSectionResource::collection($this->whenLoaded('staticSections')),
        ];
    }
}