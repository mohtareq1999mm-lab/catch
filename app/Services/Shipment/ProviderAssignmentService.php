<?php

declare(strict_types=1);

namespace App\Services\Shipment;

use App\Audit\ActivityAuditService;
use App\Exceptions\CarrierConfigurationException;
use App\Exceptions\ProviderOverrideConflictException;
use App\Models\Shipment;
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

    /** @return array{provider_code:string,service_level:string} */
    public function resolve(): array
    {
        $code = $this->config->get('shipping.default_provider');
        if (!is_string($code) || preg_match('/^[A-Za-z0-9_-]+$/', $code) !== 1) {
            throw CarrierConfigurationException::unknownProvider(is_string($code) ? $code : '');
        }

        return $this->forProvider($code);
    }

    /** @return array{provider_code:string,service_level:string} */
    public function forProvider(string $providerCode): array
    {
        $code = trim($providerCode);
        if (preg_match('/^[A-Za-z0-9_-]+$/', $code) !== 1) {
            throw CarrierConfigurationException::unknownProvider($providerCode);
        }
        if (!$this->factories->hasProvider($code)) {
            throw CarrierConfigurationException::unknownProvider($code);
        }
        if (!$this->factories->status($code)['enabled']) {
            throw CarrierConfigurationException::disabled($code);
        }

        $level = $this->config->get("shipping.providers.{$code}.service_level");
        if (!is_string($level) || trim($level) === '') {
            throw CarrierConfigurationException::missingServiceLevel($code);
        }

        return ['provider_code' => $code, 'service_level' => trim($level)];
    }

    public function override(Shipment $shipment, string $providerCode, string $reason): Shipment
    {
        $reason = trim($reason);
        if ($reason === '' || mb_strlen($reason) > 500) {
            throw new InvalidArgumentException('A provider override requires a reason of at most 500 characters.');
        }

        $assignment = $this->forProvider($providerCode);

        return DB::transaction(function () use ($shipment, $assignment, $reason): Shipment {
            $locked = Shipment::whereKey($shipment->getKey())->lockForUpdate()->firstOrFail();

            if ($locked->awb_created_at !== null) {
                throw ProviderOverrideConflictException::awbAlreadyCreated((int) $locked->id);
            }

            $old = [
                'provider_code' => $locked->provider_code,
                'service_level' => $locked->provider_service_level,
                'provider_override' => (bool) $locked->provider_override,
            ];
            $new = [
                'provider_code' => $assignment['provider_code'],
                'service_level' => $assignment['service_level'],
                'provider_override' => true,
            ];

            $locked->update([
                'provider_code' => $new['provider_code'],
                'provider_service_level' => $new['service_level'],
                'provider_override' => true,
            ]);

            ActivityAuditService::recordModel(
                $locked,
                'provider_override',
                'shipping',
                'Shipping provider overridden by administrator',
                $old,
                $new,
                ['shipment_uuid' => $locked->uuid],
                $reason,
            );

            return $locked->fresh();
        });
    }
}
