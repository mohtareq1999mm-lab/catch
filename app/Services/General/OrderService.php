<?php

namespace App\Services\General;

use App\DTOs\CheckoutTotals;
use App\DTOs\Tax\TaxBreakdown;
use App\Events\OrderCreated;
use App\Services\Checkout\OrderCreationService;
use App\Services\General\CartInventoryService;
use App\Services\Shipping\ShippingQuoteService;
use App\Services\Tax\TaxCalculator;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use App\ValueObjects\TransitionActor;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Marvel\Database\Models\Coupon;
use Marvel\Database\Models\Cart;
use Marvel\Database\Models\CartItem;
use Marvel\Database\Models\Order;
use Marvel\Database\Models\Product;
use Marvel\Database\Models\Settings;
use Marvel\Enums\ShippingMethod;
use App\Services\Coupon\CouponCalculator;
use App\Services\Coupon\CouponOrchestrator;
use App\Services\Coupon\CouponReservationService;
use Marvel\Enums\DiscountType;
use Marvel\Services\Pricing\ProductPricingService;

/**
 * F-13 global lock ordering (deadlock prevention):
 * Transaction → Order → Cart → Coupon → CouponTargeting → CouponAssignment
 * → CouponReservation → CouponClaim → Usage rows → Order side-effects.
 */
class OrderService
{
    private const DEFAULT_PER_PAGE = 15;

    private const MAX_PER_PAGE = 100;

    protected $dataArray = [
        'name',
        'user_phone',
        'user_email',
        'address',
        'notes',
        'governorate_id',
    ];

    public function __construct(
        private PromotionService $promotionService,
        private OrderCreationService $orderCreationService,
        private CartInventoryService $cartInventoryService,
        private \App\Services\Inventory\OrderReservationService $orderReservationService,
        private \App\Services\Order\OrderTransitionService $orderTransition,
        private CouponReservationService $couponReservationService,
        private ShippingQuoteService $shippingQuoteService,
    ) {}

    public function paginateForUser(Request $request): LengthAwarePaginator
    {
        $limit = $this->getLimit($request);
        $userId = (int) $request->user()->id;

        $orders = Order::query()
            ->forUser($userId)
            ->when($request->has('status'), fn($q) => $q->where('status', $request->get('status')))
            ->with($this->orderListRelations())
            ->paginate($limit)
            ->withQueryString();

        $orders->getCollection()->each(fn(Order $order) => $this->enrichOrderItemsPricing($order));

        return $orders;
    }

    public function getOrderForUser(Request $request, int $orderId): ?Order
    {
        $order = Order::query()
            ->forUser((int) $request->user()->id)
            ->with($this->orderDetailRelations())
            ->find($orderId);

        if (!$order) {
            return null;
        }

        $this->enrichOrderItemsPricing($order);

        return $order;
    }

    private function enrichOrderItemsPricing(Order $order): void
    {
        $order->orderItems->each(function ($item) {
            if ($item->relationLoaded('product') && $item->product) {
                app(ProductService::class)->enrichProductWithPricing($item->product);
            }
        });
    }

    /**
     * @return array<int|string, mixed>
     */
    private function orderListRelations(): array
    {
        return [
            'orderItems.product' => fn($q) => $q->withAvg('reviews', 'rating'),
            'orderItems.product.media',
            'orderItems.productVariant.attributeProducts.attributeValue',
            'transactions',
            'pickupLocation',
            'latestInvoice',
            // Powers digital_downloads[] on delivered DIGITAL lines.
            // Powers digital_downloads[] on delivered DIGITAL lines (BD1 Option B).
            'digitalEntitlements.orderItem.product.digitalAssets',
            // Flow context on lists: full stages (flow.statuses) are included
            // so GET /general/orders returns the same `flow` contract as
            // GET /general/orders/{id} (customer progress UI in lists).
            'flow.statuses',
            'flow',
            'currentStatus',
        ];
    }

    /**
     * Detail-view relations: same as the list (flow stages included for
     * the customer progress UI; single order: one extra query).
     *
     * @return array<int|string, mixed>
     */
    private function orderDetailRelations(): array
    {
        return $this->orderListRelations();
    }

    private function getLimit(Request $request): int
    {
        $limit = (int) $request->get('limit', self::DEFAULT_PER_PAGE);

        if ($limit <= 0) {
            return self::DEFAULT_PER_PAGE;
        }

        return min($limit, self::MAX_PER_PAGE);
    }

