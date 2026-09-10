<?php

namespace App\Http\Resources\Category;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CategoryNavbarResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $level = (int) ($this->level ?? 0);
        $requestedLevel = $request->query('level');
        $maxLevel = $requestedLevel !== null && $requestedLevel !== '' && (int) $requestedLevel > 0
            ? (int) $requestedLevel
            : 3;

        return [
            'id' => $this->id,
            'name' => $this->getTranslation('name', app()->getLocale()),
            'slug' => $this->slug,
            'level' => $level,
            'image' => [
                'desktop' => $this->getFirstMediaUrl('categories-desktop'),
                'mobile' => $this->getFirstMediaUrl('categories-mobile'),
            ],
            'children' => $level >= $maxLevel
                ? []
                : ($this->relationLoaded('children')
                    ? $this->children->map(function ($child) use ($request) {
                        return (new CategoryNavbarResource($child))->toArray($request);
                    })->values()
                    : []),
        ];
    }
}
