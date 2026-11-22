<?php

declare(strict_types=1);

namespace Tests\Support\Shipping;

use App\DTOs\Shipping\CarrierGatewayContext;
use App\DTOs\Shipping\CarrierMoney;
use App\DTOs\Shipping\CarrierQuote;
use App\Enums\Shipping\CarrierCapability;
use App\Services\Shipment\Contracts\CarrierGatewayContract;
use App\Services\Shipment\Contracts\ProvidesCarrierQuotes;

/**
 * Local-only adapter double: never performs outbound calls.
 */
class ConfiguredCarrierGateway implements CarrierGatewayContract, ProvidesCarrierQuotes
{
    public static int $constructions = 0;

    public function __construct(private readonly CarrierGatewayContext $context)
    {
        self::$constructions++;
    }

    public function context(): CarrierGatewayContext
    {
        return $this->context;
    }

    /** @return list<CarrierCapability> */
    public function capabilities(): array
    {
        return [CarrierCapability::QUOTE];
    }

    /** @param array<string,mixed> $parameters */
    public function quote(array $parameters): CarrierQuote
    {
        return new CarrierQuote(
            $this->context->providerCode,
            $this->context->mode,
            ['quote' => 'local-quote'],
            new CarrierMoney(1250, 'EGP'),
            'redacted://shipping/quote/local-quote',
        );
    }
}
