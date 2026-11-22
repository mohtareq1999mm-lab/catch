<?php

namespace Tests\Feature;

use App\DTOs\CheckoutTotals;
use App\Services\General\OrderService;
use App\Services\Shipping\ShippingQuoteService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Marvel\Database\Models\Cart;
use Marvel\Database\Models\CartItem;
use Marvel\Database\Models\Coupon;
use Marvel\Database\Models\Country;
use Marvel\Database\Models\Governorate;
use Marvel\Database\Models\Product;
use Marvel\Database\Models\ShippingPrice;
use Marvel\Database\Models\User;
use Marvel\Enums\DiscountType;
use Marvel\Enums\ItemType;
use Marvel\Enums\ProductType;
use Tests\TestCase;

class ShippingQuoteServiceTest extends TestCase
{
    use DatabaseTransactions;

    private Country $country;

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('en');

        $this->country = Country::create(['name' => 'Quote Testland', 'status' => true]);
    }

    private function makeGovernorate(float $price, ?float $freeShippingOver = null, bool $status = true): Governorate
    {
        $governorate = Governorate::create([
            'country_id' => $this->country->id,
            'name' => 'Gov-'.Str::upper(Str::random(6)),
            'status' => $status,
        ]);

        ShippingPrice::create([
            'governorate_id' => $governorate->id,
            'price' => $price,
            'free_shipping_over' => $freeShippingOver,
            'status' => true,
        ]);

        return $governorate;
    }

    private function makeCartWithLine(string $itemType, float $lineTotal): Cart
    {
        $user = User::create([
            'name' => 'Quote User',
            'email' => 'quote-'.Str::lower(Str::random(8)).'@example.com',
            'password' => bcrypt('password'),
            'type' => 'user',
            'is_active' => true,
        ]);

        $product = Product::create([
            'name' => 'Quote '.$itemType,
            'slug' => 'quote-'.Str::lower($itemType).'-'.Str::lower(Str::random(8)),
            'price' => $lineTotal,
            'product_type' => ProductType::SIMPLE,
            'item_type' => $itemType,
            'status' => true,
            'in_stock' => true,
            'stock_quantity' => 10,
        ]);

        $cart = Cart::create([
            'user_id' => $user->id,
            'status' => 'active',
            'total_price' => $lineTotal,
        ]);

        CartItem::create([
            'cart_id' => $cart->id,
            'product_id' => $product->id,
            'quantity' => 1,
            'price' => $lineTotal,
            'total_price' => $lineTotal,
            'reserved_quantity' => 0,
            'shipping_method' => 'SCHEDULED',
        ]);

        return $cart;
    }

    /** Golden: base rate equals the established governorate authority result. */
    public function test_quote_uses_governorate_base_rate(): void
    {
        $governorate = $this->makeGovernorate(25.00);

        $quote = app(ShippingQuoteService::class)->quote((int) $governorate->id, 60.00);

        // Established calculation: no threshold, no waiver => stored rate.
        $this->assertSame(25.00, $quote['price']);
        $this->assertSame(25.00, $quote['shipping_amount']);
        $this->assertSame($governorate->id, $quote['governorate_id']);
        $this->assertNull($quote['free_shipping_over']);
        $this->assertSame(1, ShippingPrice::where('governorate_id', $governorate->id)->count());
    }

    /** Golden: a free-shipping coupon zeroes the quoted amount. */
    public function test_quote_zeroes_shipping_for_free_shipping_coupon(): void
    {
        $governorate = $this->makeGovernorate(25.00);

        $quote = app(ShippingQuoteService::class)->quote(
            (int) $governorate->id,
            60.00,
            DiscountType::FREE_SHIPPING,
        );

        // Established precedence: coupon waiver wins over the charged rate.
        $this->assertSame(25.00, $quote['price']);
        $this->assertSame(0.00, $quote['shipping_amount']);
        $this->assertSame(1, ShippingPrice::where('governorate_id', $governorate->id)->count());
    }

    /** Golden: threshold uses strict ">" and cart quotes count physical lines only. */
    public function test_quote_applies_free_shipping_threshold(): void
    {
        $governorate = $this->makeGovernorate(30.00, 100.00);
        $service = app(ShippingQuoteService::class);

        $this->assertSame(30.00, $service->quote((int) $governorate->id, 100.00)['shipping_amount']);
        $this->assertSame(0.00, $service->quote((int) $governorate->id, 100.01)['shipping_amount']);

        $cart = $this->makeCartWithLine(ItemType::PHYSICAL, 60.00);
        $this->makeCartWithLine(ItemType::DIGITAL, 500.00);

        // Physical subtotal is 60 (< 100); the 500 digital line never qualifies.
        $cartQuote = $service->quoteForCart($cart, (int) $governorate->id);
        $this->assertSame(60.00, $cartQuote['subtotal']);
        $this->assertSame(30.00, $cartQuote['shipping_amount']);
    }

    /** Regression #134: the invoice preview must apply the coupon waiver too. */
    public function test_invoice_preview_zeroes_shipping_for_free_shipping_coupon(): void
    {
        $governorate = $this->makeGovernorate(25.00);
        $cart = $this->makeCartWithLine(ItemType::PHYSICAL, 60.00);
        $coupon = Coupon::create([
            'name' => ['en' => 'Preview Free Shipping'],
            'slug' => 'preview-free-shipping-'.Str::lower(Str::random(6)),
            'code' => 'PREVIEW-FS-'.Str::upper(Str::random(6)),
            'discount_type' => DiscountType::FREE_SHIPPING,
            'discount' => 0,
            'status' => true,
            'start_date' => now()->subDay(),
            'end_date' => now()->addMonth(),
        ]);
        $cart->update(['coupon' => $coupon->code]);

        Sanctum::actingAs($cart->user);
        $request = Request::create('/', 'POST', ['governorate_id' => $governorate->id]);
        $request->setUserResolver(fn () => $cart->user);

        $previewTotal = app(OrderService::class)->calcInvoicePrice($request);

        // Without the waiver the authoritative 25.00 rate leaks into the preview.
        $this->assertSame(60.00, round((float) $previewTotal, 2));
        $this->assertSame(60.00, round((float) $cart->fresh()->total_price, 2));
    }

    /** Golden: a zero authoritative rate stays zero; no client price enters the table. */
    public function test_quote_returns_zero_for_zero_rate_authority(): void
    {
        $governorate = $this->makeGovernorate(0.00);

        $quote = app(ShippingQuoteService::class)->quote((int) $governorate->id, 500.00, DiscountType::FREE_SHIPPING);

        $this->assertSame(0.00, $quote['price']);
        $this->assertSame(0.00, $quote['shipping_amount']);
        $this->assertSame(1, ShippingPrice::where('governorate_id', $governorate->id)->count());
    }

    /** Checkout quote consumers delegate to the same authority without changing shapes. */
    public function test_checkout_quote_consumers_match_the_quote_service(): void
    {
        $governorate = $this->makeGovernorate(40.00, 100.00);
        $cart = $this->makeCartWithLine(ItemType::PHYSICAL, 60.00);

        $quoteService = app(ShippingQuoteService::class);
        $orderService = app(OrderService::class);

        $info = $quoteService->governorateShippingInfo((int) $governorate->id);

        $this->assertSame($info, $orderService->getGovernorateShippingInfo((int) $governorate->id));
        $this->assertSame(['price', 'free_shipping_over', 'governorate_id'], array_keys($info));
        $this->assertSame(
            $quoteService->shippingForCart($cart, $info),
            $orderService->resolveShippingChargeForCart($cart, new CheckoutTotals(
                subtotal: 60.00,
                promotionDiscount: 0.0,
                couponDiscount: 0.0,
                finalTotal: 60.00,
            ), $info),
        );
        $this->assertSame(40.00, $quoteService->shippingForCart($cart, $info));
    }
}
