<?php

declare(strict_types=1);

namespace App\Enums\Shipping;

/**
 * Inbox lifecycle. #143 only writes RECEIVED; PROCESSED/FAILED are retained
 * for the future polling/replay worker and never mutate shipment state.
 */
enum ShippingWebhookStatus: string
{
    case RECEIVED = 'received';
    case PROCESSED = 'processed';
    case FAILED = 'failed';
}
