<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Customer-safe Order Flow definition.
 *
 * The single sanitized contract for BOTH frontend discovery endpoints:
 * - GET /api/v1/general/order-flows/available (guest-safe, all active flows)
 * - GET /api/v1/general/order-flows/by-shipping-type/{type} (auth, one flow)
 *
 * Exposes the business contract only (shipping_type selection + ordered
 * statuses + input schemas). Internal identifiers (id, flow_id),
 * administrative flags (is_active, is_default) and timestamps are NEVER
 * exposed here — the admin OrderFlowResource/FlowInputResource carry those.
 * Machine identifiers (code, key, type, source, required_at) stay
 * untranslated; human labels stay bilingual {en, ar}.
 */
class AvailableOrderFlowResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'shipping_type' => $this->shipping_type,
            'code' => $this->code,
            'name' => \App\Support\LocalizedName::for($this->resource, 'name'),
            'statuses' => $this->when(
                $this->relationLoaded('statuses'),
                fn () => $this->statuses->map(fn ($status) => [
                    'code' => $status->code,
                    'name' => \App\Support\LocalizedName::for($status, 'name'),
                    'sort_order' => (int) $status->pivot->sort_order,
                ])->values()->all(),
                []
            ),
            'inputs' => $this->when(
                $this->relationLoaded('inputs'),
                fn () => $this->inputs->map(fn ($input) => [
                    'key' => $input->key,
                    'label' => \App\Support\LocalizedName::for($input, 'label'),
                    'placeholder' => \App\Support\LocalizedName::for($input, 'placeholder'),
                    'help_text' => \App\Support\LocalizedName::for($input, 'help_text'),
                    'type' => $input->type,
                    'source' => $input->source,
                    'required' => (bool) $input->required,
                    'required_at' => $input->required_at,
                    'sort_order' => (int) $input->sort_order,
                    'validation' => $input->validation,
                ])->values()->all(),
                []
            ),
        ];
    }
}
