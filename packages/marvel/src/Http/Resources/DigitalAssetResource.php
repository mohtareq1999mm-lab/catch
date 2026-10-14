<?php

namespace Marvel\Http\Resources;

use Illuminate\Http\Request;

class DigitalAssetResource extends Resource
{
    public function toArray($request): array
    {
        return [
            'id'            => $this->id,
            'uuid'          => $this->uuid,
            'type'          => $this->type,
            'original_name' => $this->original_name,
            'display_name'  => $this->display_name,
            'mime'          => $this->mime,
            'size'          => (int) $this->size,
            'sort_order'    => (int) $this->sort_order,

            // W5 — URL assets expose their (validated, public) target to
            // authorized admin viewers only through this resource; customer
            // disclosure is gated by entitlement in the general controller.
            // FILE storage internals and LICENSE/ACCESS secrets are NEVER
            // serialized here.
            'external_url'  => $this->when(
                $this->type === \App\Enums\DigitalAssetType::URL->value,
                fn () => $this->external_url
            ),

            'created_at'    => $this->created_at?->toIso8601String(),
            'updated_at'    => $this->updated_at?->toIso8601String(),
        ];
    }
}