    public function calcInvoicePrice($request)
    {
        try {
            return DB::transaction(function () use ($request) {
                $cart = $this->getCartUser();
                if (!$cart) {
                    throw new \InvalidArgumentException(__('checkout.cart_not_found'));
                }
                if ($cart->items->isEmpty()) {
                    throw new \InvalidArgumentException(__('checkout.cart_empty'));
                }
                if ($cart->coupon) {
                    // AREA_IN saved-address remediation: coupon evaluation
                    // uses the user's saved addresses; the preview request
                    // delivery area (shipping only) is never coupon input.
                    $validation = CouponOrchestrator::validateByCode($cart->coupon, $request->user(), $cart->items);
                    if (!$validation['valid']) {
                        $cart->update(['coupon' => null]);
                    }
                }
                $checkoutTotals = $this->calculateCheckoutTotals(
                    $cart,
                    (int) $request->input('selected_promotion_id') ?: null,
                    (int) $request->input('selected_gift_product_id') ?: null,
                    ShippingMethod::SCHEDULED,
                );

                // Preview uses the same quote authority and coupon waiver as
                // order creation; the waiver keeps the quoted shipping at zero.
                $shippingPrice = $this->shippingQuoteService->quoteForCart(
                    $cart,
                    (int) $request->input('governorate_id') ?: null,
                    $checkoutTotals->couponDiscountType,
                )['shipping_amount'];

                // Preview totals include the authoritative tax breakdown.
                $checkoutTotals = $this->withTaxes($checkoutTotals, $cart, $shippingPrice);

                $finalTotal = round(
                    (float) $checkoutTotals->finalTotal + $checkoutTotals->totalTaxAmount() + $shippingPrice,
                    2
                );
                $cart->update(['total_price' => $finalTotal]);

                return $cart->total_price;
            });
        } catch (\Exception $e) {
            report($e);
            throw new \InvalidArgumentException($e->getMessage(), 0, $e);
        }
    }

