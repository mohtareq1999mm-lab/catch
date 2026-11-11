<?php

namespace App\Services\Guest;

use App\Contexts\ChannelContext;
use App\Models\GuestIdentity;
use App\Services\General\CartInventoryService;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Marvel\Database\Models\Cart;
use Marvel\Database\Models\CartItem;
use Marvel\Database\Models\Product;
use Marvel\Database\Models\ProductVariant;
use Marvel\Database\Models\User;
use Marvel\Database\Repositories\CartRepository;
use Marvel\Enums\ItemType;
use Marvel\Enums\ShippingMethod;
use Marvel\Http\Resources\CartResource;

/**
 * Guest → authenticated cart merge.
 *
 * Ownership is resolved ONLY from the verified guest cookie; no client cart id
 * is accepted. Cart mutations reuse CartRepository/CartInventoryService, so
 * product validation and pricing stay authoritative.
 *
 * Transaction/locking: both carts are locked in ascending id order. The guest
 * cart is deleted only after every item has been moved/merged and the
 * transaction commits. A failure rolls back everything and leaves the guest
 * cart intact.
 */
class GuestCartMergeService
{
    public function __construct(
        private GuestIdentityService $identities,
        private CartRepository $cartRepository,
        private CartInventoryService $cartInventoryService,
    ) {
    }

