<?php

declare(strict_types=1);

namespace App\Services\Shipment;

use App\DTOs\Shipping\CarrierReturnLink;
use App\Exceptions\CarrierReturnLinkSkipped;
use App\Models\Shipment;
use App\Services\Fulfillment\ReturnService;
use App\Services\Shipment\Contracts\LinksCarrierReturns;
use InvalidArgumentException;

/**
 * #174: link-only carrier-return adapter.
 *
 * The DTO/provider seam is translated into the existing ReturnService call;
 * this class never creates a shipment, return request, or return lifecycle.
 */
final class CarrierReturnLinkService implements LinksCarrierReturns
{
    public function __construct(private readonly ReturnService $returns) {}

    /** @param array<string,mixed> $parameters */
    public function linkReturn(array $parameters): CarrierReturnLink
    {
        $shipment = $this->shipment($parameters['shipment_id'] ?? null);
        $evidence = $this->evidence($parameters);

        $linked = $this->returns->linkCarrierReturn($shipment, $evidence['metadata']);
        if ($linked === null) {
            throw new CarrierReturnLinkSkipped('No suitable return request is available for carrier return linkage.');
        }

        return new CarrierReturnLink(
            $evidence['provider_code'],
            $evidence['mode'],
            $evidence['external_ids'],
            null,
            $evidence['reference'],
        );
    }

    private function shipment(mixed $candidate): Shipment
    {
        $shipmentId = $this->positiveInt($candidate, 'shipment id');
        $shipment = Shipment::query()->find($shipmentId);
        if ($shipment === null) {
            throw new CarrierReturnLinkSkipped("Shipment [{$shipmentId}] was not found.");
        }

        return $shipment;
    }

    /**
     * @param  array<string,mixed>  $parameters
     * @return array{provider_code:string,mode:string,reference:string,external_ids:array<string,string>,metadata:array<string,mixed>}
     */
    private function evidence(array $parameters): array
    {
        $providerCode = $this->nonEmptyString($parameters['provider_code'] ?? null, 'provider code');
        $mode = $this->nonEmptyString($parameters['mode'] ?? null, 'mode');
        $reference = $this->nonEmptyString($parameters['redacted_payload_reference'] ?? null, 'redacted payload reference');
        $externalIds = $this->externalIds($parameters['external_ids'] ?? []);

        return [
            'provider_code' => $providerCode,
            'mode' => $mode,
            'reference' => $reference,
            'external_ids' => $externalIds,
            'metadata' => $this->providerMetadata(
                $parameters['metadata'] ?? [],
                [
                    'provider_code' => $providerCode,
                    'mode' => $mode,
                    'redacted_payload_reference' => $reference,
                    ...$externalIds,
                ],
            ),
        ];
    }

    /** @param array<string,mixed> $carrierMetadata */
    private function providerMetadata(mixed $callerMetadata, array $carrierMetadata): array
    {
        $metadata = $this->metadata($callerMetadata);
        foreach ($carrierMetadata as $key => $value) {
            if (!array_key_exists($key, $metadata)) {
                $metadata[$key] = $value;
            }
        }

        return $metadata;
    }

    /** @return array<string,string> */
    private function externalIds(mixed $candidate): array
    {
        if (!is_array($candidate) || $candidate === []) {
            throw new InvalidArgumentException('Carrier return requires at least one external identifier.');
        }

        $externalIds = [];
        foreach ($candidate as $name => $value) {
            if (!is_string($name) || trim($name) === '' || !is_string($value) || trim($value) === '') {
                throw new InvalidArgumentException('Carrier return external identifiers must be non-empty strings.');
            }
            $externalIds[$name] = $value;
        }

        return $externalIds;
    }

    /** @return array<string,mixed> */
    private function metadata(mixed $candidate): array
    {
        if (!is_array($candidate)) {
            throw new InvalidArgumentException('Carrier return metadata must be an array.');
        }

        $metadata = [];
        foreach ($candidate as $key => $value) {
            if (is_string($key) && $key !== '' && $value !== null) {
                $metadata[$key] = $value;
            }
        }

        return $metadata;
    }

    private function nonEmptyString(mixed $candidate, string $label): string
    {
        if (!is_string($candidate) || trim($candidate) === '') {
            throw new InvalidArgumentException("Carrier return requires a non-empty {$label}.");
        }

        return trim($candidate);
    }

    private function positiveInt(mixed $candidate, string $label): int
    {
        if (is_int($candidate) && $candidate > 0) {
            return $candidate;
        }
        if (is_string($candidate)) {
            $normalized = trim($candidate);
            if (preg_match('/^[1-9][0-9]*$/', $normalized) === 1 && (string) (int) $normalized === $normalized) {
                return (int) $normalized;
            }
        }

        throw new InvalidArgumentException("Carrier return requires a positive {$label}.");
    }
}