    /**
     * Canonical order creation path.
     *
     * @param  Cart|null  $ownedCart  Guest cart resolved from the verified guest
     *                                credential. NULL keeps the authenticated
     *                                behaviour (auth()->id()).
     * @param  int|null   $guestId    Verified guest identity id. When present
     *                                the order is linked to the guest and
     *                                user_id stays NULL (no fabricated account).
     */
    public function addItemsInOrder($request, ?Cart $ownedCart = null, ?int $guestId = null)
    {
        $checkoutTotals = null;

        if ($guestId !== null) {
            if (!$ownedCart || (int) $ownedCart->guest_id !== (int) $guestId) {
                throw new \InvalidArgumentException(__('guest_checkout.cart_not_found'));
            }
        }

        try {
            $order = DB::transaction(function () use ($request, &$checkoutTotals, $ownedCart, $guestId) {
                $cartQuery = Cart::query()
                    ->where('status', 'active')
                    ->lockForUpdate()
                    ->with(['items' => fn($q) => $q->where('shipping_method', ShippingMethod::SCHEDULED), 'items.product.flash_sales' => fn($q) => $q->valid(), 'items.productVariant']);

                if ($ownedCart) {
                    $cartQuery->whereKey($ownedCart->id);
                } else {
                    $cartQuery->where('user_id', auth()->id());
                }

                $cart = $cartQuery->first();

                // Re-read under lock: a concurrent checkout may have committed
                // and emptied this cart while we waited. Fail cleanly.
                if (!$cart || $cart->items->isEmpty()) {
                    throw new \App\Exceptions\CartEmptyException(CART_NOT_FOUND);
                }

            $this->refreshCartItemPrices($cart);
            $this->assertCartProductsActive($cart);

            $freeShippingCoupon = false;
            if ($cart->coupon) {
                // CP-09: canonical lookup (case-insensitive, trimmed).
                $lockedCoupon = Coupon::byCode($cart->coupon)->lockForUpdate()->first();
                if ($lockedCoupon) {
                    // Checkout is authoritative: full dynamic revalidation.
                    // AREA_IN saved-address remediation: the request delivery
                    // area drives shipping only, never coupon eligibility.
                    $validation = CouponOrchestrator::validate($lockedCoupon, $request->user(), $cart->items);
                    if (!$validation['valid']) {
                        $cart->update(['coupon' => null]);
                        $cart->refresh();
                    } elseif ($lockedCoupon->discount_type === DiscountType::FREE_SHIPPING) {
                        $freeShippingCoupon = true;
                    }
                } else {
                    $cart->update(['coupon' => null]);
                    $cart->refresh();
                }
            }

            // Prefer the explicit request selection; fall back to promotion
            // metadata persisted on cart lines by earlier pricing previews.
            $selectedPromotionId = (int) $request->input('selected_promotion_id') ?: ($cart->items
                ->firstWhere(fn($item) => !is_null($item->promotion_id))
                ?->promotion_id);

            $selectedGiftProductId = (int) $request->input('selected_gift_product_id') ?: ($cart->items
                ->firstWhere('is_gift', true)
                ?->product_id);

            // Pending order is resolved BEFORE totals so an admin tax override
            // persists across retries and participates in the tax resolution.
            $pendingOrder = $guestId !== null
                ? $this->orderCreationService->findPendingOrderForGuest($guestId)
                : $this->orderCreationService->findPendingOrderForUser($request->user()->id);

            $checkoutTotals = $this->calculateCheckoutTotals(
                $cart,
                $selectedPromotionId ? (int) $selectedPromotionId : null,
                $selectedGiftProductId ? (int) $selectedGiftProductId : null,
                ShippingMethod::SCHEDULED,
            );

            $minimumOrderAmount = (float) (Settings::first()?->minimum_order_amount ?? 0);
            if ($minimumOrderAmount > 0 && $checkoutTotals->subtotal < $minimumOrderAmount) {
                throw new \InvalidArgumentException(
                    __('message.ERROR.MINIMUM_ORDER_AMOUNT', ['amount' => $minimumOrderAmount])
                );
            }

            $orderData = $request->only(array_merge($this->dataArray, [
                'fulfillment_type', 'payment_method', 'payment_gateway', 'pickup_location_id',
            ]));
            $orderData['user_id'] = $guestId !== null ? null : $request->user()->id;
            $orderData['guest_id'] = $guestId;

            $quote = $this->shippingQuoteService->quoteForCart(
                $cart,
                (int) ($orderData['governorate_id'] ?? null),
                $freeShippingCoupon ? DiscountType::FREE_SHIPPING : null,
            );
            $shippingPrice = $quote['shipping_amount'];
            $governorateId = $quote['governorate_id'];

            // Authoritative tax calculation — once, after discounts.
            // Shipping is NEVER taxable.
            $checkoutTotals = $this->withTaxes(
                $checkoutTotals,
                $cart,
                $shippingPrice,
                null
            );

            // Check for existing pending order (Rules 4-5: Payment retry reuses pending order)

            if ($pendingOrder) {
                // Pending-retry invariant: a pending order is already bound
                // to its shipping_type/flow_id. A retry requesting a
                // DIFFERENT shipping type fails closed (422) instead of
                // silently keeping the old flow. Same type (or omitted,
                // which defaults to local) reuses normally.
                $requestedShippingType = \App\Services\OrderFlow\OrderFlowService::normalizeShippingType(
                    $request->input('shipping_type')
                );
                $storedShippingType = \App\Services\OrderFlow\OrderFlowService::normalizeShippingType(
                    $pendingOrder->shipping_type
                );
                if ($requestedShippingType !== $storedShippingType) {
                    throw new \InvalidArgumentException(__('checkout.pending_order_shipping_type_conflict'));
                }

                // Multi-flow: a retry explicitly selecting a DIFFERENT flow
                // than the pending order's stored flow_id fails closed (422)
                // instead of silently keeping the old flow. No explicit
                // selection reuses the stored flow (unchanged behavior).
                $retryFlowId = $request->input('flow_id') !== null ? (int) $request->input('flow_id') : null;
                $retryFlowCode = is_string($request->input('flow_code')) ? $request->input('flow_code') : null;
                if ($retryFlowId !== null || ($retryFlowCode !== null && trim($retryFlowCode) !== '')) {
                    $retryFlow = app(\App\Services\OrderFlow\OrderFlowService::class)->resolveCheckoutFlow(
                        $request->input('shipping_type'), $retryFlowCode, $retryFlowId
                    );
                    if ($pendingOrder->flow_id && (int) $pendingOrder->flow_id !== (int) $retryFlow->id) {
                        throw new \InvalidArgumentException(__('checkout.flow_mismatch'));
                    }
                }

                // Phase 3 addendum: a pending order whose inventory is
                // already COMMITTED (COD/cashier committed at creation) can
                // no longer be re-reserved — the authority has no
                // committed→active transition, so release()/reserveForOrder()
                // below would silently no-op on stale lines. Supersede it
                // through the canonical cancel (committed→restored via the
                // state claim, fulfillment cascade, coupon release) and fall
                // through to a fresh creation below. All stock movement stays
                // inside canonical authorities; never a second commit path.
                if ($pendingOrder->inventory_state === Order::INVENTORY_STATE_COMMITTED) {
                    $this->orderTransition->cancelAsSuperseded(
                        $pendingOrder,
                        TransitionActor::user(auth()->user()),
                        'Superseded by re-checkout: committed pending order replaced by a fresh order',
                    );
                    $pendingOrder = null;
                }

                // Reuse existing pending order: update with new cart data.
                // ($pendingOrder is guaranteed non-committed here: a
                // committed pending order was superseded above.)
                if ($pendingOrder) {
                    $order = $this->orderCreationService->updateOrder(
                        $pendingOrder, $orderData, $cart, $checkoutTotals, null, null, null, $shippingPrice, $governorateId,
                    );

                    // Release old reservation before creating new one
                    $this->orderReservationService->release($order);

                    // CRITICAL FIX: Release old coupon reservation if coupon changed
                    // This prevents orphaned reservations when user changes coupon between retries
                    $this->couponReservationService->release($order);

                    // Sync order items with current cart
                    if (!$this->orderCreationService->syncOrderItems($order, $cart, $checkoutTotals->giftItems, $checkoutTotals)) {
                        throw new \RuntimeException(__('message.MESSAGE.ORDER_ITEM_SYNC_FAILED'));
                    }
                }
            }

            if (!isset($order)) {
                // Create new order
                // Flow/shipping-type assignment happens inside createOrder
                // (local default, fail-closed on unavailable types). Payment
                // retry reuses the pending order WITHOUT changing its flow.
                //
                // Flow Input gate (checkout context): resolve the flow FIRST
                // so missing/invalid flow_values abort BEFORE any row exists.
                // Validated values persist after creation (same transaction).
                $flowService = app(\App\Services\OrderFlow\OrderFlowService::class);
                $checkoutFlowValues = $request->input('flow_values', []);
                if ($checkoutFlowValues === null) {
                    $checkoutFlowValues = [];
                }
                if (!is_array($checkoutFlowValues)) {
                    throw new \InvalidArgumentException(__('checkout.flow_values_must_be_object'));
                }
                $validatedCheckoutValues = [];
                $checkoutFlowId = $request->input('flow_id') !== null ? (int) $request->input('flow_id') : null;
                $checkoutFlowCode = is_string($request->input('flow_code')) ? $request->input('flow_code') : null;
                if (\App\Services\OrderFlow\OrderFlowService::orderFlowColumnsAvailable()) {
                    $checkoutFlow = $flowService->resolveCheckoutFlow(
                        $request->input('shipping_type'),
                        $checkoutFlowCode,
                        $checkoutFlowId
                    );
                    $validatedCheckoutValues = $flowService->validateFlowValues($checkoutFlow, $checkoutFlowValues, 'checkout');
                    // Pin the validated flow: creation binds this exact flow id,
                    // so a concurrent default-switch cannot re-route the order
                    // after its inputs were validated (TOCTOU fail-closed: a
                    // concurrent deactivation still aborts creation).
                    $checkoutFlowId = (int) $checkoutFlow->id;
                    $checkoutFlowCode = null;
                }
                $order = $this->orderCreationService->createOrder(
                    $orderData, $cart, $checkoutTotals, null, null, null, $shippingPrice, $governorateId,
                    $request->input('shipping_type'),
                    $checkoutFlowId,
                    $checkoutFlowCode,
                );
                if (!$order) {
                    throw new \RuntimeException(__('message.MESSAGE.ORDER_CREATION_FAILED'));
                }
                if (!empty($validatedCheckoutValues)) {
                    $flowService->persistValidatedValues($order, $validatedCheckoutValues, 'checkout');
                    $order->refresh();
                }
                if (!$this->orderCreationService->createOrderItems($order, $cart, $checkoutTotals->giftItems, $checkoutTotals)) {
                    throw new \RuntimeException(__('message.MESSAGE.ORDER_ITEM_CREATION_FAILED'));
                }
            }

            // The ORDER now owns the inventory reservation. Any failure here
            // (insufficient stock) aborts the whole transaction: no Order, no
            // reservation, and the CartItems survive untouched for retry.
            //
            // F-04: the atomic reservation may fail on the selected
            // promotional gift (stock lost between the apply-time snapshot
            // and this order-time claim). A genuinely short gift surfaces as
            // the gift-specific 422; any other shortage rethrows the
            // original generic stock error untouched.
            try {
                $this->orderReservationService->reserveForOrder($order);
            } catch (\App\Exceptions\InsufficientStockException $e) {
                $this->promotionService->throwIfGiftUnavailable($checkoutTotals->giftItems ?? []);
                throw $e;
            }

            // Phase 3 addendum: COD/cashier secure their inventory AT CREATION,
            // exactly as a paid online order does — manual payment must not
            // leave ordered quantities sellable to other orders. The canonical
            // commit() (active→committed claim; stock decrement + sold
            // increment) runs in this same transaction, so commit-failure
            // cannot strand a half-created order. Later completion
            // (mark-paid → completed) re-commits as an idempotent no-op —
            // never a second deduction. Digital-only orders keep today's
            // ACTIVE state (no physical counters exist for them).
            if (
                in_array($order->payment_method ?? null, ['cod', 'pay_at_cashier'], true)
                && $this->orderReservationService->hasPhysicalLines($order)
            ) {
                $this->orderReservationService->commit($order);
            }

            // Reservation succeeded — the ordered slice leaves the cart.
            // The cart row itself always survives as a reusable container.
            $this->cartInventoryService->clearCheckedOutSlice($cart, ShippingMethod::SCHEDULED);

            return $order->load(['orderItems.product', 'orderItems.productVariant']);
            });

            // Listeners must never observe an order whose reservation failed:
            // dispatch strictly after commit.
            $this->orderCreationService->finalizeOrder($order, $checkoutTotals);

            return $order;
        } catch (\App\Exceptions\CartEmptyException $e) {
            throw $e;
        } catch (\InvalidArgumentException $e) {
            throw $e;
        } catch (\Exception $e) {
            report($e);
            return null;
        }
    }


