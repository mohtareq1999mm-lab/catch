<?php

declare(strict_types=1);

namespace App\Services\Fulfillment;

use App\DTOs\Shipping\CarrierReturnLink;
use App\Models\Fulfillment\ReturnRequest;

/**
 * #179: carrier returns are link evidence only.
 *
 * ReturnService remains the sole lifecycle authority. This processor writes
 * provider metadata and never inspects, approves, disposes, restocks, or
 * completes a return.
 */
final class CarrierReturnProcessor
{
    public function link(ReturnRequest $return, CarrierReturnLink $link): ReturnRequest
    {
        $metadata = is_array($return->provider_return_metadata)
            ? $return->provider_return_metadata
            : [];
        $references = array_values($link->externalIds);

        $metadata['provider_code'] = $link->providerCode;
        $metadata['mode'] = $link->mode;
        $metadata['carrier_reference'] = (string) ($references[0] ?? '');
        $metadata['redacted_payload_reference'] = $link->redactedPayloadReference;

        $return->forceFill([
            'provider_return_metadata' => $metadata,
        ])->save();

        return $return->fresh();
    }
}
