<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * #179: finance acceptance state of a COD settlement statement.
 *
 * This is evidence state only; it never changes payment, order, shipment,
 * fulfillment, or inventory authority.
 */
enum CodStatementAcceptanceStatus: string
{
    case PENDING = 'pending';
    case ACCEPTED = 'accepted';
    case REJECTED = 'rejected';
    case SUPERSEDED = 'superseded';
}