    public function eligiblePromotionsForUser(): ?array
    {
        $cart = $this->getCartUser();
        if (!$cart || !$cart->items()->exists()) {
            return null;
        }

        return $this->promotionService->eligiblePromotionsPayload($cart);
    }

    public function getGovernorateShippingInfo(?int $governorateId): array
    {
        return $this->shippingQuoteService->governorateShippingInfo($governorateId);
    }

    /** Kept for legacy reflection-based tests; quote authority lives in ShippingQuoteService. */
    private function resolveShippingPrice(?int $governorateId): array
    {
        return $this->shippingQuoteService->governorateShippingInfo($governorateId);
    }

    public function resolveFreeShippingByThreshold(float $subtotal, ?float $freeShippingOver, float $shippingPrice): float
    {
        return $this->shippingQuoteService->applyFreeShippingThreshold($subtotal, $freeShippingOver, $shippingPrice);
    }

    /**
     * D4 — shipping exists only for PHYSICAL lines.
     * Kept as a thin delegate: quote behavior belongs to ShippingQuoteService.
     */
    public function resolveShippingChargeForCart(Cart $cart, CheckoutTotals $checkoutTotals, array $shippingInfo): float
    {
        return $this->shippingQuoteService->shippingForCart($cart, $shippingInfo);
    }

