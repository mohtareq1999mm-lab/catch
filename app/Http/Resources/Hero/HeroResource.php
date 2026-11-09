<?php

namespace App\Http\Resources\Hero;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Marvel\Database\Models\Hero;

class HeroResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $collection = $this->getMediaCollectionNameForType($this->type);
        $first = $this->getFirstMedia($collection) ?: $this->getFirstMedia();

        $media = null;
        if ($first) {
            $media = [
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

        $translations = $this->getTranslations('description');
        $locale = app()->getLocale();
        $localizedDescription = $this->getTranslation('description', $locale, false)
            ?: ($translations['en'] ?? $translations['ar'] ?? null);

        return [
            'id' => $this->id,
            'type' => $this->type ?? Hero::TYPE_IMAGE,
            'description' => $localizedDescription,
            'is_active' => (bool) $this->is_active,
            'media' => $media,
        ];
    }
}

