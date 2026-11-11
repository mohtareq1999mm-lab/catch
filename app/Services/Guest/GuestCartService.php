<?php

namespace App\Services\Guest;

use App\Contexts\ChannelContext;
use App\Models\GuestIdentity;
use App\Services\General\CartInventoryService;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Marvel\Database\Models\Cart;
use Marvel\Database\Models\CartItem;
use Marvel\Database\Models\Product;
use Marvel\Database\Repositories\CartRepository;
use Marvel\Enums\ItemType;
use Marvel\Enums\ShippingMethod;

/**
 * Guest cart lifecycle.
 *
 * Reuses the canonical cart mutation path (CartRepository + CartInventoryService)
 * so validation, pricing (ProductPricingService) and totals stay identical to
 * authenticated carts. Never trusts client prices, totals or cart ids.
 *
 * Retention: guest_expires_at moves forward ONLY on genuine mutations
 * (add/update/remove/clear). Read paths never extend it.
 */
class GuestCartService
{
    public function __construct(
        private CartRepository $cartRepository,
        private CartInventoryService $cartInventoryService,
        private GuestIdentityService $identityService,
    ) {
    }

    public function ttlDays(): int
    {
        return $this->identityService->ttlDays();
    }

    /** Read-only: no identity/cart creation, no expiry extension. */
    public function findCart(GuestIdentity $identity): ?Cart
    {
        return Cart::query()
            ->where('guest_id', $identity->id)
            ->with([
                'items.product.flash_sales' => fn ($q) => $q->valid(),
                'items.productVariant.attributeProducts.attributeValue.attribute',
            ])
            ->first();
    }

    /**
     * Concurrency-safe one-cart-per-guest. UNIQUE(carts.guest_id) arbitrates
     * simultaneous first mutations; the loser resolves the winner's row.
     */
    public function ensureCart(GuestIdentity $identity): Cart
    {
        $cart = Cart::query()->where('guest_id', $identity->id)->first();

        if ($cart) {
            return $cart;
        }

        try {
            return Cart::create([
                'user_id' => null,
                'guest_id' => $identity->id,
                'status' => 'active',
                'total_price' => 0,
                'guest_expires_at' => now()->addDays($this->ttlDays()),
            ]);
        } catch (QueryException $e) {
            if (!$this->isDuplicateKeyError($e)) {
                throw $e;
            }

            $winner = Cart::query()->where('guest_id', $identity->id)->first();

            if (!$winner) {
                throw $e;
            }

            return $winner;
        }
    }

    public function addItem(Request $request, GuestIdentity $identity, array $item): Cart
    {
        $this->assertEligibleItem($item);

        $cart = $this->ensureCart($identity);
        $cart = $this->applyWithRetry($cart, $item, 'add');
        $this->touch($request, $identity, $cart);

        return $cart;
    }

    public function updateItem(Request $request, GuestIdentity $identity, array $item): Cart
    {
        $this->assertEligibleItem($item);

        $cart = $this->ensureCart($identity);
        $cart = $this->applyWithRetry($cart, $item, 'set');
        $this->touch($request, $identity, $cart);

        return $cart;
    }

    /**
     * A concurrent login merge may delete the guest cart between ensureCart()
     * and the locked mutation. Re-create once instead of surfacing a 500.
     */
    private function applyWithRetry(Cart $cart, array $item, string $mode): Cart
    {
        try {
            return $this->cartRepository->applyItemToCart($cart, $item, $mode);
        } catch (ModelNotFoundException $e) {
            $fresh = $this->ensureCart($this->identityForCart($cart));

            return $this->cartRepository->applyItemToCart($fresh, $item, $mode);
        }
    }

    private function identityForCart(Cart $cart): GuestIdentity
    {
        return GuestIdentity::query()->findOrFail($cart->guest_id);
    }

