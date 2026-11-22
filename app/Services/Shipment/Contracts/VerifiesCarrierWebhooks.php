<?php

declare(strict_types=1);

namespace App\Services\Shipment\Contracts;

use App\DTOs\Shipping\CarrierWebhookEvent;

interface VerifiesCarrierWebhooks
{
    public function verifyWebhook(string $payload, string $signature): CarrierWebhookEvent;
}
