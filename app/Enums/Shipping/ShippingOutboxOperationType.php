<?php

declare(strict_types=1);

namespace App\Enums\Shipping;

/**
 * Fixed launch taxonomy for durable shipping outbox operations.
 *
 * Values are persisted verbatim and are part of the deterministic operation
 * key, so they are append-only at the contract level.
 */
enum ShippingOutboxOperationType: string
{
    case PROVIDER_CREATE_SHIPMENT = 'provider.create_shipment';
    case PROVIDER_CANCEL_SHIPMENT = 'provider.cancel_shipment';
    case PROVIDER_FETCH_LABEL = 'provider.fetch_label';
    case PROVIDER_SYNC_TRACKING = 'provider.sync_tracking';
    case NOTIFICATION_SEND = 'notification.send';
    case POLL_RUN = 'poll.run';
    case SETTLEMENT_IMPORT = 'settlement.import';
}