    /**
     * @return array{
     *   status: string,
     *   added_items: int,
     *   merged_items: int,
     *   adjusted_items: array,
     *   unavailable_items: array,
     *   guest_cart_removed: bool,
     *   cart: mixed
     * }
     */
    public function merge(Request $request, User $user): array
    {
        $guest = $this->identities->current($request);

        if (!$guest) {
            return $this->emptyResult('no_guest_cart');
        }

        $result = DB::transaction(function () use ($guest, $user, $request) {
            $guestCartId = Cart::query()
                ->where('guest_id', $guest->id)
                ->value('id');

            if (!$guestCartId) {
                return $this->emptyResult('no_guest_cart');
            }

            $accountCart = $this->ensureAccountCart($user);

            if ((int) $guestCartId === (int) $accountCart->id) {
                // Defensive: a cart must never be both guest- and user-owned.
                return $this->emptyResult('no_guest_cart');
            }

            // Consistent lock order prevents AB/BA deadlocks between
            // concurrent login requests.
            $lockedCarts = Cart::query()
                ->whereIn('id', array_values(array_unique([$guestCartId, $accountCart->id])))
                ->orderBy('id')
                ->lockForUpdate()
                ->get()
                ->keyBy('id');

            $guestCart = $lockedCarts->get($guestCartId);
            $accountCart = $lockedCarts->get($accountCart->id) ?? $accountCart;

            if (!$guestCart) {
                // A concurrent login already merged and removed it.
                return $this->emptyResult('no_guest_cart');
            }

            $guestItems = CartItem::query()
                ->where('cart_id', $guestCart->id)
                ->orderBy('id')
                ->lockForUpdate()
                ->get();

            $added = 0;
            $merged = 0;
            $adjusted = [];
            $unavailable = [];

            foreach ($guestItems as $guestItem) {
                if ($guestItem->is_gift) {
                    // Gift lines are promotion artifacts; preserve them
                    // verbatim rather than converting them into paid lines.
                    $this->preserveUnavailable($accountCart, $guestItem);
                    $unavailable[] = $this->itemSummary($guestItem, 'gift_line_preserved');
                    continue;
                }

                $availability = $this->availability($guestItem);

                if (!$availability['available']) {
                    $this->preserveUnavailable($accountCart, $guestItem);
                    $unavailable[] = $this->itemSummary($guestItem, $availability['reason']);
                    continue;
                }

                $product = $availability['product'];
                $existing = $this->findAccountLine($accountCart, $guestItem);
                $existingQty = $existing ? (int) $existing->quantity : 0;

                $target = min(
                    CartInventoryService::maxItemQuantity(),
                    $existingQty + (int) $guestItem->quantity
                );

                $availableQty = $this->knownAvailableQuantity($product, $guestItem->product_variant_id);
                if ($availableQty !== null) {
                    $target = min($target, $availableQty);
                }

                $target = max($existingQty, $target);
                $mergeQty = max(0, $target - $existingQty);
                $dropped = max(0, ($existingQty + (int) $guestItem->quantity) - $target);

                if ($mergeQty < 1) {
                    // Existing line is already at the max/stock ceiling; the
                    // guest quantity cannot be added. Report, then release the
                    // guest line (the merge itself succeeds).
                    $adjusted[] = $this->itemSummary($guestItem, 'quantity_capped', [
                        'requested_quantity' => (int) $guestItem->quantity,
                        'applied_quantity' => 0,
                        'dropped_quantity' => (int) $guestItem->quantity,
                    ]);
                    $guestItem->delete();
                    continue;
                }

                try {
                    $accountCart = $this->cartRepository->applyItemToCart($accountCart, [
                        'product_id' => $guestItem->product_id,
                        'product_variant_id' => $guestItem->product_variant_id,
                        'quantity' => $mergeQty,
                        'shipping_method' => $guestItem->shipping_method,
                        'attributes' => $guestItem->attributes ?? [],
                        'operation' => 'increment',
                    ], 'add');
                } catch (\Illuminate\Database\QueryException $e) {
                    // Infrastructure failure: abort the whole merge so the
                    // guest cart is preserved, never masquerade as
                    // "unavailable".
                    throw $e;
                } catch (\Throwable $e) {
                    // Product/variant changed under us: preserve rather than
                    // lose the customer's selection.
                    report($e);
                    $this->preserveUnavailable($accountCart, $guestItem);
                    $unavailable[] = $this->itemSummary($guestItem, 'merge_failed');
                    continue;
                }

                if ($existing) {
                    $merged++;
                } else {
                    $added++;
                }

                if ($dropped > 0) {
                    $adjusted[] = $this->itemSummary($guestItem, 'quantity_capped', [
                        'requested_quantity' => (int) $guestItem->quantity,
                        'applied_quantity' => $mergeQty,
                        'dropped_quantity' => $dropped,
                    ]);
                }

                $guestItem->delete();
            }

            $remaining = CartItem::query()->where('cart_id', $guestCart->id)->count();
            $removed = $remaining === 0;

            if ($removed) {
                $guestCart->delete();
            }

            $accountCart = $accountCart->fresh([
                'items.product',
                'items.productVariant.attributeProducts.attributeValue.attribute',
            ]);
            $accountCart->update([
                'total_price' => round((float) $accountCart->items()->sum('total_price'), 2),
                'status' => 'active',
            ]);
            $accountCart->refresh();

            return [
                'status' => 'merged',
                'added_items' => $added,
                'merged_items' => $merged,
                'adjusted_items' => $adjusted,
                'unavailable_items' => $unavailable,
                'guest_cart_removed' => $removed,
                // Load relations inside the transaction, but serialize AFTER
                // commit so coupon/promotion/currency work does not extend
                // the row-lock window.
                'cart' => $accountCart->load([
                    'items.product',
                    'items.productVariant.attributeProducts.attributeValue.attribute',
                ]),
            ];
        });

        if (($result['status'] ?? null) === 'merged' && $result['cart'] instanceof Cart) {
            try {
                $result['cart'] = CartResource::make($result['cart'])->resolve($request);
            } catch (\Throwable $e) {
                // Merge already committed; report presentation failure without
                // telling the client the merge failed.
                report($e);
                $result['cart'] = null;
            }
        }

        return $result;
    }

    /**
     * @return array{available: bool, reason: ?string, product: ?Product}
     */
    private function availability(CartItem $item): array
    {
        $product = $item->product_id
            ? Product::withTrashed()->find($item->product_id)
            : null;

        if (!$product) {
            return ['available' => false, 'reason' => 'product_deleted', 'product' => null];
        }

        if (method_exists($product, 'trashed') && $product->trashed()) {
            return ['available' => false, 'reason' => 'product_deleted', 'product' => $product];
        }

        if (($product->item_type ?? ItemType::PHYSICAL) === ItemType::DIGITAL) {
            return ['available' => false, 'reason' => 'digital_not_supported', 'product' => $product];
        }

        if (!Product::query()->active()->whereKey($product->id)->exists()) {
            return ['available' => false, 'reason' => 'product_unavailable', 'product' => $product];
        }

        if (
            config('channel.enabled', true)
            && app(ChannelContext::class)->isHome()
            && (bool) $product->is_fast_shipping_available
        ) {
            return ['available' => false, 'reason' => 'channel_not_eligible', 'product' => $product];
        }

        if ($item->product_variant_id) {
            $variantExists = ProductVariant::query()
                ->whereKey($item->product_variant_id)
                ->where('product_id', $product->id)
                ->exists();

            if (!$variantExists) {
                return ['available' => false, 'reason' => 'variant_unavailable', 'product' => $product];
            }
        }

        return ['available' => true, 'reason' => null, 'product' => $product];
    }

