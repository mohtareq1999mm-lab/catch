<?php

namespace Tests\Unit\Guest;

use App\Exceptions\GuestCartRejectionException;
use App\Models\GuestIdentity;
use App\Services\Guest\GuestCartService;
use App\Services\Guest\GuestIdentityService;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Marvel\Database\Models\Product;
use Marvel\Database\Models\ProductVariant;
use Marvel\Enums\ItemType;
use Marvel\Enums\ProductType;
use Tests\Feature\Guest\GuestCheckoutTestCase;

/**
 * Add-time guest Eligibility rules (T17 / S11 / D2).
 *
 * Drives the guest cart service directly (same seam as the HTTP controller
 * uses) and asserts the machine-readable rejection reason for each rule.
 */
class GuestEligibilityTest extends GuestCheckoutTestCase
{
    private GuestCartService $cartService;

    protected function setUp(): void
    {
        parent::setUp();

        $this->cartService = app(GuestCartService::class);
    }

    private function guest(): GuestIdentity
    {
        return app(GuestIdentityService::class)
            ->createForMobile(Request::create('/api/v1/general/guest/mobile/session', 'POST'))[0];
    }

    private function variant(Product $product, array $overrides = []): ProductVariant
    {
        $variant = ProductVariant::create(array_merge([
            'product_id' => $product->id,
            'sku' => 'GUEST-' . Str::random(8),
            'price' => 100,
            'stock_quantity' => 10,
            'reserved_quantity' => 0,
            'in_stock' => true,
        ], $overrides));

        return $variant->refresh();
    }

    private function variableProduct(array $overrides = []): Product
    {
        return $this->makeProduct(array_merge([
            'product_type' => ProductType::VARIABLE,
            'stock_quantity' => 100,
        ], $overrides));
    }

    private function assertRejected(string $reason, array $item, ?Product $product = null): void
    {
        $item = array_merge([
            'product_id' => ($product ?? $this->product)->id,
            'quantity' => 1,
            'shipping_method' => 'scheduled',
        ], $item);

        try {
            $this->cartService->addItem(Request::create('/x', 'POST'), $this->guest(), $item);
            $this->fail('Expected the item to be rejected with reason ' . $reason);
        } catch (GuestCartRejectionException $e) {
            $this->assertSame($reason, $e->reason());
        }
    }

    public function test_inactive_product_is_rejected(): void
    {
        $inactive = $this->makeProduct(['status' => false]);

        $this->assertRejected(GuestCartRejectionException::PRODUCT_UNAVAILABLE, [], $inactive);
    }

    public function test_digital_product_is_rejected(): void
    {
        $digital = $this->makeProduct(['item_type' => ItemType::DIGITAL]);

        $this->assertRejected(GuestCartRejectionException::DIGITAL_NOT_SUPPORTED, [], $digital);
    }

    public function test_fast_shipping_method_is_rejected(): void
    {
        $this->assertRejected(
            GuestCartRejectionException::FAST_SHIPPING_NOT_SUPPORTED,
            ['shipping_method' => 'fast']
        );
    }

    public function test_variable_product_requires_a_variant(): void
    {
        $product = $this->variableProduct();

        $this->assertRejected(GuestCartRejectionException::VARIANT_REQUIRED, [], $product);
    }

    public function test_variant_from_another_product_is_rejected(): void
    {
        $product = $this->variableProduct();
        $foreign = $this->variableProduct();
        $foreignVariant = $this->variant($foreign);

        $this->assertRejected(
            GuestCartRejectionException::VARIANT_UNAVAILABLE,
            ['product_variant_id' => $foreignVariant->id],
            $product
        );
    }

    public function test_fully_reserved_variant_is_rejected_as_insufficient_stock(): void
    {
        // Approved S11: with known variant stock, `quantity > stock - reserved`
        // fires first, so a fully reserved variant is insufficient_stock.
        $product = $this->variableProduct();
        $variant = $this->variant($product, ['stock_quantity' => 5, 'reserved_quantity' => 5]);
        $variant->forceFill(['in_stock' => false])->save();

        $this->assertRejected(
            GuestCartRejectionException::INSUFFICIENT_STOCK,
            ['product_variant_id' => $variant->id],
            $product
        );
    }

    public function test_zero_stock_variant_keeps_unlimited_semantics(): void
    {
        // Approved S11: both variant gates are gated on stock > 0, so a
        // variant with stock_quantity = 0 keeps the documented
        // unlimited/unknown semantics (product-level parity).
        $product = $this->variableProduct();
        $variant = $this->variant($product, ['stock_quantity' => 0]);

        $cart = $this->cartService->addItem(
            Request::create('/x', 'POST'),
            $this->guest(),
            [
                'product_id' => $product->id,
                'product_variant_id' => $variant->id,
                'quantity' => 3,
                'shipping_method' => 'scheduled',
            ]
        );

        $this->assertSame(3, (int) $cart->items()->first()->quantity);
    }

    public function test_quantity_above_variant_stock_is_rejected(): void
    {
        $product = $this->variableProduct();
        $variant = $this->variant($product, ['stock_quantity' => 5]);

        $this->assertRejected(
            GuestCartRejectionException::INSUFFICIENT_STOCK,
            ['product_variant_id' => $variant->id, 'quantity' => 6],
            $product
        );
    }

    public function test_reserved_variant_quantity_reduces_availability(): void
    {
        $product = $this->variableProduct();
        $variant = $this->variant($product, ['stock_quantity' => 5, 'reserved_quantity' => 5]);

        $this->assertRejected(
            GuestCartRejectionException::INSUFFICIENT_STOCK,
            ['product_variant_id' => $variant->id, 'quantity' => 1],
            $product
        );
    }

    public function test_quantity_above_product_stock_is_rejected(): void
    {
        $product = $this->makeProduct(['stock_quantity' => 5]);

        $this->assertRejected(
            GuestCartRejectionException::INSUFFICIENT_STOCK,
            ['quantity' => 6],
            $product
        );
    }

    public function test_zero_product_stock_keeps_unlimited_semantics(): void
    {
        $product = $this->makeProduct(['stock_quantity' => 0]);

        $cart = $this->cartService->addItem(
            Request::create('/x', 'POST'),
            $this->guest(),
            ['product_id' => $product->id, 'quantity' => 3, 'shipping_method' => 'scheduled']
        );

        $this->assertSame(3, (int) $cart->items()->first()->quantity);
    }

    public function test_variant_at_available_stock_is_accepted(): void
    {
        $product = $this->variableProduct();
        $variant = $this->variant($product, ['stock_quantity' => 4, 'reserved_quantity' => 1]);

        $cart = $this->cartService->addItem(
            Request::create('/x', 'POST'),
            $this->guest(),
            [
                'product_id' => $product->id,
                'product_variant_id' => $variant->id,
                'quantity' => 3,
                'shipping_method' => 'scheduled',
            ]
        );

        $this->assertSame(3, (int) $cart->items()->first()->quantity);
    }
}
