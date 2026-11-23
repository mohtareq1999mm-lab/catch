<?php

declare(strict_types=1);

namespace App\Services\Shipment;

use App\Audit\ActivityAuditService;
use App\Enums\Shipping\ProviderMode;
use App\Exceptions\CarrierConfigurationException;
use App\Exceptions\ProviderOverrideConflictException;
use App\Models\Shipment;
use App\Support\ProviderCode;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * #148: the only authority for which carrier handles a shipment.
 *
 * Initial assignment is derived from deployment config; a different provider
 * requires an explicit, reason-bearing admin override. The legacy `courier`
 * field is never consulted here.
 */
final class ProviderAssignmentService
{
    public function __construct(
        private readonly Repository $config,
        private readonly CarrierGatewayFactory $factories,
    ) {}

    /** @return array{provider_code:string,service_level:string,provider_mode:string} */
    public function resolve(): array
    {
        $code = $this->config->get('shipping.default_provider');
        if (!is_string($code) || !ProviderCode::isValid($code)) {
            throw CarrierConfigurationException::unknownProvider(is_string($code) ? $code : '');
        }

        return $this->forProvider($code);
    }

    /** @return array{provider_code:string,service_level:string,provider_mode:string} */
    public function forProvider(string $providerCode): array
    {
        $code = trim($providerCode);
        if (!ProviderCode::isValid($code)) {
            throw CarrierConfigurationException::unknownProvider($providerCode);
        }
        if (!$this->factories->hasProvider($code)) {
            throw CarrierConfigurationException::unknownProvider($code);
        }

        // #149: mixed or invalid mode configuration must fail before an
        // assignment (and therefore a mode snapshot) can be produced.
        $mode = $this->factories->resolveMode($code);

        if (!$this->factories->status($code)['enabled']) {
            throw CarrierConfigurationException::disabled($code);
        }

        $level = $this->config->get("shipping.providers.{$code}.service_level");
        if (!is_string($level) || trim($level) === '') {
            throw CarrierConfigurationException::missingServiceLevel($code);
        }

        return ['provider_code' => $code, 'service_level' => trim($level), 'provider_mode' => $mode];
    }

    public function override(Shipment $shipment, string $providerCode, string $reason): Shipment
    {
        // The FormRequest owns required/string/max:500; only whitespace-only
        // input survives it and needs a translated rejection here.
        $reason = trim($reason);
        if ($reason === '') {
            throw new InvalidArgumentException(__('shipping.audit.override_reason_required'));
        }

        $assignment = $this->forProvider($providerCode);

        return DB::transaction(function () use ($shipment, $assignment, $reason): Shipment {
            $locked = Shipment::whereKey($shipment->getKey())->lockForUpdate()->firstOrFail();

            if ($this->hasAwbEvidence($locked)) {
                throw ProviderOverrideConflictException::awbAlreadyCreated((int) $locked->id);
            }

            $snapshot = (string) $locked->provider_mode;
            $mode = $this->overrideMode($assignment['provider_code'], $snapshot, $assignment['provider_mode']);

            $old = [
                'provider_code' => $locked->provider_code,
                'provider_mode' => $snapshot,
                'service_level' => $locked->provider_service_level,
                'provider_override' => (bool) $locked->provider_override,
            ];
            $new = [
                'provider_code' => $assignment['provider_code'],
                'provider_mode' => $mode,
                'service_level' => $assignment['service_level'],
                'provider_override' => true,
            ];

            $locked->update([
                'provider_code' => $new['provider_code'],
                'provider_mode' => $mode,
                'provider_service_level' => $new['service_level'],
                'provider_override' => true,
            ]);

            ActivityAuditService::recordModel(
                $locked,
                'provider_override',
                'shipping',
                __('shipping.audit.provider_overridden'),
                $old,
                $new,
                ['shipment_uuid' => $locked->uuid],
                $reason,
            );

            return $locked->fresh();
        });
    }

    /**
     * #149: `internal` is a local provider with no external environment. The
     * first explicit override to a carrier may establish its sandbox or
     * production mode; once established, the snapshot is immutable and a
     * cross-environment override fails closed without audit or retry.
     */
    private function overrideMode(string $providerCode, string $snapshot, string $target): string
    {
        if ($snapshot === $target) {
            return $snapshot;
        }
        if ($snapshot === ProviderMode::INTERNAL->value) {
            return $target;
        }

        throw CarrierConfigurationException::modeMismatch($providerCode, $snapshot, $target);
    }

    /**
     * #148: AWB evidence is either an explicit timestamp or an issued tracking
     * number. Both mean the physical label may already be with a carrier.
     */
    private function hasAwbEvidence(Shipment $shipment): bool
    {
        $trackingNumber = $shipment->tracking_number;

        return $shipment->awb_created_at !== null
            || (is_string($trackingNumber) && trim($trackingNumber) !== '');
    }
}