    public function resolveFreeShippingByCoupon(?string $couponDiscountType, float $shippingPrice): float
    {
        return $this->shippingQuoteService->applyCouponWaiver($couponDiscountType, $shippingPrice);
    }

    private function getCartUser()
    {
        return Cart::query()
            ->where('user_id', auth()->id())
            ->where('status', 'active')
            ->with(['items' => fn($q) => $q->where('shipping_method', ShippingMethod::SCHEDULED), 'items.product.flash_sales' => fn($q) => $q->valid(), 'items.productVariant'])
            ->first();
    }

    private function calculatePriceByCoupon($cart, $totalPrice): array
    {
        if ($cart->coupon === null) {
            return [
                'finalPrice' => $totalPrice,
                'discountType' => null,
                'freeShipping' => false,
            ];
        }

        // CP-09: canonical lookup (case-insensitive, trimmed).
        $coupon = Coupon::valid()->byCode($cart->coupon)->first();
        if (!$coupon) {
            return [
                'finalPrice' => $totalPrice,
                'discountType' => null,
                'freeShipping' => false,
            ];
        }

        return CouponCalculator::calculate($coupon, (float) $totalPrice);
    }

    private function refreshCartItemPrices(Cart $cart): void
    {
        $pricingService = app(ProductPricingService::class);
        $cart->load(['items.product', 'items.productVariant']);

        foreach ($cart->items as $item) {
            if ($item->is_gift) {
                continue;
            }

            $product = $item->product;
            if (!$product) {
                continue;
            }

            $currentPrice = $item->productVariant
                ? $pricingService->calculateVariantCurrentPrice($product, $item->productVariant)
                : $pricingService->calculateProductCurrentPrice($product);

            if ($currentPrice !== null && (float) $currentPrice !== (float) $item->price) {
                $item->forceFill([
                    'price' => $currentPrice,
                    'total_price' => round($currentPrice * max(1, (int) ($item->quantity ?? 0)), 2),
                ])->save();
            }
        }

        $cart->refresh();
        $cart->load(['items' => fn($q) => $q->where('shipping_method', ShippingMethod::SCHEDULED), 'items.product.flash_sales' => fn($q) => $q->valid(), 'items.productVariant']);
    }

