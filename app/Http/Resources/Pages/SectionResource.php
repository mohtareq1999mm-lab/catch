<?php

namespace App\Http\Resources\Pages;

use Illuminate\Http\Resources\Json\JsonResource;
use Marvel\Database\Models\SectionType;

class SectionResource extends JsonResource
{
    private ?array $resolvedSettings = null;

    private function getSettings(): array
    {
        if ($this->resolvedSettings !== null) {
            return $this->resolvedSettings;
        }

        if ($this->setting !== null) {
            return $this->resolvedSettings = $this->setting;
        }

        $sectionType = $this->relationLoaded('sectionType')
            ? $this->sectionType
            : SectionType::where('type', $this->type)->first();
        if (!$sectionType) {
            return $this->resolvedSettings = ['front' => [], 'back' => []];
        }

        $front = $sectionType->settings->firstWhere('setting_key', 'front')?->value ?? [];
        $back = $sectionType->settings->firstWhere('setting_key', 'back')?->value ?? [];

        return $this->resolvedSettings = ['front' => $front, 'back' => $back];
    }

    public function toArray($request)
    {
        $settings = $this->getSettings();
        return [
            'id' => $this->id,
            'type' => $this->type,
            'title' => $this->title_visible ? $this->title : null,
            'is_active' => (bool) $this->is_active,
            'endpoint' => $this->buildEndpoint($settings),
            'order' => $this->order,
            'setting' => $settings,
        ];
    }

    private function buildEndpoint(array $settings): string
    {
        $params = $settings['back'] ?? [];

        return 'general/' . $this->type . '?' . http_build_query(
            array_filter($params)
        );
    }
}
