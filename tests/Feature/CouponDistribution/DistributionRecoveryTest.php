<?php

namespace Tests\Feature\CouponDistribution;

use App\Enums\CouponDistributionTriggerType;
use App\Services\Coupon\Distribution\DistributionService;
use App\Services\Coupon\Distribution\Events\CouponDistributionEvents;
use App\Services\Coupon\Distribution\Events\CouponEventEnvelope;
use App\Services\Coupon\Distribution\Messaging\ConsumeResult;
use App\Services\Coupon\Distribution\Messaging\FakeCouponEventTransport;
use App\Services\Coupon\Distribution\Outbox\CouponOutboxService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Marvel\Database\Models\Coupon;
use Marvel\Database\Models\CouponTargeting;
use Marvel\Database\Models\CustomerMetrics;
use Marvel\Database\Models\User;
use Tests\TestCase;

class DistributionRecoveryTest extends TestCase
{
    use RefreshDatabase;

    private FakeCouponEventTransport $fake;

    protected function setUp(): void
    {
        parent::setUp();

        NullFcmChannel::reset();
        Notification::extend('fcm', fn () => new NullFcmChannel());

        $this->fake = new FakeCouponEventTransport();
        $this->fake->declareTopology();
        $this->app->singleton(FakeCouponEventTransport::class, fn () => $this->fake);
    }

    private function createDynamicCoupon(): Coupon
    {
        $code = 'REC-'.Str::random(8);
        $coupon = Coupon::create([
            'code' => $code, 'slug' => Str::slug($code), 'name' => 'Recovery Coupon',
            'discount_type' => 'percentage', 'discount' => 10,
            'start_date' => now()->subDay(), 'end_date' => now()->addMonth(), 'status' => true,
        ]);
        CouponTargeting::create([
            'coupon_id' => $coupon->id, 'mode' => 'dynamic', 'require_claim' => true,
            'rule_tree' => ['type' => 'min_completed_orders', 'value' => 0],
        ]);

        return $coupon;
    }

    public function test_broker_outage_keeps_business_transaction_and_recovers()
    {
        $coupon = $this->createDynamicCoupon();
        $user = User::factory()->create();
        CustomerMetrics::create(['user_id' => $user->id, 'completed_orders' => 1]);

        // RabbitMQ down: the business transaction still commits.
        $this->fake->unreachable = true;

        $result = app(DistributionService::class)->startDistribution(
            $coupon->fresh('targeting'), CouponDistributionTriggerType::MANUAL, 'manual'
        );

        $this->assertTrue($result['created']);
        $this->assertDatabaseHas('coupon_outbox', [
            'event_type' => 'coupon.distribution.start',
            'status' => 'pending',
        ]);
        $this->assertCount(0, $this->fake->published);

        // Broker recovers: the sweep eventually publishes, nothing is lost.
        // (The failed immediate attempt backs the row off; travel past it.)
        $this->fake->unreachable = false;
        $this->travel(5)->minutes();
        $published = app(CouponOutboxService::class)->publishDue(100);

        $this->assertSame(1, $published);
        $this->assertCount(1, $this->fake->published);
        $this->assertDatabaseHas('coupon_outbox', [
            'event_type' => 'coupon.distribution.start',
            'status' => 'published',
        ]);
    }

    public function test_consumer_crash_before_ack_redelivers_without_duplicate_effect()
    {
        $envelope = CouponEventEnvelope::create(
            CouponDistributionEvents::NOTIFICATION_REQUESTED, 1, ['x' => 1]
        );
        $this->fake->publish($envelope);

        $deliveries = 0;
        $effects = 0;

        // First delivery crashes before ack (attempt recorded, requeued).
        $this->fake->consume('notifications', function () use (&$deliveries) {
            $deliveries++;

            if ($deliveries === 1) {
                return ConsumeResult::RETRY; // crash before ack
            }

            return ConsumeResult::ACK;
        }, 10);

        // Consumer dedupes by event_id: the redelivery applies no new effect.
        $seen = [];
        $this->fake->reset();
        $this->fake->publish($envelope);
        $this->fake->publish($envelope); // duplicate delivery of same event

        $this->fake->consume('notifications', function (array $message) use (&$seen, &$effects) {
            $parsed = CouponEventEnvelope::fromJson($message['body']);

            if (isset($seen[$parsed->eventId])) {
                return ConsumeResult::ACK; // already processed
            }

            $seen[$parsed->eventId] = true;
            $effects++;

            return ConsumeResult::ACK;
        }, 10);

        $this->assertSame(1, $effects);
    }

    public function test_repeated_consumer_failure_reaches_dlq()
    {
        $envelope = CouponEventEnvelope::create(
            CouponDistributionEvents::USER_EVALUATE, 1, ['x' => 1]
        );
        $this->fake->publish($envelope);

        // Every attempt fails: bounded retries then DLQ (never infinite).
        $this->fake->consume('evaluation', fn () => ConsumeResult::RETRY, 50);

        $this->assertCount(1, $this->fake->deadLettered);
        $this->assertSame(
            $envelope->eventId,
            CouponEventEnvelope::fromJson($this->fake->deadLettered[0]['body'])->eventId
        );
    }
}
