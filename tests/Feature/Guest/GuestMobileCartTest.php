<?php

namespace Tests\Feature\Guest;

use App\Exceptions\GuestCartRejectionException;
use App\Services\Guest\GuestIdentityService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Marvel\Database\Models\Cart;
use Marvel\Database\Models\Product;
use Marvel\Database\Models\ProductVariant;
use Marvel\Enums\ProductType;

/**
 * Mobile Credential cart flows (T19 / S5 / S11 / S12 / A1).
 */
class GuestMobileCartTest extends GuestCheckoutTestCase
{
    private function asBearer(string $token): static
    {
        return $this->withUnencryptedCookies([
            GuestIdentityService::COOKIE => '',
            GuestIdentityService::CSRF_COOKIE => '',
        ])->withHeaders(['Authorization' => 'Bearer ' . $token]);
    }

    private function createMobileSession(): string
    {
        $response = $this->withUnencryptedCookies([
            GuestIdentityService::COOKIE => '',
            GuestIdentityService::CSRF_COOKIE => '',
        ])->withHeaders(['Authorization' => ''])
            ->postJson(self::PREFIX . '/mobile/session');

        $response->assertCreated();

        return (string) $response->json('data.token');
    }

    private function addItem(string $token, array $item)
    {
        return $this->asBearer($token)->postJson(
            self::PREFIX . '/cart/items',
            ['item' => array_merge([
                'shipping_method' => 'scheduled',
                'quantity' => 1,
            ], $item)]
        );
    }

    private function variableProduct(array $overrides = []): Product
    {
        return $this->makeProduct(array_merge([
            'product_type' => ProductType::VARIABLE,
            'stock_quantity' => 100,
        ], $overrides));
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

    public function test_add_remove_and_clear_via_bearer(): void
    {
        $token = $this->createMobileSession();

        // Add twice: default operation increments the same line.
        $this->addItem($token, ['product_id' => $this->product->id, 'quantity' => 1])->assertCreated();
        $this->addItem($token, ['product_id' => $this->product->id, 'quantity' => 1])->assertCreated();

        $this->assertSame(2, (int) $this->currentGuestItem()->quantity);

        // Decrement one unit.
        $this->asBearer($token)->putJson(self::PREFIX . '/cart/items', [
            'item' => [
                'product_id' => $this->product->id,
                'quantity' => 1,
                'shipping_method' => 'scheduled',
                'operation' => 'decrement',
            ],
        ])->assertOk();

        $this->assertSame(1, (int) $this->currentGuestItem()->quantity);

        // Remove the single line.
        $this->asBearer($token)->deleteJson(
            self::PREFIX . '/cart/items/' . $this->currentGuestItem()->id
        )->assertOk();

        $this->assertSame(0, $this->guestItemCount());

        // Clear the cart.
        $this->asBearer($token)->deleteJson(self::PREFIX . '/cart')->assertOk();
    }

    public function test_foreign_variant_is_rejected_with_reason(): void
    {
        $token = $this->createMobileSession();
        $product = $this->variableProduct();
        $foreign = $this->variableProduct();
        $foreignVariant = $this->variant($foreign);

        $this->addItem($token, [
            'product_id' => $product->id,
            'product_variant_id' => $foreignVariant->id,
        ])->assertStatus(422)
            ->assertJsonPath('data.reason', GuestCartRejectionException::VARIANT_UNAVAILABLE);
    }

    public function test_out_of_stock_variant_is_rejected_with_reason(): void
    {
        $token = $this->createMobileSession();
        $product = $this->variableProduct();
        $variant = $this->variant($product);
        $variant->forceFill(['in_stock' => false])->save();

        $this->addItem($token, [
            'product_id' => $product->id,
            'product_variant_id' => $variant->id,
        ])->assertStatus(422)
            ->assertJsonPath('data.reason', GuestCartRejectionException::VARIANT_UNAVAILABLE);
    }

    public function test_quantity_above_stock_is_rejected_with_reason(): void
    {
        $token = $this->createMobileSession();
        $product = $this->variableProduct();
        $variant = $this->variant($product, ['stock_quantity' => 3]);

        $this->addItem($token, [
            'product_id' => $product->id,
            'product_variant_id' => $variant->id,
            'quantity' => 4,
        ])->assertStatus(422)
            ->assertJsonPath('data.reason', GuestCartRejectionException::INSUFFICIENT_STOCK);
    }

    public function test_invalid_operation_fails_validation(): void
    {
        $token = $this->createMobileSession();

        $this->addItem($token, [
            'product_id' => $this->product->id,
            'operation' => 'multiply',
        ])->assertStatus(422);
    }

    public function test_reads_do_not_extend_retention_but_mutations_do(): void
    {
        $token = $this->createMobileSession();

        $this->addItem($token, ['product_id' => $this->product->id, 'quantity' => 1])->assertCreated();

        $before = Carbon::parse((string) Cart::query()->first()->guest_expires_at);

        // Read: retention must not move.
        $this->asBearer($token)->getJson(self::PREFIX . '/cart')->assertOk();

        $afterRead = Carbon::parse((string) Cart::query()->first()->guest_expires_at);
        $this->assertTrue($before->equalTo($afterRead), 'Reads must never extend guest retention.');

        // Mutation: retention moves forward.
        $this->addItem($token, ['product_id' => $this->product->id, 'quantity' => 1])->assertCreated();

        $afterWrite = Carbon::parse((string) Cart::query()->first()->guest_expires_at);
        $this->assertTrue($afterWrite->greaterThan($before), 'Mutations must extend guest retention.');
    }
}
