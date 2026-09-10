<?php

namespace Tests\Unit;

use App\DTOs\FrontendCachePayload;
use App\Enums\FrontendResource;
use Tests\TestCase;

class FrontendCachePayloadTest extends TestCase
{
    /** @test */
    public function it_creates_payload_with_resource()
    {
        $payload = new FrontendCachePayload(
            resource: FrontendResource::PRODUCTS,
        );

        $this->assertEquals(FrontendResource::PRODUCTS, $payload->resource);
    }

    /** @test */
    public function it_generates_uuid_as_request_id()
    {
        $payload = new FrontendCachePayload(
            resource: FrontendResource::CATEGORIES,
        );

        $this->assertNotNull($payload->requestId);
        $this->assertTrue(str()->isUuid($payload->requestId));
    }

    /** @test */
    public function it_accepts_custom_request_id()
    {
        $payload = new FrontendCachePayload(
            resource: FrontendResource::BRANDS,
            requestId: 'custom-id-456',
        );

        $this->assertEquals('custom-id-456', $payload->requestId);
    }

    /** @test */
    public function it_generates_iso8601_timestamp()
    {
        $payload = new FrontendCachePayload(
            resource: FrontendResource::PROMOTIONS,
        );

        $this->assertNotNull($payload->occurredAt);
        $this->assertNotFalse(now()->parse($payload->occurredAt));
    }

    /** @test */
    public function it_accepts_custom_occurred_at()
    {
        $payload = new FrontendCachePayload(
            resource: FrontendResource::SETTINGS,
            occurredAt: '2026-07-30T09:00:00+00:00',
        );

        $this->assertEquals('2026-07-30T09:00:00+00:00', $payload->occurredAt);
    }

    /** @test */
    public function to_array_returns_correct_structure()
    {
        $payload = new FrontendCachePayload(
            resource: FrontendResource::PRODUCTS,
            requestId: 'req-1',
            occurredAt: '2026-07-30T10:00:00+00:00',
        );

        $array = $payload->toArray();

        $this->assertIsArray($array);
        $this->assertEquals(1, $array['version']);
        $this->assertEquals('req-1', $array['request_id']);
        $this->assertEquals('products', $array['resource']);
        $this->assertEquals('2026-07-30T10:00:00+00:00', $array['occurred_at']);
    }

    /** @test */
    public function to_array_has_exactly_four_fields()
    {
        $payload = new FrontendCachePayload(
            resource: FrontendResource::FLASH_SALES,
        );

        $array = $payload->toArray();

        $this->assertCount(4, $array);
        $this->assertArrayHasKey('version', $array);
        $this->assertArrayHasKey('request_id', $array);
        $this->assertArrayHasKey('resource', $array);
        $this->assertArrayHasKey('occurred_at', $array);
    }

    /** @test */
    public function to_array_does_not_include_event_or_action()
    {
        $payload = new FrontendCachePayload(
            resource: FrontendResource::COUPONS,
        );

        $array = $payload->toArray();

        $this->assertArrayNotHasKey('event', $array);
        $this->assertArrayNotHasKey('action', $array);
        $this->assertArrayNotHasKey('data', $array);
    }
}
