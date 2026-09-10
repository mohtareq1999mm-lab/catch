<?php

namespace App\Services\General;

use Carbon\Carbon;
use Exception;
use Illuminate\Support\Facades\DB;
use Marvel\Database\Models\Cart;
use Marvel\Database\Models\CartItem;
use Marvel\Database\Models\Product;
use Marvel\Database\Models\ProductVariant;
use Marvel\Database\Models\User;
use Marvel\Enums\ShippingMethod;
use Marvel\Services\Pricing\ProductPricingService;

/**
 * Cart lifecycle service.
 *
 * The cart is ONLY the user's current shopping selection:
 * CRUD, duplicate merging, quantity validation, pricing snapshots and
 * abandoned-cart activity tracking. It NEVER touches inventory counters
 * (products/product_variants.reserved_quantity) — inventory reservation
 * belongs to the Order via App\Services\Inventory\OrderReservationService.
 *
 * Note: the historical class name is retained to limit blast radius; the
 * "inventory" responsibility now lives exclusively in OrderReservationService.
 */
class CartInventoryService
{
    /** Activity window used purely for abandoned-cart analytics/notifications. */
    private const CART_ACTIVITY_TTL_DAYS = 3;

    /**
     * Add quantity to a cart line (merging duplicates by product+variant+method).
     * Stock availability is intentionally NOT checked here — it is validated
     * and reserved atomically at checkout against the created Order.
     */
    public function incrementItem(Cart $cart, Product $product, ?ProductVariant $variant, int $quantity, array $attributes = [], string $shippingMethod = ShippingMethod::SCHEDULED): CartItem
    {
        $productName = is_array($product->name) ? ($product->name[app()->getLocale()] ?? $product->name['en'] ?? '') : $product->name;

        return DB::transaction(function () use ($cart, $product, $variant, $quantity, $attributes, $shippingMethod, $productName) {
            $cart = Cart::whereKey($cart->id)->lockForUpdate()->firstOrFail();
            $item = $this->findCartItemForLock($cart, $product->id, $variant?->id, $shippingMethod);
            $desiredQuantity = (int) ($item?->quantity ?? 0) + max(1, $quantity);

            return $this->upsertItem($cart, $item, $product, $variant, $desiredQuantity, $attributes, $shippingMethod);
        });
    }

    /**
     * Reduce a cart line by quantity. Deleting when the remainder drops below 1.
     */
    public function decrementItem(Cart $cart, Product $product, ?ProductVariant $variant, int $quantity, string $shippingMethod = ShippingMethod::SCHEDULED): ?CartItem
    {
        return DB::transaction(function () use ($cart, $product, $variant, $quantity, $shippingMethod) {
            $cart = Cart::whereKey($cart->id)->lockForUpdate()->firstOrFail();
            $item = $this->findCartItemForLock($cart, $product->id, $variant?->id, $shippingMethod);

            if (!$item) {
                throw new Exception(INVALID_ITEM_DATA);
            }

            $targetQuantity = (int) $item->quantity - max(1, $quantity);

            if ($targetQuantity >= 1) {
                return $this->upsertItem($cart, $item, $product, $variant, $targetQuantity, $item->attributes ?: [], $shippingMethod);
            }

            return $this->deleteItemAndTidy($item, $cart);
        });
    }

    /**
     * Remove a single cart line. $deleteItem=false is accepted for backward
     * compatibility and is a safe no-op: carts no longer hold reservations.
     */
    public function releaseItem(CartItem $item, bool $deleteItem = false): bool
    {
        if (!$deleteItem) {
            return true;
        }

        return DB::transaction(function () use ($item) {
            $item = CartItem::whereKey($item->id)->lockForUpdate()->firstOrFail();
            $cartId = $item->cart_id;
            $deleted = (bool) $item->delete();

            if ($deleted) {
                $this->tidyCartAfterRemoval($cartId);
            }

            return $deleted;
        });
    }

    /**
     * Clear every cart line (user "clear cart"). The Cart row itself always survives.
     */
    public function releaseCart(Cart $cart, bool $deleteItems = false): bool
    {
        return DB::transaction(function () use ($cart, $deleteItems) {
            $cart = Cart::whereKey($cart->id)->lockForUpdate()->with('items')->firstOrFail();

            if ($deleteItems) {
                $cart->items()->each(fn (CartItem $item) => $item->delete());
            }

            $cart->update([
                'status' => 'active',
                'coupon' => null,
                'reserved_at' => null,
                'expires_at' => null,
                'total_price' => 0,
            ]);

            return true;
        });
    }

