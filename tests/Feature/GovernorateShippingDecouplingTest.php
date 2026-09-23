<?php

namespace Tests\Feature;

use App\Services\Coupon\Eligibility\EligibilityEngine;
use App\Services\General\OrderService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Marvel\Database\Models\Address;
use Marvel\Database\Models\Country;
use Marvel\Database\Models\Coupon;
use Marvel\Database\Models\CouponTargeting;
use Marvel\Database\Models\Governorate;
use Marvel\Database\Models\Order;
use Marvel\Database\Models\ShippingPrice;
use Marvel\Database\Models\User;
use Tests\TestCase;

/**
 * Governorate/shipping decoupling (Cases A–F).
 *
 * Canonical guarantees under test:
 * - address.governorate_id validity never depends on shipping;
 * - resolveShippingPrice keeps existent ids (snapshot truth), zeroes price when unshippable;
 * - coupon area_in reads addresses + active governorates only (shipping toggles change nothing);
 * - NULL addresses fail closed; order snapshots are immutable.
 */
class GovernorateShippingDecouplingTest extends TestCase
{
    use RefreshDatabase;

    private Country $country;

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('en');
        $this->country = Country::create(['name' => 'Testland']);
    }

    private function makeGovernorate(string $name, bool $status = true): Governorate
    {
        return Governorate::create([
            'country_id' => $this->country->id,
            'name' => $name.'-'.Str::random(4),
            'status' => $status,
        ]);
    }

    private function makeShipping(Governorate $gov, float $price, bool $status = true): ShippingPrice
    {
        return ShippingPrice::create([
            'governorate_id' => $gov->id,
            'price' => $price,
            'status' => $status,
        ]);
    }

    private function makeUser(): User
    {
        return User::factory()->create();
    }

    private function makeAddress(User $user, ?int $governorateId): Address
    {
        return Address::create([
            'title' => 'Home',
            'address' => ['zip' => '1', 'city' => 'C', 'state' => 'S', 'country' => 'T', 'street_address' => '1 St'],
            'customer_id' => $user->id,
            'governorate_id' => $governorateId,
        ]);
    }

    private function makeAreaCoupon(Governorate $gov): Coupon
    {
        $code = 'AREA-'.Str::upper(Str::random(6));
        $coupon = Coupon::create([
            'code' => $code,
            'slug' => Str::slug($code),
            'name' => 'Area coupon',
            'discount_type' => 'percentage',
            'discount' => 10,
            'start_date' => now()->subDay(),
            'end_date' => now()->addMonth(),
            'status' => true,
        ]);
        CouponTargeting::create([
            'coupon_id' => $coupon->id,
            'mode' => 'dynamic',
            'rule_tree' => ['operator' => 'AND', 'rules' => [['type' => 'area_in', 'value' => [$gov->id]]]],
        ]);

        return $coupon->fresh();
    }

    /** Case A: enabled + priced ⇒ price + id. */
    public function test_enabled_governorate_resolves_price_and_id(): void
    {
        $gov = $this->makeGovernorate('Cairo');
        $this->makeShipping($gov, 25.00);

        $info = app(OrderService::class)->getGovernorateShippingInfo($gov->id);

        $this->assertEquals(25.00, $info['price']);
        $this->assertEquals($gov->id, $info['governorate_id']);
    }

    /** Case B: shipping disabled / missing ⇒ price 0 but id KEPT (the decoupling fix). */
    public function test_unshippable_governorate_keeps_id_with_zero_price(): void
    {
        $disabledRow = $this->makeGovernorate('Giza');
        $this->makeShipping($disabledRow, 60.00, false);

        $noRow = $this->makeGovernorate('Aswan');

        $inactive = $this->makeGovernorate('Dead', false);

        foreach ([$disabledRow, $noRow, $inactive] as $gov) {
            $info = app(OrderService::class)->getGovernorateShippingInfo($gov->id);
            $this->assertEquals(0, $info['price'], "price for gov {$gov->id}");
            $this->assertEquals($gov->id, $info['governorate_id'], "id kept for gov {$gov->id}");
        }

        // Nonexistent id: FK safety — null triple unchanged.
        $missing = app(OrderService::class)->getGovernorateShippingInfo(999999999);
        $this->assertEquals(0, $missing['price']);
        $this->assertNull($missing['governorate_id']);

        // Null input unchanged.
        $null = app(OrderService::class)->getGovernorateShippingInfo(null);
        $this->assertNull($null['governorate_id']);
    }

    /** Case B (address): shipping-disabled governorate is a valid address selection. */
    public function test_address_accepts_shipping_disabled_governorate(): void
    {
        $gov = $this->makeGovernorate('Giza');
        $this->makeShipping($gov, 60.00, false);

        $validator = \Illuminate\Support\Facades\Validator::make(
            [
                'title' => 'Home',
                'address' => ['zip' => '1', 'city' => 'C', 'state' => 'S', 'country' => 'T', 'street_address' => '1 St'],
                'governorate_id' => $gov->id,
            ],
            (new \Marvel\Http\Requests\AddressRequest())->rules()
        );

        $this->assertFalse($validator->fails());

        $address = $this->makeAddress($this->makeUser(), $gov->id);
        $this->assertEquals($gov->id, $address->fresh()->governorate_id);
    }

    /** Case C: price change affects shipping only — address + coupon verdict untouched. */
    public function test_shipping_price_change_does_not_affect_address_or_coupon(): void
    {
        $gov = $this->makeGovernorate('Cairo');
        $shipping = $this->makeShipping($gov, 25.00);
        $user = $this->makeUser();
        $this->makeAddress($user, $gov->id);
        $coupon = $this->makeAreaCoupon($gov);
        $engine = app(EligibilityEngine::class);

        $this->assertTrue($engine->evaluate($coupon, $user)->isEligible);

        $shipping->update(['price' => 99.99]);

        $this->assertEquals($gov->id, Address::where('customer_id', $user->id)->first()->governorate_id);
        $this->assertTrue($engine->evaluate($coupon, $user)->isEligible);
        $this->assertEquals(99.99, app(OrderService::class)->getGovernorateShippingInfo($gov->id)['price']);
    }

    /** Case D: enable→disable — address stays valid, area_in still matches, shipping zeroes. */
    public function test_disabling_shipping_keeps_address_and_coupon_eligibility(): void
    {
        $gov = $this->makeGovernorate('Cairo');
        $shipping = $this->makeShipping($gov, 25.00);
        $user = $this->makeUser();
        $this->makeAddress($user, $gov->id);
        $coupon = $this->makeAreaCoupon($gov);
        $engine = app(EligibilityEngine::class);

        $this->assertTrue($engine->evaluate($coupon, $user)->isEligible);

        $shipping->update(['status' => false]);

        $this->assertEquals($gov->id, Address::where('customer_id', $user->id)->first()->governorate_id);
        $this->assertTrue($engine->evaluate($coupon, $user)->isEligible);
        $info = app(OrderService::class)->getGovernorateShippingInfo($gov->id);
        $this->assertEquals(0, $info['price']);
        $this->assertEquals($gov->id, $info['governorate_id']);
    }

    /** Case E: NULL address governorate ⇒ area_in fails closed. */
    public function test_null_address_governorate_fails_area_closed(): void
    {
        $gov = $this->makeGovernorate('Cairo');
        $user = $this->makeUser();
        $this->makeAddress($user, null);
        $coupon = $this->makeAreaCoupon($gov);

        $this->assertFalse(app(EligibilityEngine::class)->evaluate($coupon, $user)->isEligible);
    }

    /** Case F: order snapshot immutable across later address changes. */
    public function test_order_snapshot_survives_address_change(): void
    {
        $cairo = $this->makeGovernorate('Cairo');
        $giza = $this->makeGovernorate('Giza');
        $user = $this->makeUser();
        $address = $this->makeAddress($user, $cairo->id);

        $order = Order::create([
            'order_number' => 'GOV-'.Str::upper(Str::random(8)),
            'user_id' => $user->id,
            'name' => 'Test User',
            'user_phone' => '01000000000',
            'user_email' => 'test@example.com',
            'address' => json_encode(['street' => '1 St']),
            'total_price' => 100,
            'price' => 100,
            'governorate_id' => $cairo->id,
            'status' => 'pending',
        ]);

        $address->update(['governorate_id' => $giza->id]);

        $this->assertEquals($cairo->id, $order->fresh()->governorate_id);
        $this->assertEquals($giza->id, $address->fresh()->governorate_id);
    }
}
