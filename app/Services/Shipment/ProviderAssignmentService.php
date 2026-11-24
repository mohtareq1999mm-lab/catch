<?php

declare(strict_types=1);

namespace App\Services\Shipment;

use App\Audit\ActivityAuditService;
use App\Audit\ActivityContext;
use App\Audit\ActivitySnapshot;
use App\Enums\Shipping\ProviderMode;
use App\Exceptions\CarrierConfigurationException;
use App\Exceptions\ProviderOverrideConflictException;
use App\Models\OrderTrackingEvent;
use App\Models\Shipment;
use App\Support\ProviderCode;
use App\ValueObjects\TransitionActor;
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
        private readonly CarrierStatusMapper $mappings,
    ) {}

    /** @return array{provider_code:string,service_level:string,mapping_version:string,provider_mode:string} */
    public function resolve(): array
    {
        $code = $this->config->get('shipping.default_provider');
        if (!is_string($code) || !ProviderCode::isValid($code)) {
            throw CarrierConfigurationException::unknownProvider(is_string($code) ? $code : '');
        }

        return $this->forProvider($code);
    }

    /** @return array{provider_code:string,service_level:string,mapping_version:string,provider_mode:string} */
    public function forProvider(string $providerCode): array
    {
        $code = trim($providerCode);
        if (!ProviderCode::isValid($code)) {
            throw CarrierConfigurationException::unknownProvider($providerCode);
        }
        if (!$this->factories->hasProvider($code)) {
            throw CarrierConfigurationException::unknownProvider($code);
        }

        // #149/#160: mixed/invalid mode, disabled provider, missing creds, or
        // missing mapping version must fail closed before assignment.
        $mode = $this->factories->resolveMode($code);
        $this->factories->assertProviderConfigResolvable($code, $mode);

        if (!$this->factories->status($code)['enabled']) {
            throw CarrierConfigurationException::disabled($code);
        }

        $level = $this->config->get("shipping.providers.{$code}.service_level");
        if (!is_string($level) || trim($level) === '') {
            throw CarrierConfigurationException::missingServiceLevel($code);
        }

        // #172: the mapping version belongs to the same configured provider
        // and mode. Without a valid version the assignment fails closed; no
        // default, client value or historical guess is ever substituted.
        $mappingVersion = $this->mappings->mappingVersion($code, $mode);
        if ($mappingVersion === null || $mappingVersion === '') {
            throw CarrierConfigurationException::missingMappingVersion($code, $mode);
        }

        return [
            'provider_code' => $code,
            'service_level' => trim($level),
            'mapping_version' => $mappingVersion,
            'provider_mode' => $mode,
        ];
    }

    /**
     * #162: the authority boundary passes the actor explicitly. Ambient auth
     * is never consulted here; a missing actor is an unauthenticated/system
     * override, not an implied admin.
     */
    public function override(Shipment $shipment, string $providerCode, string $reason, ?TransitionActor $actor = null): Shipment
    {
        // The FormRequest owns required/string/max:500; only whitespace-only
        // input survives it and needs a translated rejection here.
        $reason = trim($reason);
        if ($reason === '') {
            throw new InvalidArgumentException(__('shipping.audit.override_reason_required'));
        }

        $assignment = $this->forProvider($providerCode);

        return DB::transaction(function () use ($shipment, $assignment, $reason, $actor): Shipment {
            $locked = Shipment::whereKey($shipment->getKey())->lockForUpdate()->firstOrFail();

            if ($this->hasAwbEvidence($locked)) {
                throw ProviderOverrideConflictException::awbAlreadyCreated((int) $locked->id);
            }

            $snapshot = (string) $locked->provider_mode;
            $mode = $this->overrideMode($assignment['provider_code'], $snapshot, $assignment['provider_mode']);

            $old = [
                'provider_code' => $locked->provider_code,
                'provider_mode' => $snapshot,
                'mapping_version' => $locked->mapping_version,
                'service_level' => $locked->provider_service_level,
                'provider_override' => (bool) $locked->provider_override,
            ];
            $new = [
                'provider_code' => $assignment['provider_code'],
                'provider_mode' => $mode,
                'mapping_version' => $assignment['mapping_version'],
                'service_level' => $assignment['service_level'],
                'provider_override' => true,
            ];

            $locked->update([
                'provider_code' => $new['provider_code'],
                'provider_mode' => $mode,
                'mapping_version' => $new['mapping_version'],
                'provider_service_level' => $new['service_level'],
                'provider_override' => true,
            ]);

            $actor ??= TransitionActor::system();
            $actorUser = $actor->user;
            $source = match ($actor->kind) {
                TransitionActor::KIND_ADMIN => 'admin',
                TransitionActor::KIND_SYSTEM => 'system',
                TransitionActor::KIND_GATEWAY => 'gateway',
                TransitionActor::KIND_GUEST => 'guest',
                default => 'user',
            };

            // The activity causer and the timeline actor come from the same
            // explicit context, so neither can disagree with the audit source.
            // A fully-formed snapshot avoids ActorResolver's ambient auth read.
            ActivityAuditService::record(new ActivitySnapshot(
                logName: 'shipping',
                event: 'provider_override',
                description: __('shipping.audit.provider_overridden'),
                subjectType: Shipment::class,
                subjectId: (int) $locked->id,
                causerType: $actorUser !== null ? get_class($actorUser) : null,
                causerId: $actor->historyActorId(),
                old: $old,
                new: $new,
                context: array_merge(ActivityContext::capture(), [
                    'shipment_uuid' => $locked->uuid,
                    'actor' => $actorUser !== null
                        ? ['type' => 'human', 'user_id' => $actor->historyActorId(), 'user_type' => get_class($actorUser)]
                        : ['type' => 'system'],
                ]),
                reason: $reason,
            ));

            // #162: override is audit-only. Record a non-customer-visible timeline
            // event with actor and notes, and never dispatch a status-change event.
            OrderTrackingEvent::create([
                'order_id' => (int) $locked->order_id,
                'event_type' => 'shipment.provider_overridden',
                'event_timestamp' => now(),
                'actor_type' => $actor->historyActorType(),
                'actor_id' => $actor->historyActorId(),
                'actor_name' => $actorUser?->name ?? 'System',
                'old_status' => null,
                'new_status' => null,
                'metadata' => [
                    'shipment_id' => (int) $locked->id,
                    'shipment_uuid' => $locked->uuid,
                    'old' => $old,
                    'new' => $new,
                ],
                'customer_visible' => false,
                'customer_label_key' => 'tracking.shipment.provider_overridden',
                'customer_description_key' => 'tracking.shipment.provider_overridden_description',
                'admin_notes' => $reason,
                'source' => $source,
                'ip_address' => request()->ip(),
            ]);

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

