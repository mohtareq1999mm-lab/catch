<?php

namespace Marvel\Http\Resources;

use Illuminate\Http\Request;
use Marvel\Database\Models\Hero;

class HeroResource extends Resource
{
    /**
     * Transform the resource into an array.
     *
     * @param  Request  $request
     * @return array
     */
    public function toArray($request): array
    {
        $media = $this->resolveMediaPayload();
        $translations = $this->getTranslations('description');
        $locale = app()->getLocale();
        $localizedDescription = $this->getTranslation('description', $locale, false)
            ?: ($translations['en'] ?? $translations['ar'] ?? null);

        return [
            'id' => $this->id,
            'type' => $this->type ?? Hero::TYPE_IMAGE,
            'description' => request()->routeIs('heroes.index', 'hero.index')
                ? $localizedDescription
                : $translations,
            'description_translations' => $translations,
            'is_active' => (bool) $this->is_active,
            'media' => $media,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }

    private function resolveMediaPayload(): ?array
    {
        $collection = $this->getMediaCollectionNameForType($this->type);
        $first = $this->getFirstMedia($collection) ?: $this->getFirstMedia();

        if (!$first) {
            return null;
        }

        return [
            'id' => $first->id,
            'url' => $first->getUrl(),
            'thumb_url' => $first->hasGeneratedConversion('thumb') ? $first->getUrl('thumb') : null,
            'collection_name' => $first->collection_name,
            'name' => $first->name,
            'file_name' => $first->file_name,
            'mime_type' => $first->mime_type,
            'size' => $first->size,
        ];
    }
}

