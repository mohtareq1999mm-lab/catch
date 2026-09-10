<?php

namespace App\Http\Resources\StaticPage;

use Illuminate\Http\Resources\Json\JsonResource;

class StaticSectionResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     */
    public function toArray($request)
    {
        $media = null;
        if ($this->relationLoaded('media')) {
            $first = $this->getFirstMedia($this->getMediaCollectionForResource());
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
            } else {
                // Fallback: check alternative collection (image vs video)
                $fallback = $this->getFirstMedia();
                if ($fallback) {
                    $media = [
                        'id' => $fallback->id,
                        'url' => $fallback->getUrl(),
                        'thumb_url' => $fallback->hasGeneratedConversion('thumb') ? $fallback->getUrl('thumb') : null,
                        'collection_name' => $fallback->collection_name,
                        'name' => $fallback->name,
                        'file_name' => $fallback->file_name,
                        'mime_type' => $fallback->mime_type,
                        'size' => $fallback->size,
                    ];
                }
            }
        }

        return [
            'id' => $this->id,
            'static_page_id' => $this->static_page_id,
            'type' => $this->type ?? 'text',
            'title' => $this->getTranslation('title', app()->getLocale()),
            'content' => $this->getTranslations('content'),
            'config' => $this->config,
            'order' => (int) $this->order,
            'is_active' => (bool) ($this->is_active ?? true),
            'media' => $media,
        ];
    }

    private function getMediaCollectionForResource(): string
    {
        $type = $this->type ?? 'text';
        if (in_array($type, ['image', 'screenshot'], true)) {
            return \Marvel\Database\Models\StaticSection::COLLECTION_IMAGE;
        }
        if ($type === 'video') {
            return \Marvel\Database\Models\StaticSection::COLLECTION_VIDEO;
        }
        return \Marvel\Database\Models\StaticSection::COLLECTION_IMAGE;
    }
}