    private function findAccountLine(Cart $accountCart, CartItem $guestItem): ?CartItem
    {
        return CartItem::query()
            ->where('cart_id', $accountCart->id)
            ->where('product_id', $guestItem->product_id)
            ->when(
                $guestItem->product_variant_id,
                fn ($q) => $q->where('product_variant_id', $guestItem->product_variant_id),
                fn ($q) => $q->whereNull('product_variant_id')
            )
            ->where('shipping_method', $guestItem->shipping_method)
            ->where('is_gift', false)
            ->lockForUpdate()
            ->first();
    }

    /**
     * Move an unavailable line into the authenticated cart unchanged so the
     * customer can see/resolve it. Checkout revalidates cart products and
     * rejects unavailable lines.
     */
    private function preserveUnavailable(Cart $accountCart, CartItem $guestItem): void
    {
        if ($guestItem->is_gift || $guestItem->product_id === null) {
            $guestItem->cart_id = $accountCart->id;
            $guestItem->save();

            return;
        }

        $existing = CartItem::query()
            ->where('cart_id', $accountCart->id)
            ->where('product_id', $guestItem->product_id)
            ->when(
                $guestItem->product_variant_id,
                fn ($q) => $q->where('product_variant_id', $guestItem->product_variant_id),
                fn ($q) => $q->whereNull('product_variant_id')
            )
            ->where('shipping_method', $guestItem->shipping_method)
            ->where('is_gift', false)
            ->lockForUpdate()
            ->first();

        if ($existing) {
            $existing->quantity = (int) $existing->quantity + (int) $guestItem->quantity;
            $existing->total_price = round((float) $existing->price * (int) $existing->quantity, 2);
            $existing->save();
            $guestItem->delete();

            return;
        }

        $guestItem->cart_id = $accountCart->id;
        $guestItem->save();
    }

    private function knownAvailableQuantity(Product $product, ?int $variantId = null): ?int
    {
        if ($variantId) {
            $variant = ProductVariant::query()->whereKey($variantId)->first();
            $variantStock = (int) ($variant->stock_quantity ?? 0);

            if ($variant) {
                // Variant stock is stricter than product-level stock.
                return $variantStock > 0
                    ? max(0, $variantStock - (int) ($variant->reserved_quantity ?? 0))
                    : 0;
            }
        }

        $stock = (int) ($product->stock_quantity ?? 0);

        if ($stock <= 0) {
            return null; // unlimited/unknown stock semantics
        }

        return max(0, $stock - (int) ($product->reserved_quantity ?? 0));
    }

    private function ensureAccountCart(User $user): Cart
    {
        $cart = Cart::query()->where('user_id', $user->id)->first();

        if ($cart) {
            return $cart;
        }

        try {
            return DB::transaction(fn () => Cart::create([
                'user_id' => $user->id,
                'status' => 'active',
                'total_price' => 0,
            ]));
        } catch (QueryException $e) {
            if (!$this->isDuplicateKeyError($e)) {
                throw $e;
            }

            $winner = Cart::query()->where('user_id', $user->id)->first();

            if (!$winner) {
                throw $e;
            }

            return $winner;
        }
    }

    private function itemSummary(CartItem $item, string $reason, array $extra = []): array
    {
        return array_merge([
            'cart_item_id' => $item->id,
            'product_id' => $item->product_id,
            'product_variant_id' => $item->product_variant_id,
            'shipping_method' => $item->shipping_method,
            'quantity' => (int) $item->quantity,
            'reason' => $reason,
            'message' => __('guest_checkout.merge_' . $reason),
        ], $extra);
    }

    private function emptyResult(string $status): array
    {
        return [
            'status' => $status,
            'added_items' => 0,
            'merged_items' => 0,
            'adjusted_items' => [],
            'unavailable_items' => [],
            'guest_cart_removed' => false,
            'cart' => null,
        ];
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