    /**
     * F-03 — APPROVED DISCOUNT STACKING PRECEDENCE (explicit contract).
     *
     * Discounts compose deterministically in exactly this order:
     *
     *   Flash Sale → Promotion → Coupon
     *
     * - Flash-sale pricing is embedded in the cart line prices BEFORE this
     *   method runs (refreshCartItemPrices); the post-flash price is the base.
     * - Promotion is applied next via PromotionService on that base.
     * - Coupon is applied LAST on the remainder (priceAfterPromotion).
     * - Gift promotions carry discount 0 plus an order-line descriptor, so
     *   they never shrink the coupon base; the free gift rides alongside.
     *
     * This precedence emerges from the call order below ON PURPOSE. Future
     * discount types must not silently reorder it — change this contract
     * explicitly (code + manual + pinning test) or not at all.
     */
    public function calculateCheckoutTotals(Cart $cart, ?int $selectedPromotionId, ?int $selectedGiftProductId = null, ?string $shippingMethod = null): CheckoutTotals
    {
        $promotionTotals = $this->promotionService->applySelectedPromotion($cart, $selectedPromotionId, $selectedGiftProductId, $shippingMethod);
        $priceAfterPromotion = $promotionTotals->finalTotal;
        $couponResult = $this->calculatePriceByCoupon($cart, $priceAfterPromotion);
        $finalTotal = round(max(0, (float) $couponResult['finalPrice']), 2);

        $coupon = null;
        $couponDiscountMaxAmount = null;
        if ($cart->coupon) {
            // CP-09: canonical lookup (case-insensitive, trimmed).
            $couponModel = Coupon::valid()->byCode($cart->coupon)->first();
            if ($couponModel) {
                $coupon = $couponModel->code;
                $couponDiscountMaxAmount = $couponModel->max_discount_amount;
            }
        }

        // FINANCIAL INVARIANT FIX: Use actual discount amount from CouponCalculator
        // instead of residual calculation to ensure precision
        $couponDiscount = round((float) ($couponResult['discountAmount'] ?? 0), 2);

        return new CheckoutTotals(
            subtotal: $promotionTotals->subtotal,
            promotionDiscount: $promotionTotals->promotionDiscount,
            couponDiscount: $couponDiscount,
            finalTotal: $finalTotal,
            promotion: $promotionTotals->promotion,
            giftItems: $promotionTotals->giftItems,
            coupon: $coupon,
            couponDiscountType: $couponResult['discountType'],
            couponDiscountMaxAmount: $couponDiscountMaxAmount,
        );
    }

