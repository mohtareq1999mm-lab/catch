<?php

declare(strict_types=1);

namespace App\Enums\Shipping;

/**
 * Outcome reported by a notification delivery adapter.
 *
 * AMBIGUOUS is deliberately distinct from FAILED: when transmission cannot be
 * ruled out, a blind retry may duplicate a message that was already accepted.
 */
enum NotificationDeliveryResult: string
{
    case DELIVERED = 'delivered';
    case FAILED = 'failed';
    case AMBIGUOUS = 'ambiguous';
}
