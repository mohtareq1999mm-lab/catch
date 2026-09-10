<?php

namespace App\Http\Resources\Pages;

use Illuminate\Http\Resources\Json\JsonResource;

class ContentPageResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     */
    public function toArray($request)
    {
        return [
            'id' => $this->id,
            'title' => $this->title,
            'slug' => $this->slug,
            'is_active' => (bool) $this->is_active,
            'sections' => SectionResource::collection($this->whenLoaded('sections')),
        ];
    }
}
