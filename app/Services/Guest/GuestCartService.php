<?php

namespace App\Services\Guest;

use App\Enums\GuestCartRejectionReason;
use App\Exceptions\GuestCartRejectionException;
use App\Exceptions\ProductNotPurchasableException;
use App\Support\UniqueViolation;
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
use Marvel\Enums\CartOperation;
use Marvel\Enums\ShippingMethod;

/**
 * Guest cart lifecycle.
 *
 * Reuses the canonical cart mutation path (CartRepository + CartInventoryService)
 * so validation, pricing (ProductPricingService) and totals stay identical to
 * authenticated carts. Never trusts client prices, totals or cart ids.
 *
 * Line policy is delegated to GuestCartEligibility (one home for the rule).
 * Retention: guest_expires_at moves forward ONLY on genuine mutations
 * (add/update/remove/clear). Read paths never extend it.
 */
class GuestCartService
{
    public function __construct(
        private CartRepository $cartRepository,
        private CartInventoryService $cartInventoryService,
        private GuestIdentityService $identityService,
        private GuestCartEligibility $eligibility,
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
            if (!UniqueViolation::detect($e)) {
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
        $this->assertKnownOperation($item);

        $cart = $this->ensureCart($identity);
        $cart = $this->applyWithRetry($cart, $item, 'add');
        $this->touch($request, $identity, $cart);

        return $cart;
    }

    public function updateItem(Request $request, GuestIdentity $identity, array $item): Cart
    {
        $this->assertEligibleItem($item);
        $this->assertKnownOperation($item);

        $cart = $this->ensureCart($identity);
        $cart = $this->applyWithRetry($cart, $item, 'set');
        $this->touch($request, $identity, $cart);

        return $cart;
    }

    /**
     * S12: reject unknown cart operations at the guest boundary with a
     * machine-readable reason. The repository keeps its own defensive guard
     * for non-HTTP callers; this keeps the HTTP reason precise.
     */
    private function assertKnownOperation(array $item): void
    {
        $operation = $item['operation'] ?? CartOperation::INCREMENT;

        if (!in_array($operation, CartOperation::getValues(), true)) {
            throw new GuestCartRejectionException(
                GuestCartRejectionReason::INVALID_OPERATION,
                __('guest_checkout.invalid_operation')
            );
        }
    }

    /**
     * A concurrent login merge may delete the guest cart between ensureCart()
     * and the locked mutation. Re-create once instead of surfacing a 500.
     */
    private function applyWithRetry(Cart $cart, array $item, string $mode): Cart
    {
        try {
            return $this->cartRepository->applyItemToCart($cart, $item, $mode);
        } catch (ProductNotPurchasableException $e) {
            // The product changed between assertEligibleItem() and the locked
            // mutation; keep the guest contract (message + data.reason) instead
            // of leaking the authenticated field-error envelope.
            throw new GuestCartRejectionException(
                GuestCartRejectionReason::PRODUCT_UNAVAILABLE,
                __('guest_checkout.product_unavailable')
            );
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
            throw new GuestCartRejectionException(
                GuestCartRejectionReason::PRODUCT_UNAVAILABLE,
                __('guest_checkout.product_unavailable')
            );
        }

        $quantity = max(1, (int) ($item['quantity'] ?? 0));
        $variantId = empty($item['product_variant_id']) ? null : (int) $item['product_variant_id'];
        $shippingMethod = (string) ($item['shipping_method'] ?? ShippingMethod::SCHEDULED);

        // Line policy lives in GuestCartEligibility (one home for the rule,
        // including the quantity-vs-ceiling comparison for simple and variant
        // lines).
        $this->eligibility->assertEligible($product, $variantId, $quantity, $shippingMethod);
    }
}
