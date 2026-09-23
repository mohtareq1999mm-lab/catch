<?php

namespace Tests\Feature\CouponDistribution;

use App\Services\Coupon\Distribution\Messaging\FakeCouponEventTransport;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Marvel\Database\Models\Address;
use Marvel\Database\Models\Country;
use Marvel\Database\Models\Coupon;
use Marvel\Database\Models\CouponTargeting;
use Marvel\Database\Models\CustomerMetrics;
use Marvel\Database\Models\Governorate;
use Marvel\Database\Models\User;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class DistributionTriggerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        NullFcmChannel::reset();
        Notification::extend('fcm', fn () => new NullFcmChannel());

        $fake = new FakeCouponEventTransport();
        $this->app->singleton(FakeCouponEventTransport::class, fn () => $fake);
    }

    private function createCoupon(bool $active, ?array $ruleTree): Coupon
    {
        $code = 'TRG-'.Str::random(8);
        $coupon = Coupon::create([
            'code' => $code, 'slug' => Str::slug($code), 'name' => 'Trigger Coupon',
            'discount_type' => 'percentage', 'discount' => 10,
            'start_date' => now()->subDay(), 'end_date' => now()->addMonth(), 'status' => $active,
        ]);

        if ($ruleTree !== null) {
            CouponTargeting::create([
                'coupon_id' => $coupon->id, 'mode' => 'dynamic', 'require_claim' => true,
                'rule_tree' => $ruleTree,
            ]);
        }

        return $coupon;
    }

    public function test_activation_starts_distribution_run()
    {
        $coupon = $this->createCoupon(false, ['type' => 'min_completed_orders', 'value' => 0]);

        $coupon->update(['status' => true]);

        $this->assertDatabaseHas('coupon_distribution_runs', [
            'coupon_id' => $coupon->id,
            'trigger_type' => 'coupon_activated',
        ]);
        $this->assertDatabaseHas('coupon_outbox', [
            'event_type' => 'coupon.distribution.start',
        ]);
    }

    public function test_disable_cancels_inflight_runs()
    {
        $coupon = $this->createCoupon(true, ['type' => 'min_completed_orders', 'value' => 0]);
        $coupon->update(['status' => true]); // activation run (no-op if already active? status unchanged → no event)

        app(\App\Services\Coupon\Distribution\DistributionService::class)->startDistribution(
            $coupon->fresh('targeting'),
            \App\Enums\CouponDistributionTriggerType::MANUAL,
            'manual'
        );

        $coupon->update(['status' => false]);

        $this->assertSame(
            0,
            \App\Models\CouponDistributionRun::query()
                ->where('coupon_id', $coupon->id)
                ->whereIn('status', ['pending', 'running'])
                ->count()
        );
    }

    public function test_address_change_fans_out_only_to_area_coupons()
    {
        $areaCoupon = $this->createCoupon(true, ['type' => 'area_in', 'value' => [999111]]);
        $metricsCoupon = $this->createCoupon(true, ['type' => 'min_completed_orders', 'value' => 0]);

        $country = Country::create(['name' => 'TC', 'status' => true]);
        $gov = Governorate::create([
            'country_id' => $country->id, 'name' => 'Gov', 'status' => true,
            'is_fast_shipping_enabled' => false,
        ]);

        $user = User::factory()->create();
        Address::create([
            'title' => 'Home', 'address' => ['street_address' => 'x'],
            'customer_id' => $user->id, 'governorate_id' => $gov->id,
        ]);

        $this->assertDatabaseHas('coupon_distribution_runs', [
            'coupon_id' => $areaCoupon->id,
            'trigger_type' => 'address_changed',
        ]);
        $this->assertDatabaseMissing('coupon_distribution_runs', [
            'coupon_id' => $metricsCoupon->id,
            'trigger_type' => 'address_changed',
        ]);
    }

    public function test_targeting_upsert_triggers_distribution_via_api()
    {
        $coupon = $this->createCoupon(true, ['type' => 'min_completed_orders', 'value' => 5]);

        $admin = User::factory()->create(['type' => 'admin']);
        Permission::firstOrCreate(['name' => 'update-coupon', 'guard_name' => 'api']);
        $admin->givePermissionTo('update-coupon');
        \Laravel\Sanctum\Sanctum::actingAs($admin);

        $response = $this->putJson("/api/v1/admin/coupons/{$coupon->id}/targeting", [
            'mode' => 'dynamic',
            'require_claim' => true,
            'rule_tree' => ['type' => 'min_completed_orders', 'value' => 0],
        ]);
        $response->assertOk();

        $this->assertDatabaseHas('coupon_distribution_runs', [
            'coupon_id' => $coupon->id,
            'trigger_type' => 'targeting_changed',
        ]);
    }

    public function test_activation_scanner_is_deduplicated()
    {
        $coupon = $this->createCoupon(true, ['type' => 'min_completed_orders', 'value' => 0]);

        Artisan::call('coupons:detect-activations');
        Artisan::call('coupons:detect-activations');

        $this->assertSame(1, \App\Models\CouponDistributionRun::query()
            ->where('coupon_id', $coupon->id)
            ->where('trigger_type', 'coupon_activated')
            ->count());
    }

    public function test_registration_fans_out_only_to_registration_coupons()
    {
        $regCoupon = $this->createCoupon(true, [
            'type' => 'registered_after', 'value' => now()->subYear()->toDateTimeString(),
        ]);
        $otherCoupon = $this->createCoupon(true, ['type' => 'min_completed_orders', 'value' => 0]);

        event(new \Illuminate\Auth\Events\Registered($user = User::factory()->create()));

        $this->assertDatabaseHas('coupon_distribution_runs', [
            'coupon_id' => $regCoupon->id,
            'trigger_type' => 'user_registered',
        ]);
        $this->assertDatabaseMissing('coupon_distribution_runs', [
            'coupon_id' => $otherCoupon->id,
            'trigger_type' => 'user_registered',
        ]);
    }
}
