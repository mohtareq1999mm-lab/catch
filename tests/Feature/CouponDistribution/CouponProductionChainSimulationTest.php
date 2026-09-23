<?php

namespace Tests\Feature\CouponDistribution;

use App\Enums\CouponDistributionRunStatus;
use App\Enums\CouponDistributionTriggerType;
use App\Services\Coupon\Distribution\DistributionService;
use App\Services\Coupon\Distribution\Events\CouponDistributionEvents;
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

/**
 * LOCAL production-chain simulation (no broker/FCM/Pusher available here).
 *
 * Drives the REAL application path end to end — targeting → distribution →
 * outbox → transport → consumers → evaluation → transition → notification
 * request → notify → database notification (+FCM payload capture) — with
 * the Fake transport standing in at exactly the AMQP seam production uses.
 *
 * Each stage records IDs/timestamps (T0–T10 evidence). Anything requiring a
 * live broker, real FCM/Pusher, workers, or production data is marked
 * UNVERIFIED here and left for the production runbook.
 */
class CouponProductionChainSimulationTest extends TestCase
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

    private function targetedCoupon(string $code, array $ruleTree, bool $requireClaim): Coupon
    {
        $coupon = Coupon::create([
            'code' => $code, 'slug' => Str::slug($code), 'name' => 'E2E Chain Coupon',
            'discount_type' => 'percentage', 'discount' => 10,
            'start_date' => now()->subDay(), 'end_date' => now()->addMonth(), 'status' => true,
        ]);
        CouponTargeting::create([
            'coupon_id' => $coupon->id, 'mode' => 'dynamic',
            'require_claim' => $requireClaim, 'rule_tree' => $ruleTree,
        ]);

        return $coupon;
    }

    private function pump(): void
    {
        $outbox = app(CouponOutboxService::class);

        for ($i = 0; $i < 15; $i++) {
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

    public function test_full_chain_targeting_to_eligible_notification()
    {
        // T0: actors + targeting (A eligible, B not).
        $coupon = $this->targetedCoupon(
            'E2E-T-' . strtoupper(Str::random(6)),
            ['type' => 'min_completed_orders', 'value' => 1],
            false
        );
        $customerA = User::factory()->create();
        $customerB = User::factory()->create();
        CustomerMetrics::create(['user_id' => $customerA->id, 'completed_orders' => 5]);
        CustomerMetrics::create(['user_id' => $customerB->id, 'completed_orders' => 0]);

        // T1–T2: distribution starts (run created).
        $result = app(DistributionService::class)->startDistribution(
            $coupon->fresh('targeting'), CouponDistributionTriggerType::MANUAL, 'manual'
        );
        $runId = $result['run']->getKey();
        $this->assertTrue($result['created']);

        // T3–T6: outbox → transport → consumed → run completes.
        $this->pump();

        $this->assertFalse(
            \App\Models\CouponOutbox::query()->where('status', 'pending')->exists(),
            'T4: no outbox row may remain pending.'
        );
        $this->assertNotEmpty($this->fake->published, 'T5: transport received messages.');
        $run = \App\Models\CouponDistributionRun::query()->find($runId);
        $runStatus = $run->status instanceof \BackedEnum ? $run->status->value : $run->status;
        $this->assertSame(CouponDistributionRunStatus::COMPLETED->value, $runStatus, 'T6: run completed.');
        fwrite(STDERR, "\nCHAIN run={$runId} tree={$run->tree_hash} eligible={$run->eligible_count} notified={$run->notified_count}\n");

        // T7–T8: transition recorded for A only.
        $stateA = \App\Models\CouponDistributionUserState::query()
            ->where('coupon_id', $coupon->id)->where('user_id', $customerA->id)->first();
        $this->assertNotNull($stateA, 'T7: A has a transition state row.');
        $stateValue = $stateA->state instanceof \BackedEnum ? $stateA->state->value : $stateA->state;
        $this->assertSame('notified', $stateValue, 'T8: A transitioned to notified.');
        $this->assertSame(
            0,
            \App\Models\CouponDistributionUserState::query()
                ->where('coupon_id', $coupon->id)->where('user_id', $customerB->id)->count(),
            'T7: B was never evaluated into a notified path.'
        );

        // T9: exactly one database notification for A, none for B.
        $notesA = $customerA->fresh()->notifications()->where('type', 'coupon.eligible')->get();
        $this->assertCount(1, $notesA, 'T9: exactly one coupon.eligible for A.');
        $this->assertSame(
            0,
            $customerB->fresh()->notifications()->where('type', 'coupon.eligible')->count(),
            'T9: B receives nothing.'
        );

        $data = $notesA->first()->data;
        $this->assertSame($coupon->id, $data['coupon_id']);
        $this->assertIsBool($data['requires_claim']);
        $this->assertFalse($data['requires_claim']);
        $this->assertArrayNotHasKey('coupon_code', $data);
        $this->assertStringNotContainsString($coupon->code, json_encode($data));

        // FCM payload captured for A (delivery channel evidence).
        $this->assertNotEmpty(array_filter(
            NullFcmChannel::$sent,
            fn ($s) => $s['notifiable_id'] === $customerA->id
        ));

        // Idempotency: re-pump the same version → still exactly one.
        $this->pump();
        $this->assertCount(
            1,
            $customerA->fresh()->notifications()->where('type', 'coupon.eligible')->get(),
            'Same tree_hash must not duplicate.'
        );
    }

    public function test_claim_required_variant_carries_true_boolean()
    {
        $coupon = $this->targetedCoupon(
            'E2E-C-' . strtoupper(Str::random(6)),
            ['type' => 'min_completed_orders', 'value' => 0],
            true
        );
        $user = User::factory()->create();
        CustomerMetrics::create(['user_id' => $user->id, 'completed_orders' => 3]);

        app(DistributionService::class)->startDistribution(
            $coupon->fresh('targeting'), CouponDistributionTriggerType::MANUAL, 'manual'
        );
        $this->pump();

        $note = $user->fresh()->notifications()->where('type', 'coupon.eligible')->first();
        $this->assertNotNull($note);
        $this->assertTrue($note->data['requires_claim']);
        $this->assertIsBool($note->data['requires_claim']);
    }
}