    /**
     * THE single authoritative checkout tax calculation point.
     * Product tax after discounts (per line, allocated coupon), order tax on
     * SAME taxable base (Σ taxableLine), shipping NEVER taxable.
     */
    public function withTaxes(
        CheckoutTotals $totals,
        Cart $cart,
        ?float $shippingPrice = null,
        ?float $fastShippingFee = null,
    ): CheckoutTotals {
        $lines = $cart->items->reject(fn ($item) => (bool) ($item->is_gift ?? false));

        if ($lines->isEmpty()) {
            return $totals;
        }

        $lineNetCents = $lines->mapWithKeys(
            fn ($item) => [(int) $item->id => TaxCalculator::toCents((float) ($item->total_price ?? 0))]
        )->all();

        $couponCents = TaxCalculator::toCents($totals->couponDiscount);
        $couponShares = TaxCalculator::allocate($lineNetCents, $couponCents);

        $taxableLineCents = [];
        foreach ($lineNetCents as $itemId => $net) {
            $taxableLineCents[$itemId] = max(0, $net - ($couponShares[$itemId] ?? 0));
        }

        $finalTotalCents = max(0, TaxCalculator::toCents($totals->finalTotal));

        // Load products for direct tax rate
        $products = Product::query()
            ->whereIn('id', $lines->pluck('product_id')->filter()->unique()->values())
            ->get()
            ->keyBy('id');

        // Per-line product tax: taxableLineBase × product.tax_rate (only when enabled)
        $rateGroups = [];
        foreach ($lines as $item) {
            $product = $products->get($item->product_id);
            if ($product && !empty($product->tax_enabled) && $product->tax_rate !== null && (float)$product->tax_rate > 0) {
                $rate = (float) $product->tax_rate;
                $rateGroups[$rate][(int) $item->id] = $taxableLineCents[$item->id];
            }
        }

        $lineTaxes = [];
        $productTaxCents = 0;
        $productTaxableCents = 0;
        foreach ($rateGroups as $rate => $groupLines) {
            $groupTaxable = array_sum($groupLines);
            $productTaxableCents += $groupTaxable;
            foreach (TaxCalculator::groupLineTaxes($groupLines, $rate) as $itemId => $cents) {
                $lineTaxes[$itemId] = [
                    'taxable' => TaxCalculator::fromCents($taxableLineCents[$itemId]),
                    'amount' => TaxCalculator::fromCents($cents),
                    'rate' => (float) $rate,
                ];
                $productTaxCents += $cents;
            }
        }
        // Ensure every taxable line has an entry even if no product tax
        foreach ($taxableLineCents as $itemId => $taxable) {
            if (!isset($lineTaxes[$itemId])) {
                $lineTaxes[$itemId] = [
                    'taxable' => TaxCalculator::fromCents($taxable),
                    'amount' => 0.0,
                    'rate' => null,
                ];
            }
        }

        // Order tax: same taxable base (Σ taxableLine), never productTax nor shipping
        $orderTaxableCents = array_sum($taxableLineCents); // equals finalTotalCents when coupon allocation exact
        // Fallback to finalTotalCents for safety if rounding drift (should be equal)
        if ($orderTaxableCents !== $finalTotalCents) {
            // Use sum of taxable lines as authoritative base per spec
        }

        $settings = null;
        $orderTaxEnabled = false;
        $orderTaxRate = null;
        try {
            if (Schema::hasColumn('settings', 'order_tax_enabled')) {
                $settings = Settings::first();
                $orderTaxEnabled = (bool) ($settings->order_tax_enabled ?? false);
                $orderTaxRate = $settings?->order_tax_rate !== null ? (float) $settings?->order_tax_rate : null;
            }
        } catch (\Throwable $e) { report($e); }

        $orderTaxCents = 0;
        if ($orderTaxEnabled && $orderTaxRate !== null && $orderTaxRate > 0 && $orderTaxableCents > 0) {
            $orderTaxCents = TaxCalculator::amountOn($orderTaxableCents, $orderTaxRate);
        }

        $breakdown = new TaxBreakdown(
            productTaxableAmount: TaxCalculator::fromCents($productTaxableCents),
            productTaxAmount: TaxCalculator::fromCents($productTaxCents),
            orderTaxRate: $orderTaxRate,
            orderTaxableAmount: TaxCalculator::fromCents($orderTaxableCents),
            orderTaxAmount: TaxCalculator::fromCents($orderTaxCents),
            lineTaxes: $lineTaxes,
            taxableBase: TaxCalculator::fromCents($orderTaxableCents),
        );

        return $totals->withTax($breakdown);
    }


    /**
     * Legacy advisory only (flow-less migration window). New code must use
     * getFlowAwareStatusTargets(), which is flow-only for flowed orders.
     * Delegates to the flow module's single owner
     * (OrderFlowService::flowlessAdvisoryTargets).
     */
    public static function getAllowedOrderStatusTargets(?string $currentStatus): array
    {
        return \App\Services\OrderFlow\OrderFlowService::flowlessAdvisoryTargets($currentStatus);
    }

