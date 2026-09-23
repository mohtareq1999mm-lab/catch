<?php

namespace Tests\Feature\CouponDistribution;

use App\Services\Coupon\Distribution\Events\CouponDistributionEvents;
use App\Services\Coupon\Distribution\Events\CouponEventEnvelope;
use App\Services\Coupon\Distribution\Events\InvalidCouponEventException;
use Tests\TestCase;

class CouponEventEnvelopeTest extends TestCase
{
    public function test_create_builds_valid_envelope()
    {
        $envelope = CouponEventEnvelope::create(
            CouponDistributionEvents::DISTRIBUTION_START,
            aggregateId: 123,
            payload: ['run_id' => 7],
            userId: 456,
            distributionRunId: 7,
            treeHash: str_repeat('a', 64),
        );

        $this->assertTrue(\Illuminate\Support\Str::isUuid($envelope->eventId));
        $this->assertTrue(\Illuminate\Support\Str::isUuid($envelope->correlationId));
        $this->assertNull($envelope->causationId);
        $this->assertSame(1, $envelope->version);
        $this->assertSame(123, $envelope->aggregateId);

        // Round-trip through JSON preserves identity.
        $parsed = CouponEventEnvelope::fromJson($envelope->toJson());
        $this->assertSame($envelope->eventId, $parsed->eventId);
        $this->assertSame($envelope->correlationId, $parsed->correlationId);
    }

    public function test_derive_inherits_correlation_and_points_causation_at_parent()
    {
        $parent = CouponEventEnvelope::create(CouponDistributionEvents::DISTRIBUTION_START, 1);
        $child = $parent->derive(CouponDistributionEvents::DISTRIBUTION_CHUNK, ['x' => 1]);

        $this->assertSame($parent->correlationId, $child->correlationId);
        $this->assertSame($parent->eventId, $child->causationId);
        $this->assertNotSame($parent->eventId, $child->eventId);
    }

    public function test_rejects_unknown_event_type()
    {
        $this->expectException(InvalidCouponEventException::class);

        CouponEventEnvelope::fromArray([
            'event_id' => (string) \Illuminate\Support\Str::uuid(),
            'event_type' => 'coupon.nope',
            'version' => 1,
            'occurred_at' => now()->toIso8601String(),
            'correlation_id' => (string) \Illuminate\Support\Str::uuid(),
            'payload' => [],
        ]);
    }

    public function test_rejects_unsupported_version()
    {
        $this->expectException(InvalidCouponEventException::class);

        CouponEventEnvelope::fromArray([
            'event_id' => (string) \Illuminate\Support\Str::uuid(),
            'event_type' => CouponDistributionEvents::USER_EVALUATE,
            'version' => 999,
            'occurred_at' => now()->toIso8601String(),
            'correlation_id' => (string) \Illuminate\Support\Str::uuid(),
            'payload' => [],
        ]);
    }

    public function test_rejects_missing_correlation_id()
    {
        $this->expectException(InvalidCouponEventException::class);

        CouponEventEnvelope::fromArray([
            'event_id' => (string) \Illuminate\Support\Str::uuid(),
            'event_type' => CouponDistributionEvents::USER_EVALUATE,
            'version' => 1,
            'occurred_at' => now()->toIso8601String(),
            'payload' => [],
        ]);
    }

    public function test_rejects_malformed_json_body()
    {
        $this->expectException(InvalidCouponEventException::class);

        CouponEventEnvelope::fromJson('{not json');
    }

    public function test_rejects_non_uuid_event_id()
    {
        $this->expectException(InvalidCouponEventException::class);

        CouponEventEnvelope::fromArray([
            'event_id' => 'not-a-uuid',
            'event_type' => CouponDistributionEvents::USER_EVALUATE,
            'version' => 1,
            'occurred_at' => now()->toIso8601String(),
            'correlation_id' => (string) \Illuminate\Support\Str::uuid(),
            'payload' => [],
        ]);
    }
}
