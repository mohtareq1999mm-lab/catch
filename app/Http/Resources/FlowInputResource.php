<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class FlowInputResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'flow_id' => $this->flow_id,
            'key' => $this->key,
            // Bilingual contract: always {en, ar}; admin-authored values,
            // never hardcoded controller strings.
            'label' => $this->localized('label'),
            'placeholder' => $this->localized('placeholder'),
            'help_text' => $this->localized('help_text'),
            'type' => $this->type,
            'source' => $this->source,
            'required' => (bool) $this->required,
            'required_at' => $this->required_at,
            'sort_order' => (int) $this->sort_order,
            'validation' => $this->validation,
            'is_active' => (bool) $this->is_active,
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }

    /**
     * @return array{en: ?string, ar: ?string}
     */
    private function localized(string $attribute): array
    {
        return \App\Support\LocalizedName::for($this->resource, $attribute);
    }
}
