<?php

namespace App\Http\Resources\Category;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CategoryWithChildNameResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $level = (int) ($this->level ?? 0);

        return [
            'id' => $this->id,
            'name' => $this->getTranslation('name', app()->getLocale()),
            'slug' => $this->slug,
            'level' => $level,
            $this->mergeWhen(!request()->routeIs('navbar'), [
                'image' => [
                    'desktop' => $this->getFirstMediaUrl('categories-desktop'),
                    'mobile' => $this->getFirstMediaUrl('categories-mobile'),
                ],
            ]),

            'children' => $level >= 2
                ? []
                : ($this->relationLoaded('children')
                    ? $this->children->map(function ($child) use ($request) {
                        return (new CategoryHomeResource($child))->toArray($request);
                    })->values()
                    : []),
        ];
    }
}