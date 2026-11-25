<?php

declare(strict_types=1);

namespace App\Enums\Shipping;

enum ShippingOutboxStatus: string
{
    case PENDING = 'pending';
    case PROCESSING = 'processing';
    case SUCCEEDED = 'succeeded';
    case FAILED = 'failed';
    case UNKNOWN = 'unknown';
    case FAILED_UNKNOWN = 'failed_unknown';
}