    public function removeItem(Request $request, GuestIdentity $identity, int $itemId): ?Cart
    {
        return DB::transaction(function () use ($request, $identity, $itemId) {
            $cart = Cart::query()
                ->where('guest_id', $identity->id)
                ->lockForUpdate()
                ->first();

            if (!$cart) {
                return null;
            }

            $item = CartItem::query()
                ->where('cart_id', $cart->id)
                ->whereKey($itemId)
                ->first();

            if (!$item) {
                return null;
            }

            if (!$this->cartInventoryService->releaseItem($item, true)) {
                return null;
            }

            $cart->update([
                'total_price' => round((float) CartItem::query()->where('cart_id', $cart->id)->sum('total_price'), 2),
            ]);

            $this->touch($request, $identity, $cart);

            return $cart->load([
                'items.product',
                'items.productVariant.attributeProducts.attributeValue.attribute',
            ]);
        });
    }

    public function clear(Request $request, GuestIdentity $identity): ?Cart
    {
        return DB::transaction(function () use ($request, $identity) {
            $cart = Cart::query()
                ->where('guest_id', $identity->id)
                ->lockForUpdate()
                ->first();

            if (!$cart) {
                return null;
            }

            $this->cartInventoryService->releaseCart($cart, true);
            $this->touch($request, $identity, $cart->refresh());

            return $cart->load([
                'items.product',
                'items.productVariant.attributeProducts.attributeValue.attribute',
            ]);
        });
    }

    /**
     * Genuine mutation: extend guest cart retention + credential expiry and
     * refresh the cookie exactly once per successful mutation.
     */
    private function touch(Request $request, GuestIdentity $identity, Cart $cart): void
    {
        $expiresAt = now()->addDays($this->ttlDays());

        $cart->forceFill([
            'guest_expires_at' => $expiresAt,
            'status' => 'active',
        ])->save();

        $this->identityService->extend($request, $identity);
    }

    /**
     * Existing product rules, mirrored from the canonical cart path:
     *  - active/sellable product (Product::active()),
     *  - physical only for guest checkout (locked decision),
     *  - home channel hides fast-shipping products,
     *  - variable products require a variant,
     *  - requested quantity must fit current availability when known.
     */
    private function assertEligibleItem(array $item): void
    {
        $product = Product::query()->active()->find((int) ($item['product_id'] ?? 0));

        if (!$product) {
            throw new \InvalidArgumentException(__('guest_checkout.product_unavailable'));
        }

        if (($product->item_type ?? ItemType::PHYSICAL) === ItemType::DIGITAL) {
            throw new \InvalidArgumentException(__('guest_checkout.digital_not_supported'));
        }

        if (
            config('channel.enabled', true)
            && app(ChannelContext::class)->isHome()
            && (bool) $product->is_fast_shipping_available
        ) {
            throw new \InvalidArgumentException(__('guest_checkout.product_unavailable'));
        }

        $shippingMethod = strtoupper((string) ($item['shipping_method'] ?? ShippingMethod::SCHEDULED));

        // Guest checkout currently has no fast-shipping checkout path (the
        // canonical order builder consumes SCHEDULED lines only). Reject
        // instead of silently dropping the line later.
        if ($shippingMethod !== ShippingMethod::SCHEDULED) {
            throw new \InvalidArgumentException(__('guest_checkout.fast_shipping_not_supported'));
        }

        if (($product->product_type ?? null) === 'variable' && empty($item['product_variant_id'])) {
            throw new \InvalidArgumentException(__('guest_checkout.variant_required'));
        }

        $quantity = max(1, (int) ($item['quantity'] ?? 0));
        $stockQuantity = (int) ($product->stock_quantity ?? 0);
        $reserved = (int) ($product->reserved_quantity ?? 0);
        $available = max(0, $stockQuantity - $reserved);

        if ($stockQuantity > 0 && $quantity > $available) {
            throw new \InvalidArgumentException(__('guest_checkout.insufficient_stock'));
        }
    }

    private function isDuplicateKeyError(QueryException $e): bool
    {
        $errorInfo = $e->errorInfo ?? [];

        return ($errorInfo[1] ?? null) === 1062
            || $e->getCode() === '23000'
            || $e->getCode() === '23505'
            || (string) $e->getCode() === '1062';
    }
}
