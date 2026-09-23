<?php

namespace Tests\Feature\CouponDistribution;

use App\Enums\CouponDistributionTriggerType;
use App\Models\CouponDistributionRun;
use App\Services\Coupon\Distribution\DistributionRunService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Marvel\Database\Models\Coupon;
use Tests\TestCase;
use Illuminate\Support\Str;

class DistributionRunServiceTest extends TestCase
{
    use RefreshDatabase;

    private function createCoupon(): Coupon
    {
        $code = 'RUN-'.Str::random(8);

        return Coupon::create([
            'code' => $code,
            'slug' => Str::slug($code),
            'name' => 'Run Coupon',
            'discount_type' => 'percentage',
            'discount' => 10,
            'start_date' => now()->subDay(),
            'end_date' => now()->addMonth(),
            'status' => true,
        ]);
    }

    public function test_start_or_join_creates_run_with_stable_dedupe_key()
    {
        $coupon = $this->createCoupon();
        $service = app(DistributionRunService::class);
        $hash = str_repeat('b', 64);

        [$run, $created] = $service->startOrJoin(
            $coupon->id, $hash, CouponDistributionTriggerType::MANUAL, 'manual'
        );

        $this->assertTrue($created);
        $this->assertSame(
            $coupon->id.':'.$hash.':manual:manual',
            $run->dedupe_key
        );
    }

    public function test_duplicate_start_joins_existing_run()
    {
        $coupon = $this->createCoupon();
        $service = app(DistributionRunService::class);
        $hash = str_repeat('c', 64);

        [$first, $createdFirst] = $service->startOrJoin($coupon->id, $hash, CouponDistributionTriggerType::COUPON_ACTIVATED, 'activation');
        [$second, $createdSecond] = $service->startOrJoin($coupon->id, $hash, CouponDistributionTriggerType::COUPON_ACTIVATED, 'activation');

        $this->assertTrue($createdFirst);
        $this->assertFalse($createdSecond);
        $this->assertSame($first->getKey(), $second->getKey());
        $this->assertSame(1, CouponDistributionRun::query()->count());
    }

    public function test_different_tree_hash_creates_new_run()
    {
        $coupon = $this->createCoupon();
        $service = app(DistributionRunService::class);

        [$first, $createdFirst] = $service->startOrJoin($coupon->id, str_repeat('d', 64), CouponDistributionTriggerType::MANUAL, 'manual');
        [$second, $createdSecond] = $service->startOrJoin($coupon->id, str_repeat('e', 64), CouponDistributionTriggerType::MANUAL, 'manual');

        $this->assertTrue($createdFirst);
        $this->assertTrue($createdSecond);
        $this->assertNotSame($first->getKey(), $second->getKey());
    }

    public function test_reconcile_counts_recipients_by_status()
    {
        $coupon = $this->createCoupon();
        $service = app(DistributionRunService::class);

        [$run] = $service->startOrJoin($coupon->id, str_repeat('f', 64), CouponDistributionTriggerType::MANUAL, 'manual');

        $users = \Marvel\Database\Models\User::factory()->count(5)->create();

        foreach (['eligible', 'notified', 'not_eligible', 'failed_permanent', 'duplicate_skipped'] as $i => $status) {
            \App\Models\CouponDistributionRecipient::query()->create([
                'run_id' => $run->id,
                'coupon_id' => $coupon->id,
                'user_id' => $users[$i]->id,
                'tree_hash' => str_repeat('f', 64),
                'status' => $status,
            ]);
        }

        $counts = $service->reconcile($run->fresh());

        $this->assertSame(5, $counts['candidate_count']);
        $this->assertSame(2, $counts['eligible_count']); // eligible + notified
        $this->assertSame(1, $counts['not_eligible_count']);
        $this->assertSame(1, $counts['notified_count']);
        $this->assertSame(1, $counts['failed_count']);
        $this->assertSame(1, $counts['duplicate_skipped_count']);
    }
}
