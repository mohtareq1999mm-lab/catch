<?php

namespace Tests\Feature\CouponDistribution;

use App\Enums\CouponDistributionTriggerType;
use App\Services\Coupon\Distribution\Consumers\NotificationRequestHandler;
use App\Services\Coupon\Distribution\DistributionService;
use App\Services\Coupon\Distribution\Events\CouponDistributionEvents;
use App\Services\Coupon\Distribution\Events\CouponEventEnvelope;
use App\Services\Coupon\Distribution\Messaging\FakeCouponEventTransport;
use App\Services\Coupon\Distribution\Outbox\CouponOutboxService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Marvel\Database\Models\Coupon;
use Marvel\Database\Models\CouponTargeting;
use Marvel\Database\Models\CustomerMetrics;
use Marvel\Database\Models\User;
use Tests\TestCase;

class NotificationPipelineTest extends TestCase
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

    private function createDynamicCoupon(array $ruleTree): Coupon
    {
        $code = 'NOTIF-'.Str::random(8);
        $coupon = Coupon::create([
            'code' => $code, 'slug' => Str::slug($code), 'name' => 'Notify Coupon',
            'discount_type' => 'percentage', 'discount' => 10,
            'start_date' => now()->subDay(), 'end_date' => now()->addMonth(), 'status' => true,
        ]);
        CouponTargeting::create([
            'coupon_id' => $coupon->id, 'mode' => 'dynamic', 'require_claim' => true,
            'rule_tree' => $ruleTree,
        ]);

        return $coupon;
    }

    private function pump(): void
    {
        $outbox = app(CouponOutboxService::class);

        for ($i = 0; $i < 12; $i++) {
            $outbox->publishDue(200);

            foreach (['distribution', 'evaluation', 'notifications'] as $queue) {
                Artisan::call('coupon:consume', [
                    '--queue' => $queue, '--max-messages' => 50, '--max-seconds' => 20,
                ]);
            }

            $pending = \App\Models\CouponOutbox::query()
                ->where('status', 'pending')->where('available_at', '<=', now())->exists();
            $queued = array_sum(array_map('count', $this->fake->queues));

            if (! $pending && $queued === 0) {
                break;
            }
        }
    }

    public function test_notification_carries_no_code_and_reaches_owner_channel()
    {
        $coupon = $this->createDynamicCoupon(['type' => 'min_completed_orders', 'value' => 0]);
        $user = User::factory()->create();
        CustomerMetrics::create(['user_id' => $user->id, 'completed_orders' => 1]);

        app(DistributionService::class)->startDistribution(
            $coupon->fresh('targeting'), CouponDistributionTriggerType::MANUAL, 'manual'
        );
        $this->pump();

        $rows = $user->fresh()->notifications()->where('type', 'coupon.eligible')->get();
        $this->assertCount(1, $rows);

        $raw = json_encode($rows->first()->data);
        $this->assertStringNotContainsString($coupon->code, $raw);

        // FCM got a payload for this user (delivery channel, not truth).
        $this->assertNotEmpty(array_filter(
            NullFcmChannel::$sent,
            fn ($s) => $s['notifiable_id'] === $user->id
        ));
    }

    public function test_redelivered_notification_request_does_not_duplicate()
    {
        $coupon = $this->createDynamicCoupon(['type' => 'min_completed_orders', 'value' => 0]);
        $user = User::factory()->create();
        CustomerMetrics::create(['user_id' => $user->id, 'completed_orders' => 1]);

        $result = app(DistributionService::class)->startDistribution(
            $coupon->fresh('targeting'), CouponDistributionTriggerType::MANUAL, 'manual'
        );
        $this->pump();

        $run = $result['run']->fresh();
        $recipient = \App\Models\CouponDistributionRecipient::query()
            ->where('run_id', $run->id)->where('user_id', $user->id)->firstOrFail();

        // Simulate transport redelivery of the same notification.requested.
        $envelope = CouponEventEnvelope::create(
            CouponDistributionEvents::NOTIFICATION_REQUESTED,
            $coupon->id,
            [
                'run_id' => $run->id,
                'recipient_id' => $recipient->id,
                'coupon_id' => $coupon->id,
                'user_id' => $user->id,
                'tree_hash' => $run->tree_hash,
            ],
            correlationId: $run->id.'-00000000-0000-4000-8000-000000000000',
            userId: $user->id,
        );

        app(NotificationRequestHandler::class)->handle($envelope);

        $this->assertCount(1, $user->fresh()->notifications()->where('type', 'coupon.eligible')->get());
        $this->assertSame(1, (int) $run->fresh()->notified_count);
    }

    public function test_fcm_failure_keeps_database_notification()
    {
        Notification::extend('fcm', fn () => new class {
            public function send($notifiable, $notification): void
            {
                throw new \RuntimeException('push provider down');
            }
        });

        $coupon = $this->createDynamicCoupon(['type' => 'min_completed_orders', 'value' => 0]);
        $user = User::factory()->create();
        CustomerMetrics::create(['user_id' => $user->id, 'completed_orders' => 1]);

        app(DistributionService::class)->startDistribution(
            $coupon->fresh('targeting'), CouponDistributionTriggerType::MANUAL, 'manual'
        );
        $this->pump();

        // Channels are independent: the durable row survives a dead push
        // provider (failure is contained to the fcm channel send).
        $this->assertCount(1, $user->fresh()->notifications()->where('type', 'coupon.eligible')->get());
    }

    public function test_other_users_cannot_see_my_eligibility_notification()
    {
        $coupon = $this->createDynamicCoupon(['type' => 'min_completed_orders', 'value' => 0]);
        $user = User::factory()->create();
        $stranger = User::factory()->create();
        CustomerMetrics::create(['user_id' => $user->id, 'completed_orders' => 1]);

        app(DistributionService::class)->startDistribution(
            $coupon->fresh('targeting'), CouponDistributionTriggerType::MANUAL, 'manual'
        );
        $this->pump();

        $this->assertSame(0, $stranger->fresh()->notifications()->where('type', 'coupon.eligible')->count());
        $this->assertEmpty(array_filter(
            NullFcmChannel::$sent,
            fn ($s) => $s['notifiable_id'] === $stranger->id
        ));
    }
}
