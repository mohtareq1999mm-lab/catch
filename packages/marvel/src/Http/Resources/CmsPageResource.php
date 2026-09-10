<?php

declare(strict_types=1);

namespace Marvel\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Support\Collection;

class CmsPageResource extends Resource
{
    /**
     * Transform the resource into an array.
     *
     * @param  Request  $request
     * @return array<string, mixed>
     */
    public function toArray($request): array
    {
        $content = $this->content ?? [];

        if (is_array($content)) {
            $content = collect($content)
                ->sortBy('order')
                ->values()
                ->all();
        }

        return [
            'id' => $this->id,
            'slug' => $this->slug,
            'title' => $this->title,
            'content' => $content,
            'meta' => $this->meta,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}

