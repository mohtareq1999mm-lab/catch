<?php

declare(strict_types=1);

namespace Tests\Unit\Shipping;

use App\DTOs\Shipping\CarrierMoney;
use App\DTOs\Shipping\CarrierQuote;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

class CarrierResultDtoTest extends TestCase
{
    public function test_quote_carries_identity_external_ids_minor_units_and_redacted_reference(): void
    {
        $quote = new CarrierQuote(
            'carrier',
            'sandbox',
            ['quote' => 'quote-123'],
            new CarrierMoney(1250, 'EGP'),
            'redacted://shipping/quote/quote-123',
        );

        $this->assertSame('carrier', $quote->providerCode);
        $this->assertSame('sandbox', $quote->mode);
        $this->assertSame(['quote' => 'quote-123'], $quote->externalIds);
        $this->assertSame(1250, $quote->amount?->amountMinor);
        $this->assertSame('EGP', $quote->amount?->currency);
        $this->assertSame('redacted://shipping/quote/quote-123', $quote->redactedPayloadReference);
        $this->assertFalse(property_exists($quote, 'rawPayload'));
    }

    public function test_money_rejects_invalid_currency(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new CarrierMoney(100, 'egp');
    }

    public function test_result_rejects_missing_external_ids(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new CarrierQuote('carrier', 'sandbox', [], null, 'redacted://shipping/quote/x');
    }

    public function test_result_rejects_empty_redaction_reference(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new CarrierQuote('carrier', 'sandbox', ['quote' => 'q'], null, '');
    }
}
