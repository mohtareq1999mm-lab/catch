<?php

declare(strict_types=1);

namespace App\Http\Resources\Shipment;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * #148: the admin override response shape; the shipment is the only source
 * for these four fields.
 */
final class ProviderOverrideResource extends JsonResource
{
    /** @return array<string,mixed> */
    public function toArray(Request $request): array
    {
        return [
            'shipment_id' => $this->id,
            'provider_code' => $this->provider_code,
            'service_level' => $this->provider_service_level,
            'provider_override' => (bool) $this->provider_override,
        ];
    }
}
