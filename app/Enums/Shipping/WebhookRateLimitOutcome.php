<?php

declare(strict_types=1);

namespace App\Enums\Shipping;

enum WebhookRateLimitOutcome: string
{
    case ALLOWED = 'allowed';
    case LIMITED = 'limited';
    case UNAVAILABLE = 'unavailable';
}
