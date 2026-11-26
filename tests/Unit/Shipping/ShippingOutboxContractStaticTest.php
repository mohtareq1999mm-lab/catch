<?php

declare(strict_types=1);

namespace Tests\Unit\Shipping;

use App\Enums\Shipping\ShippingOutboxOperationType;
use App\Services\Shipment\Outbox\ShippingOutboxOperation;
use App\Services\Shipment\Outbox\ShippingOutboxPayload;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use ValueError;

/** #184 static contract checks: taxonomy, key shape, redaction, additive schema. */
class ShippingOutboxContractStaticTest extends TestCase
{
    public function test_taxonomy_is_the_fixed_launch_enumeration(): void
    {
        $expected = [
            'provider.create_shipment',
            'provider.cancel_shipment',
            'provider.fetch_label',
            'provider.sync_tracking',
            'notification.send',
            'poll.run',
            'settlement.import',
        ];

        $actual = array_map(static fn ($case) => $case->value, ShippingOutboxOperationType::cases());

        $this->assertSame($expected, $actual);
        $this->assertCount(count($expected), array_unique($actual));
    }

    public function test_operation_key_is_deterministic_and_version_scoped(): void
    {
        $first = new ShippingOutboxOperation(
            ShippingOutboxOperationType::PROVIDER_CREATE_SHIPMENT,
            'shipment',
            '42',
            'req-1',
            1,
            'carrier',
            'sandbox',
            ['shipment_id' => 42],
        );

        $sameRequest = new ShippingOutboxOperation(
            'provider.create_shipment',
            'shipment',
            '42',
            'req-1',
            1,
            'carrier',
            'sandbox',
            ['shipment_id' => 42],
        );

        $newRequest = new ShippingOutboxOperation(
            ShippingOutboxOperationType::PROVIDER_CREATE_SHIPMENT,
            'shipment',
            '42',
            'req-2',
            2,
        );

        $key = 'provider.create_shipment:shipment:42:1';

        $this->assertSame($key, $first->idempotencyKey);
        $this->assertSame($key, $first->operationKey());
        $this->assertSame($key, $sameRequest->idempotencyKey);
        $this->assertSame('provider.create_shipment:shipment:42:2', $newRequest->idempotencyKey);
        $this->assertLessThanOrEqual(191, strlen($key));
    }

    public function test_unknown_operation_is_rejected(): void
    {
        $this->expectException(ValueError::class);

        new ShippingOutboxOperation('carrier.create_shipment', 'shipment', '42', 'req-1');
    }

    public function test_invalid_aggregate_type_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new ShippingOutboxOperation(ShippingOutboxOperationType::NOTIFICATION_SEND, ' shipment', '42', 'req-1');
    }

    public function test_aggregate_id_cannot_contain_the_key_separator(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new ShippingOutboxOperation(ShippingOutboxOperationType::NOTIFICATION_SEND, 'shipment', '42:extra', 'req-1');
    }

    public function test_payload_is_redacted_to_identifiers_only(): void
    {
        $operation = new ShippingOutboxOperation(
            ShippingOutboxOperationType::PROVIDER_SYNC_TRACKING,
            'shipment',
            '42',
            'req-1',
            1,
            null,
            null,
            [
                'shipment_id' => 42,
                'provider_shipment_id' => 'ext-abc_123',
                'user_email' => 'customer@example.test',
                'phone' => '+201234567890',
                'price' => 12.5,
                'api_key' => 'secret',
                'meta' => ['order_id' => 7],
            ],
        );

        $this->assertSame([
            'provider_shipment_id' => 'ext-abc_123',
            'shipment_id' => 42,
        ], $operation->payload);

        ShippingOutboxPayload::assertIdentifiersOnly($operation->payload);
    }

    public function test_assert_identifiers_only_rejects_business_data(): void
    {
        $this->expectException(InvalidArgumentException::class);

        ShippingOutboxPayload::assertIdentifiersOnly(['amount' => 10]);
    }

    public function test_contact_like_aggregate_identifier_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new ShippingOutboxOperation(
            ShippingOutboxOperationType::NOTIFICATION_SEND,
            'shipment',
            'customer@example.test',
            'req-1',
        );
    }

    public function test_unsafe_authorized_request_identifier_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new ShippingOutboxOperation(
            ShippingOutboxOperationType::NOTIFICATION_SEND,
            'shipment',
            '42',
            "req\n1",
        );
    }
}
