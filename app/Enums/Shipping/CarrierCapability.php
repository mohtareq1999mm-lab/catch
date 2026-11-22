<?php

declare(strict_types=1);

namespace App\Enums\Shipping;

use App\Services\Shipment\Contracts\CancelsCarrierShipments;
use App\Services\Shipment\Contracts\CreatesCarrierShipments;
use App\Services\Shipment\Contracts\LinksCarrierReturns;
use App\Services\Shipment\Contracts\ProcessesCarrierBulk;
use App\Services\Shipment\Contracts\ProvidesCarrierLabels;
use App\Services\Shipment\Contracts\ProvidesCarrierQuotes;
use App\Services\Shipment\Contracts\ProvidesCarrierTracking;
use App\Services\Shipment\Contracts\ProvidesCodEvidence;
use App\Services\Shipment\Contracts\SchedulesCarrierPickup;
use App\Services\Shipment\Contracts\VerifiesCarrierWebhooks;

/**
 * Opt-in capabilities. Every case maps to exactly one narrow interface;
 * there is deliberately no fat "does everything" carrier contract.
 */
enum CarrierCapability: string
{
    case QUOTE = 'quote';
    case CREATE_SHIPMENT = 'create_shipment';
    case CANCEL_SHIPMENT = 'cancel_shipment';
    case LABEL = 'label';
    case PICKUP = 'pickup';
    case TRACKING = 'tracking';
    case WEBHOOK_VERIFICATION = 'webhook_verification';
    case RETURNS = 'returns';
    case BULK = 'bulk';
    case COD_EVIDENCE = 'cod_evidence';

    /** @return class-string */
    public function contract(): string
    {
        return match ($this) {
            self::QUOTE => ProvidesCarrierQuotes::class,
            self::CREATE_SHIPMENT => CreatesCarrierShipments::class,
            self::CANCEL_SHIPMENT => CancelsCarrierShipments::class,
            self::LABEL => ProvidesCarrierLabels::class,
            self::PICKUP => SchedulesCarrierPickup::class,
            self::TRACKING => ProvidesCarrierTracking::class,
            self::WEBHOOK_VERIFICATION => VerifiesCarrierWebhooks::class,
            self::RETURNS => LinksCarrierReturns::class,
            self::BULK => ProcessesCarrierBulk::class,
            self::COD_EVIDENCE => ProvidesCodEvidence::class,
        };
    }
}