    /**
     * Flow-authority admin targets: the flow successor plus applicable
     * supervised exits (completed/cancelled/failed_delivery/returned/
     * delivered-from-completed). Drives the admin status dropdown; exactly
     * what the guard enforces, so offered targets are always accepted.
     * Flow-less rows (migration window only) fall back to the legacy map.
     */
    public function getFlowAwareStatusTargets(Order $order): array
    {
        $targets = [];

        try {
            $flowService = app(\App\Services\OrderFlow\OrderFlowService::class);

            if (\App\Services\OrderFlow\OrderFlowService::orderFlowColumnsAvailable() && $order->flow_id) {
                $flow = \App\Models\OrderFlow\OrderFlow::query()->find($order->flow_id);

                if ($flow) {
                    $next = $flowService->nextStatus($flow, (string) $order->status);
                    if ($next && $next->is_active) {
                        $targets[] = $next->code;
                    }
                }

                foreach (['completed', 'cancelled', 'failed_delivery', 'returned', 'delivered'] as $exit) {
                    if ($flowService->allowsFlowTransition($order, (string) $order->status, $exit)
                        && !in_array($exit, $targets, true)
                    ) {
                        $targets[] = $exit;
                    }
                }
            } else {
                // Migration window only: flow-less rows (Marvel-created) offer
                // the flow module's advisory targets until P2 assigns every
                // order a flow. Advisory only — the guard itself is flow-only.
                $targets = \App\Services\OrderFlow\OrderFlowService::flowlessAdvisoryTargets((string) $order->status);
            }
        } catch (\Throwable $e) {
            report($e);
        }

        return array_values(array_unique($targets));
    }

    public function markCodAsPaid(Order $order, ?string $reason = null): void
    {
        DB::transaction(function () use ($order, $reason) {
            $transaction = $order->transactions()
                ->where('payment_method', 'cod')
                ->where('status', 'pending')
                ->latest()
                ->lockForUpdate()
                ->first();

            if (!$transaction) {
                throw new \RuntimeException(__('checkout.no_pending_cod_transaction'));
            }

            $transaction->update([
                'status' => 'paid',
                'paid_at' => now(),
            ]);

            // Payment confirmation only — the canonical status transition
            // (inventory commit, promotion finalization, coupon usage,
            // OrderStatusChanged, PaymentSucceeded) lives solely in
            // completeManualPayment() so there is one authoritative path.
            // The manual-audit context (actor resolved from the authenticated
            // user as the calling authority; action, transaction and reason
            // ride along) lands on the same immutable history row.
            $this->orderTransition->completeManualPayment($order, TransitionActor::user(auth()->user()), 'cod', $transaction->id, $reason);
        });
    }

    public function markCashierPaid(Order $order, ?string $reason = null): void
    {
        DB::transaction(function () use ($order, $reason) {
            $transaction = $order->transactions()
                ->where('payment_method', 'pay_at_cashier')
                ->where('status', 'pending')
                ->latest()
                ->lockForUpdate()
                ->first();

            if (!$transaction) {
                throw new \RuntimeException(__('checkout.no_pending_cashier_transaction'));
            }

            $transaction->update([
                'status' => 'paid',
                'paid_at' => now(),
            ]);

            // Payment confirmation only — the canonical status transition
            // (inventory commit, promotion finalization, coupon usage,
            // OrderStatusChanged, PaymentSucceeded) lives solely in
            // completeManualPayment() so there is one authoritative path.
            // The manual-audit context (actor resolved from the authenticated
            // user as the calling authority; action, transaction and reason
            // ride along) lands on the same immutable history row.
            $this->orderTransition->completeManualPayment($order, TransitionActor::user(auth()->user()), 'pay_at_cashier', $transaction->id, $reason);
        });
    }


    /**
     * Public checkout must not create an order for inactive products.
     * Inactive = status != publish OR out-of-stock with no available quantity.
     */
    private function assertCartProductsActive(Cart $cart): void
    {
        // A preserved unavailable line after a guest cart merge may have a
        // NULL product_id (hard-deleted product). It must block checkout
        // instead of being silently skipped.
        $hasUnresolvedNullProduct = $cart->items->contains(
            fn ($item) => !$item->is_gift && $item->product_id === null
        );

        if ($hasUnresolvedNullProduct) {
            throw new \InvalidArgumentException(__('checkout.fast_shipping_items_ineligible'));
        }

        $productIds = $cart->items->pluck('product_id')->filter()->unique()->values();
        if ($productIds->isEmpty()) {
            return;
        }
        $activeCount = Product::query()->active()->whereIn('id', $productIds)->count();
        if ($activeCount !== $productIds->count()) {
            // NOTE: __('product.not_available') has no product.php catalogue
            // and would leak as a raw key — the cart-level sentence is used.
            throw new \InvalidArgumentException(__('checkout.fast_shipping_items_ineligible'));
        }
    }
}