    /**
     * Post-checkout cleanup: delete the ordered shipping-method slice (plus any
     * legacy gift artifacts), clear coupon/totals when nothing remains, and park
     * the activity window so empty carts never trigger abandoned-cart notices.
     * Purely cart-local — inventory was already reserved on the ORDER.
     */
    public function clearCheckedOutSlice(Cart $cart, string $shippingMethod): void
    {
        DB::transaction(function () use ($cart, $shippingMethod) {
            $locked = Cart::whereKey($cart->id)->lockForUpdate()->firstOrFail();

            CartItem::query()
                ->where('cart_id', $locked->id)
                ->where(function ($q) use ($shippingMethod) {
                    $q->where('shipping_method', $shippingMethod)
                        ->orWhere('is_gift', true);
                })
                ->delete();

            $remaining = CartItem::where('cart_id', $locked->id)->lockForUpdate()->get();

            if ($remaining->isEmpty()) {
                $locked->update([
                    'status' => 'active',
                    'coupon' => null,
                    'total_price' => 0,
                    'reserved_at' => null,
                    'expires_at' => null,
                ]);

                return;
            }

            // A fast-shipping slice remains — keep the cart shoppable.
            $locked->update([
                'status' => 'active',
                'total_price' => round((float) $remaining->sum('total_price'), 2),
                'reserved_at' => now(),
                'expires_at' => Carbon::now()->addDays(self::CART_ACTIVITY_TTL_DAYS),
            ]);
        });
    }

    /**
     * Fetch the user's active cart with everything checkout pricing needs.
     * Read-only: performs no reservation or inventory work.
     */
    public function getActiveCartForUser(User $user): ?Cart
    {
        return Cart::query()
            ->where('user_id', $user->id)
            ->where('status', 'active')
            ->with([
                'items.product.flash_sales' => fn($q) => $q->valid(),
                'items.productVariant.attributeProducts.attributeValue.attribute',
            ])
            ->first();
    }

    /**
     * Abandoned-cart/activity touch. This is NOT a reservation: it only keeps
     * the analytics/notification window (reserved_at/expires_at) fresh.
     */
    public function touchActivity(Cart $cart): void
    {
        $cart->update([
            'status' => 'active',
            'reserved_at' => now(),
            'expires_at' => Carbon::now()->addDays(self::CART_ACTIVITY_TTL_DAYS),
        ]);
    }

    /** Backward-compatible alias for the historical internal name. */
    private function touchCartReservation(Cart $cart): void
    {
        $this->touchActivity($cart);
    }

    private function upsertItem(Cart $cart, ?CartItem $item, Product $product, ?ProductVariant $variant, int $desiredQuantity, array $attributes, string $shippingMethod): CartItem
    {
        return DB::transaction(function () use ($cart, $item, $product, $variant, $desiredQuantity, $attributes, $shippingMethod) {
            $cart = Cart::whereKey($cart->id)->lockForUpdate()->firstOrFail();

            if ($desiredQuantity < 1) {
                throw new Exception(__(QUANTITY_MINIMUM));
            }

            $price = $variant
                ? app(ProductPricingService::class)->calculateVariantCurrentPrice($product, $variant)
                : app(ProductPricingService::class)->calculateProductCurrentPrice($product);

            if ($variant && !$variant->relationLoaded('attributeProducts')) {
                $variant->load('attributeProducts.attributeValue.attribute');
            }

            $payload = [
                'product_id' => $product->id,
                'product_variant_id' => $variant?->id,
                'quantity' => $desiredQuantity,
                'price' => $price,
                'total_price' => round($price * $desiredQuantity, 2),
                'attributes' => $variant ? $this->getVariantAttributes($variant) : ($attributes ?: null),
                'shipping_method' => $shippingMethod,
                'promotion_id' => null,
                'discount_amount' => 0,
            ];

            if ($item) {
                $item->update($payload);
                $this->touchCartReservation($cart);

                return $item->refresh();
            }

            $item = $cart->items()->create($payload);
            $this->touchCartReservation($cart);

            return $item;
        });
    }

    private function deleteItemAndTidy(CartItem $item, Cart $cart): ?CartItem
    {
        $cartId = $item->cart_id;
        $item->delete();
        $this->tidyCartAfterRemoval($cartId);
        $this->touchCartReservation($cart->refresh());

        return null;
    }

    /** Clear the cart coupon once the last line is gone. */
    private function tidyCartAfterRemoval(int $cartId): void
    {
        $remaining = CartItem::where('cart_id', $cartId)->lockForUpdate()->count();
        if ($remaining === 0) {
            Cart::whereKey($cartId)->lockForUpdate()->update(['coupon' => null]);
        }
    }

    private function findCartItemForLock(Cart $cart, int $productId, ?int $variantId, ?string $shippingMethod = null): ?CartItem
    {
        $query = CartItem::query()
            ->where('cart_id', $cart->id)
            ->where('product_id', $productId)
            ->where('is_gift', false)
            ->lockForUpdate();

        if ($variantId) {
            $query->where('product_variant_id', $variantId);
        } else {
            $query->whereNull('product_variant_id');
        }

        if ($shippingMethod !== null) {
            $query->where('shipping_method', $shippingMethod);
        }

        return $query->first();
    }

    private function getVariantAttributes(ProductVariant $variant): array
    {
        return $variant->attributeProducts->map(function ($ap) {
            return [
                'attribute' => $ap->attributeValue?->attribute?->name,
                'value' => $ap->attributeValue?->value,
            ];
        })->toArray();
    }
}
