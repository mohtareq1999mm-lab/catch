<?php

namespace App\Services\General;

use App\DTOs\CheckoutTotals;
use App\DTOs\Tax\TaxBreakdown;
use App\Events\AssignedCouponConsumed;
use App\Events\OrderCreated;
use App\Services\Checkout\OrderCreationService;
use App\Services\General\CartInventoryService;
use App\Services\Tax\TaxCalculator;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Marvel\Database\Models\Coupon;
use Marvel\Database\Models\CouponAssignment;
use Marvel\Database\Models\CouponAssignmentUsage;
use Marvel\Database\Models\CouponUsage;
use Marvel\Database\Models\Cart;
use Marvel\Database\Models\CartItem;
use Marvel\Database\Models\Governorate;
use Marvel\Database\Models\Order;
use Marvel\Database\Models\Product;
use Marvel\Database\Models\Promotion;
use Marvel\Database\Models\Settings;
use Marvel\Database\Models\ShippingPrice;
use Marvel\Database\Models\Transaction;
use Marvel\Enums\ShippingMethod;
use App\Events\OrderCancelled;
use App\Events\OrderStatusChanged;
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
        private \App\Services\Inventory\InventoryRestoreService $inventoryRestoreService,
        private \App\Services\Invoice\InvoiceService $invoiceService,
        private CouponReservationService $couponReservationService,
        private ?\App\Services\Customer\CustomerMetricsService $customerMetricsService = null,
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
            ->with($this->orderListRelations())
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
        ];
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

                $shippingInfo = $this->resolveShippingPrice((int) $request->input('governorate_id') ?: null);
                $shippingPrice = $this->resolveShippingChargeForCart($cart, $checkoutTotals, $shippingInfo);
                $shippingPrice = $this->resolveFreeShippingByCoupon($checkoutTotals->couponDiscountType, $shippingPrice);

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

    public function addItemsInOrder($request)
    {
        $checkoutTotals = null;

        try {
            $order = DB::transaction(function () use ($request, &$checkoutTotals) {
                $cart = Cart::query()
                    ->where('user_id', auth()->id())
                    ->where('status', 'active')
                    ->lockForUpdate()
                    ->with(['items' => fn($q) => $q->where('shipping_method', ShippingMethod::SCHEDULED), 'items.product.flash_sales' => fn($q) => $q->valid(), 'items.productVariant'])
                    ->first();

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
            $pendingOrder = $this->orderCreationService->findPendingOrderForUser($request->user()->id);

            $checkoutTotals = $this->calculateCheckoutTotals(
                $cart,
                $selectedPromotionId ? (int) $selectedPromotionId : null,
                $selectedGiftProductId ? (int) $selectedGiftProductId : null,
                ShippingMethod::SCHEDULED,
            );

            $minimumOrderAmount = (float) (Settings::first()?->minimum_order_amount ?? 0);
            if ($minimumOrderAmount > 0 && $checkoutTotals->subtotal < $minimumOrderAmount) {
                throw new \InvalidArgumentException(
                    __('Minimum order amount is :amount', ['amount' => $minimumOrderAmount])
                );
            }

            $orderData = $request->only(array_merge($this->dataArray, [
                'fulfillment_type', 'payment_method', 'payment_gateway', 'pickup_location_id',
            ]));
            $orderData['user_id'] = $request->user()->id;

            $shippingInfo = $this->resolveShippingPrice((int) ($orderData['governorate_id'] ?? null));
            $shippingPrice = $this->resolveShippingChargeForCart($cart, $checkoutTotals, $shippingInfo);
            if ($freeShippingCoupon) {
                $shippingPrice = 0;
            }
            $governorateId = $shippingInfo['governorate_id'];

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
                // Reuse existing pending order: update with new cart data
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
                    throw new \RuntimeException('Order item sync failed.');
                }
            } else {
                // Create new order
                $order = $this->orderCreationService->createOrder(
                    $orderData, $cart, $checkoutTotals, null, null, null, $shippingPrice, $governorateId,
                );
                if (!$order) {
                    throw new \RuntimeException('Order creation failed.');
                }
                if (!$this->orderCreationService->createOrderItems($order, $cart, $checkoutTotals->giftItems, $checkoutTotals)) {
                    throw new \RuntimeException('Order item creation failed.');
                }
            }

            // The ORDER now owns the inventory reservation. Any failure here
            // (insufficient stock) aborts the whole transaction: no Order, no
            // reservation, and the CartItems survive untouched for retry.
            $this->orderReservationService->reserveForOrder($order);

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

    public function finalizePromotionUsageAfterPayment(Order $order): void
    {
        if ($order->promotion_consumed) {
            return;
        }

        $promotionId = $order->promotion_id ? (int) $order->promotion_id : null;
        if ($promotionId) {
            $this->promotionService->incrementUsage($promotionId);
        }

        if (Schema::hasColumn('orders', 'promotion_consumed')) {
            $order->update(['promotion_consumed' => true]);
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
        return $this->resolveShippingPrice($governorateId);
    }

    public function resolveFreeShippingByThreshold(float $subtotal, ?float $freeShippingOver, float $shippingPrice): float
    {
        if ($freeShippingOver !== null && $subtotal > $freeShippingOver) {
            return 0;
        }
        return $shippingPrice;
    }

    /**
     * D4 — shipping exists only for PHYSICAL lines.
     * Digital-only carts ship nothing; mixed carts apply the normal
     * governorate price, with the free-shipping threshold evaluated against
     * the physical-lines subtotal only.
     */
    public function resolveShippingChargeForCart(Cart $cart, CheckoutTotals $checkoutTotals, array $shippingInfo): float
    {
        $physicalSubtotal = $this->physicalLinesSubtotal($cart);

        if ($physicalSubtotal <= 0) {
            return 0;
        }

        return $this->resolveFreeShippingByThreshold($physicalSubtotal, $shippingInfo['free_shipping_over'], $shippingInfo['price']);
    }

    private function physicalLinesSubtotal(Cart $cart): float
    {
        $cart->loadMissing(['items.product']);

        return round((float) $cart->items->sum(function ($item) {
            if (($item->product?->item_type ?? \Marvel\Enums\ItemType::PHYSICAL) === \Marvel\Enums\ItemType::DIGITAL) {
                return 0;
            }

            return (float) ($item->total_price ?? 0);
        }), 2);
    }

    public function resolveFreeShippingByCoupon(?string $couponDiscountType, float $shippingPrice): float
    {
        if ($couponDiscountType === DiscountType::FREE_SHIPPING) {
            return 0;
        }
        return $shippingPrice;
    }

    private function resolveShippingPrice(?int $governorateId): array
    {
        if (!$governorateId) {
            return ['price' => 0, 'free_shipping_over' => null, 'governorate_id' => null];
        }

        // Governorate/shipping decoupling: existence and shippability are
        // separate concerns. A nonexistent id yields the null triple (FK
        // safety for the order snapshot). An existent-but-inactive
        // governorate keeps its id (snapshot truth = selected delivery
        // area) with zero shipping — master status must not erase the area.
        $governorate = Governorate::query()->where('id', $governorateId)->first();
        if (!$governorate) {
            return ['price' => 0, 'free_shipping_over' => null, 'governorate_id' => null];
        }

        if (!$governorate->status) {
            return ['price' => 0, 'free_shipping_over' => null, 'governorate_id' => $governorateId];
        }

        $shippingPrice = $governorate->shippingPrice()
            ->where('status', true)
            ->first();

        if (!$shippingPrice) {
            return ['price' => 0, 'free_shipping_over' => null, 'governorate_id' => $governorateId];
        }

        return [
            'price' => (float) $shippingPrice->price,
            'free_shipping_over' => $shippingPrice->free_shipping_over !== null ? (float) $shippingPrice->free_shipping_over : null,
            'governorate_id' => $governorateId,
        ];
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


    private static array $allowedOrderTransitions = [
        'pending' => ['pending', 'processing', 'completed', 'cancelled'],
        'processing' => ['processing', 'completed', 'cancelled'],
        'completed' => ['completed', 'delivered'],
        'delivered' => ['delivered'],
        'cancelled' => ['cancelled'],
    ];

    private static array $allowedFulfillmentTransitions = [
        'pending' => ['pending', 'processing', 'cancelled'],
        'processing' => ['processing', 'ready_for_pickup', 'out_for_delivery', 'cancelled'],
        'ready_for_pickup' => ['ready_for_pickup', 'delivered', 'cancelled'],
        'out_for_delivery' => ['out_for_delivery', 'delivered', 'cancelled'],
        'delivered' => ['delivered'],
        'cancelled' => ['cancelled'],
    ];

private function canTransitionOrderStatus(string $from, string $to): bool
    {
        return in_array($to, self::$allowedOrderTransitions[$from] ?? [], true);
    }

    public static function getAllowedOrderStatusTargets(?string $currentStatus): array
    {
        return self::$allowedOrderTransitions[$currentStatus] ?? [];
    }

    private function canTransitionFulfillmentStatus(string $from, string $to): bool
    {
        return in_array($to, self::$allowedFulfillmentTransitions[$from] ?? [], true);
    }

    public function changeOrderStatus($invoiceId, $status, $orderId = null, bool $emitPaymentSuccess = true, ?string $auditReason = null, ?array $auditContext = null, bool $assertPaymentAuthority = true)
    {
        return DB::transaction(function () use ($invoiceId, $status, $orderId, $emitPaymentSuccess, $auditReason, $auditContext, $assertPaymentAuthority) {
            $order = null;
            $transaction = null;

            if ($invoiceId) {
                $transaction = Transaction::where('invoice_id', $invoiceId)->first();
                if ($transaction) {
                    $order = $transaction->order()->lockForUpdate()->first();
                }
            }

            if (!$order && $orderId) {
                $order = Order::whereKey($orderId)->lockForUpdate()->first();
                if ($order) {
                    $transaction = $order->transactions()->latest()->first();
                }
            }

            if (!$order) {
                return false;
            }

            $previousStatus = $order->status;

            if (!$this->canTransitionOrderStatus($previousStatus, $status)) {
                throw new \RuntimeException(
                    __('checkout.invalid_order_status_transition', [
                        'from' => $previousStatus,
                        'to' => $status,
                    ])
                );
            }

            // F-1: completing an UNPAID order is a financial act. An
            // authenticated actor needs payments.mark_paid; system/gateway
            // paths (callbacks, webhooks, zero-value, reconciliation) run
            // unauthenticated or pass $assertPaymentAuthority=false because
            // payment authority was established elsewhere (provider
            // verification or zero amount). Paid orders stay on the normal
            // fulfillment path regardless of actor.
            if ($status === 'completed' && $assertPaymentAuthority && auth()->check()
                && !$this->orderHasSuccessfulPayment($order) && !$this->actorCanMarkPaid()
            ) {
                \Illuminate\Support\Facades\Log::warning('Order completion blocked: missing payments.mark_paid', [
                    'order_id' => $order->id,
                    'actor_id' => auth()->id(),
                    'previous_status' => $previousStatus,
                ]);

                throw new \RuntimeException(
                    __('message.ERROR.PERMISSION_MISSING_PERMISSIONS')
                );
            }

            $updateData = ['status' => $status];

            if ($status === 'completed') {
                // Business contract: completed => payment succeeded.
                if (Schema::hasColumn('orders', 'payment_status')) {
                    $updateData['payment_status'] = Order::PAYMENT_STATUS_SUCCESS;
                }
                if (Schema::hasColumn('orders', 'paid_at')) {
                    $updateData['paid_at'] = $order->getRawOriginal('paid_at') ?? now();
                }
            }
            if ($status === 'completed' && Schema::hasColumn('orders', 'completed_at')) {
                $updateData['completed_at'] = now();
            }

            if ($status === 'cancelled' && $previousStatus !== 'cancelled' && Schema::hasColumn('orders', 'cancelled_at')) {
                $updateData['cancelled_at'] = now();
            }

            $fulfillmentStatusMap = [
                'processing' => Order::FULFILLMENT_STATUS_PROCESSING,
                'completed' => null,
                'cancelled' => Order::FULFILLMENT_STATUS_CANCELLED,
                'delivered' => Order::FULFILLMENT_STATUS_DELIVERED,
            ];
            if (array_key_exists($status, $fulfillmentStatusMap) && Schema::hasColumn('orders', 'fulfillment_status')) {
                $newFulfillment = $fulfillmentStatusMap[$status];
                $currentFulfillment = $order->getRawOriginal('fulfillment_status') ?? Order::FULFILLMENT_STATUS_PENDING;
                if ($newFulfillment === null) {
                    if ($currentFulfillment === Order::FULFILLMENT_STATUS_PENDING) {
                        $updateData['fulfillment_status'] = Order::FULFILLMENT_STATUS_PROCESSING;
                    }
                } elseif ($this->canTransitionFulfillmentStatus($currentFulfillment, $newFulfillment)) {
                    $updateData['fulfillment_status'] = $newFulfillment;
                }
            }

            if (!$order->update($updateData)) {
                return false;
            }

            $actorId = null;
            $actorType = 'system';
            // Record immutable status history (guarded for tests without migration)
            try {
                if (\Illuminate\Support\Facades\Schema::hasTable('order_status_history')) {
                    $oldPaymentStatus = $order->getOriginal('payment_status');
                    $oldFulfillmentStatus = $order->getOriginal('fulfillment_status');
                    // Determine actor type
                    $actorId = null;
                    $actorType = 'system';
                    if (auth()->check()) {
                        $actorId = auth()->id();
                        try {
                            $user = auth()->user();
                            // F-1: manual payment confirmation now carries
                            // payments.mark_paid; the legacy order permission
                            // is still recognized during the transition.
                            $isAdmin = method_exists($user, 'hasAnyPermission')
                                ? $user->hasAnyPermission(['payments.mark_paid', 'update-order-status'])
                                : (method_exists($user, 'hasPermissionTo')
                                    ? ($user->hasPermissionTo('payments.mark_paid') || $user->hasPermissionTo('update-order-status'))
                                    : (method_exists($user, 'can') ? ($user->can('payments.mark_paid') || $user->can('update-order-status')) : false));
                            $actorType = $isAdmin ? 'admin' : 'user';
                        } catch (\Throwable $e) {
                            $actorType = 'user';
                        }
                    } elseif ($invoiceId !== null) {
                        $actorType = 'payment_gateway';
                    }

                    // Only record if status actually changed or payment/fulfillment changed
                    $shouldRecord = $previousStatus !== $order->status
                        || ($oldPaymentStatus !== ($updateData['payment_status'] ?? $order->payment_status))
                        || isset($updateData['fulfillment_status']);

                    if ($shouldRecord) {
                        $historyMetadata = [
                            'invoice_id' => $invoiceId,
                            'old_payment_status' => $oldPaymentStatus,
                            'new_payment_status' => $updateData['payment_status'] ?? $order->payment_status,
                            'old_fulfillment_status' => $oldFulfillmentStatus,
                            'new_fulfillment_status' => $updateData['fulfillment_status'] ?? $order->fulfillment_status,
                        ];
                        $historyNotes = "Status changed from {$previousStatus} to {$order->status}";
                        // Manual-audit context (mark-paid reason, transaction,
                        // action): appended without altering the canonical text.
                        $sanitizedReason = $auditReason !== null && trim($auditReason) !== ''
                            ? \Illuminate\Support\Str::limit(strip_tags($auditReason), 500, '')
                            : null;
                        if ($sanitizedReason !== null) {
                            $historyNotes .= " — Reason: {$sanitizedReason}";
                            $historyMetadata['reason'] = $sanitizedReason;
                        }
                        if (is_array($auditContext)) {
                            foreach ($auditContext as $contextKey => $contextValue) {
                                if (!array_key_exists($contextKey, $historyMetadata)) {
                                    $historyMetadata[$contextKey] = $contextValue;
                                }
                            }
                        }
                        $order->recordStatusChange(
                            oldStatus: $previousStatus,
                            newStatus: $order->status,
                            changedBy: $actorId,
                            changedByType: $actorType,
                            notes: $historyNotes,
                            metadata: $historyMetadata,
                            oldPaymentStatus: $oldPaymentStatus,
                            newPaymentStatus: $updateData['payment_status'] ?? $order->payment_status,
                            oldFulfillmentStatus: $oldFulfillmentStatus,
                            newFulfillmentStatus: $updateData['fulfillment_status'] ?? $order->fulfillment_status
                        );
                    }
                }
            } catch (\Throwable $e) {
                report($e);
            }

            // P2-5: Structured logging + metrics for observability
            try {
                \App\Services\Logging\OrderTrackingLogger::logStatusChange($order, $previousStatus, $order->status, $actorId ?? null, $actorType ?? 'system');
                \App\Services\Metrics\OrderTrackingMetrics::incrementStatusChange($previousStatus, $order->status);
            } catch (\Throwable $e) {
                // logging must never break status transition
            }

            // Business contract: an Invoice is generated exactly once, when the
            // Order performs its FIRST VALID transition AWAY from `pending` —
            // regardless of the target status (processing / completed /
            // cancelled). Same-status re-sets are NOT "leaving pending".
            // Reuses the idempotent InvoiceService (existing-invoice lock),
            // so completion paths that also fire PaymentSucceeded still end
            // with ONE invoice. Failures never block the operational status.
            if ($previousStatus === 'pending'
                && $status !== 'pending'
                && Schema::hasTable('invoices')) {
                try {
                    $this->invoiceService->generateFromOrder($order);
                } catch (\Throwable $e) {
                    report($e);
                }
            }

            if ($status === 'completed') {
                $this->recordCouponUsage($order);

                // Canonical route is self-sufficient for every payment method:
                // committing inventory and finalizing promotion usage are both
                // idempotent no-ops if the online-payment callback already did them.
                $this->finalizePromotionUsageAfterPayment($order);
                $this->orderReservationService->commit($order);

                // P1-3: refresh customer metrics on qualifying completion only
                // (status=completed AND payment_status=payment-success).
                // Deferred to afterCommit so the metrics scan/upsert never
                // extends the Order FOR UPDATE lock hold. Idempotent; never
                // blocks completion on failure.
                $orderIdForMetrics = $order->getKey();
                DB::afterCommit(function () use ($orderIdForMetrics) {
                    try {
                        $fresh = Order::query()->find($orderIdForMetrics);
                        if ($fresh && $fresh->status === Order::ORDER_STATUS_COMPLETED
                            && $fresh->payment_status === Order::PAYMENT_STATUS_SUCCESS
                            && $fresh->user) {
                            $metricsService = $this->customerMetricsService
                                ?? app(\App\Services\Customer\CustomerMetricsService::class);
                            $metricsService->rebuildForUser($fresh->user);

                            // Coupon distribution: metrics-family trees may
                            // now evaluate differently for this user.
                            event(new \App\Events\Coupons\CustomerMetricsUpdated($fresh->user, $fresh->getKey()));
                        }
                    } catch (\Throwable $e) {
                        report($e);
                    }
                });
            }

            if ($transaction) {
                if ($status === 'completed') {
                    $transaction->update([
                        'status' => 'paid',
                        'paid_at' => now(),
                    ]);
                }

                if ($status === 'cancelled') {
                    $transaction->update([
                        'status' => 'failed',
                    ]);
                }
            }

            if ($status === 'cancelled' && $previousStatus !== 'cancelled') {
                // Check if order was paid (committed inventory)
                if ($order->payment_status === Order::PAYMENT_STATUS_SUCCESS
                    && $order->inventory_state === Order::INVENTORY_STATE_COMMITTED) {
                    // Paid order cancellation: restore inventory to stock
                    $this->inventoryRestoreService->restore($order);
                } else {
                    // Unpaid order cancellation: release the active reservation
                    $this->orderReservationService->release($order);
                }

                // Only decrement promotion usage for unpaid cancellations (Rule 17).
                // Paid orders that are cancelled must NOT decrement promotion usage
                // as the promotion benefit was already delivered and consumed.
                if ($order->payment_status !== Order::PAYMENT_STATUS_SUCCESS) {
                    $this->promotionService->decrementUsage($order->promotion_id ? (int) $order->promotion_id : null);
                }

                // CP-08 (INV-04): every pre-payment cancellation path releases
                // the coupon reservation exactly once. Delete-by-order is
                // structurally idempotent (post-payment cancels find no row).
                $this->couponReservationService->release($order);
            }

            event(new OrderStatusChanged($order, $previousStatus, $order->status, $actorId ?? null, $actorType ?? 'system'));

            if ($status === 'cancelled' && $previousStatus !== 'cancelled') {
                event(new OrderCancelled($order));
            }

            if ($status === 'delivered' && $previousStatus !== 'delivered') {
                event(new \App\Events\OrderDelivered($order));
            }

            // completed => payment succeeded: emit the payment-success lifecycle
            // exactly once per completion. Callers that already own the payment
            // event (gateway callback) pass $emitPaymentSuccess = false.
            // D1: listener failures (e.g. invoice generation) must never roll
            // back a valid completion — same guard the callbacks already use.
            if ($status === 'completed' && $emitPaymentSuccess) {
                try {
                    event(new \App\Events\PaymentSucceeded($order));
                } catch (\Throwable $e) {
                    report($e);
                }
            }

            return $order;
        });
    }

    /**
     * F-1 helper: has the order actually been paid (paid transaction or
     * success payment status)? Used to keep paid orders on the normal
     * fulfillment path while blocking financial completion of unpaid ones.
     */
    private function orderHasSuccessfulPayment(Order $order): bool
    {
        if (($order->payment_status ?? null) === Order::PAYMENT_STATUS_SUCCESS) {
            return true;
        }

        return $order->transactions()->where('status', 'paid')->exists();
    }

    /**
     * F-1 helper: does the current authenticated actor hold the dedicated
     * manual-payment permission? The legacy update-order-status permission
     * is intentionally NOT sufficient here.
     */
    private function actorCanMarkPaid(): bool
    {
        try {
            $user = auth()->user();

            if (!$user || !method_exists($user, 'hasAnyPermission')) {
                return false;
            }

            return (bool) $user->hasAnyPermission(['payments.mark_paid']);
        } catch (\Throwable $e) {
            return false;
        }
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
            // changeOrderStatus() so there is one authoritative path.
            // The manual-audit context (actor is resolved inside from the
            // authenticated user; action, transaction and reason ride along)
            // lands on the same immutable history row.
            $this->changeOrderStatus(null, 'completed', $order->id, true, $reason, [
                'action' => 'manual_mark_paid',
                'payment_method' => 'cod',
                'transaction_id' => $transaction->id,
            ]);
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
            // changeOrderStatus() so there is one authoritative path.
            // The manual-audit context (actor is resolved inside from the
            // authenticated user; action, transaction and reason ride along)
            // lands on the same immutable history row.
            $this->changeOrderStatus(null, 'completed', $order->id, true, $reason, [
                'action' => 'manual_mark_paid',
                'payment_method' => 'pay_at_cashier',
                'transaction_id' => $transaction->id,
            ]);
        });
    }

    /**
     * Record coupon usage after successful payment.
     *
     * Fail-closed contract (INV-03, CP-04): an order carrying a coupon
     * discount MUST NOT complete unless usage is committed here. Any policy
     * failure throws CouponConsumptionException so the surrounding
     * transaction rolls back and the order stays non-completed.
     *
     * Idempotency (INV-02): same-order repeats are no-ops via the
     * `coupon_consumed` flag + unique-keyed usage rows.
     *
     * Policy: quota is consumed at payment success and NEVER automatically
     * returned on cancellation/refund (POLICY 5, anti-abuse).
     *
     * Assigned coupons record: coupon_assignment_usages + assignments.used +
     * coupons.used. Public coupons record coupon_usages (firstOrCreate) +
     * coupons.used.
     *
     * Concurrency: coupon / assignment rows are locked (lockForUpdate)
     * before incrementing, so concurrent completions cannot over-consume.
     *
     * @throws \App\Exceptions\CouponConsumptionException
     */
    private function recordCouponUsage($order): void
    {
        if (!$order->coupon || $order->coupon_consumed) {
            return;
        }

        // CP-09: canonical lookup (case-insensitive, trimmed).
        $coupon = Coupon::byCode($order->coupon)->lockForUpdate()->first();
        if (!$coupon) {
            throw new \App\Exceptions\CouponConsumptionException(
                \App\Exceptions\CouponConsumptionException::REASON_COUPON_MISSING,
                __('coupon.not_found'),
                ['order_id' => $order->id, 'code' => $order->coupon],
            );
        }

        // POLICY 4: a missing or expired reservation is revalidated and
        // reacquired before consumption. Never complete on a stale hold,
        // never silently skip consumption when the coupon is unavailable.
        $this->revalidateAndReacquireReservation($order, $coupon);

        // Consumption path follows the ORDER's user grant, not the coupon's
        // global assignment population: assignment-family modes gate
        // unassigned users earlier (NOT_ASSIGNED preserved below), while
        // dynamic / assignment_or_dynamic coupons complete unassigned users
        // via the public single-use path (CouponUsage unique).
        $targetingMode = 'assignment';
        if (Schema::hasTable('coupon_targetings') && method_exists($coupon, 'targeting')) {
            $targetingMode = $coupon->targeting?->mode ?? 'assignment';
        }

        $assignment = null;
        if (Schema::hasTable('coupon_assignments')) {
            $assignment = CouponAssignment::where('coupon_id', $coupon->id)
                ->where('user_id', $order->user_id)
                ->lockForUpdate()
                ->first();
        }

        if ($assignment) {
            if ($assignment->used >= $assignment->max_uses) {
                throw new \App\Exceptions\CouponConsumptionException(
                    \App\Exceptions\CouponConsumptionException::REASON_QUOTA_EXHAUSTED,
                    __('coupon.usage_quota_exceeded'),
                    ['order_id' => $order->id, 'assignment_id' => $assignment->id],
                );
            }

            // POLICY 1: prior public consumption blocks assigned-path reuse.
            $publiclyUsed = CouponUsage::where('coupon_id', $coupon->id)
                ->where('user_id', $order->user_id)
                ->whereNotNull('used_at')
                ->exists();
            if ($publiclyUsed) {
                throw new \App\Exceptions\CouponConsumptionException(
                    \App\Exceptions\CouponConsumptionException::REASON_ALREADY_USED,
                    __('coupon.already_used'),
                    ['order_id' => $order->id, 'coupon_id' => $coupon->id],
                );
            }

            if (CouponAssignmentUsage::where('coupon_assignment_id', $assignment->id)
                ->where('order_id', $order->id)
                ->lockForUpdate()
                ->exists()) {
                // S2: idempotent repeat — usage already committed, but make
                // sure the flag reflects it for future fast-path exits.
                if (Schema::hasColumn('orders', 'coupon_consumed') && !$order->coupon_consumed) {
                    $order->update(['coupon_consumed' => true]);
                }

                return;
            }

            $coupon->increment('used');

            $assignment->increment('used');

            CouponAssignmentUsage::create([
                'coupon_assignment_id' => $assignment->id,
                'order_id' => $order->id,
                'used_at' => now(),
            ]);

            // Consume reservation AFTER successful redemption
            $this->couponReservationService->consume($order);

            DB::afterCommit(function () use ($coupon, $assignment, $order) {
                $remainingUses = max(0, $assignment->max_uses - $assignment->fresh()->used);
                event(new AssignedCouponConsumed(
                    coupon: $coupon,
                    couponAssignment: $assignment,
                    user: $order->user,
                    order: $order,
                    remainingUses: $remainingUses,
                    consumedAt: now(),
                ));
            });
        } else {
            // Assignment-family modes fail closed here (defense in depth —
            // apply/checkout already reject unassigned users). Dynamic and
            // assignment_or_dynamic fall through to the public single-use path.
            if ($targetingMode !== 'dynamic' && $targetingMode !== 'assignment_or_dynamic'
                && Schema::hasTable('coupon_assignments') && $coupon->assignments()->exists()) {
                throw new \App\Exceptions\CouponConsumptionException(
                    \App\Exceptions\CouponConsumptionException::REASON_NOT_ASSIGNED,
                    __('coupon.not_assigned'),
                    ['order_id' => $order->id, 'coupon_id' => $coupon->id],
                );
            }

            $couponUsage = CouponUsage::firstOrCreate(
                [
                    'coupon_id' => $coupon->id,
                    'user_id' => $order->user_id,
                ],
                [
                    'order_id' => $order->id,
                    'used_at' => now(),
                ]
            );

            if (!$couponUsage->wasRecentlyCreated) {
                // Single-use public coupon already consumed by another order:
                // completing this order would grant a free second discount.
                throw new \App\Exceptions\CouponConsumptionException(
                    \App\Exceptions\CouponConsumptionException::REASON_ALREADY_USED,
                    __('coupon.already_used'),
                    ['order_id' => $order->id, 'coupon_id' => $coupon->id],
                );
            }

            $coupon->increment('used');

            // Consume reservation AFTER successful redemption
            $this->couponReservationService->consume($order);
        }

        if (Schema::hasColumn('orders', 'coupon_consumed')) {
            $order->update(['coupon_consumed' => true]);
        }

        // Consumption moves quotas/validity: retire discovery caches so the
        // catalog stops advertising exhausted coupons.
        \App\Services\Coupon\Discovery\CouponDiscoveryCache::invalidate();
    }

    /**
     * POLICY 4: ensure a live reservation exists at completion.
     *
     * A stale (expired) or missing reservation is dropped, eligibility +
     * capacity are revalidated, and a fresh reservation is acquired. Any
     * failure throws so completion cannot proceed fail-open.
     *
     * @throws \App\Exceptions\CouponConsumptionException
     */
    private function revalidateAndReacquireReservation($order, $coupon): void
    {
        $reservation = \App\Models\CouponReservation::where('order_id', $order->id)->first();

        if ($reservation && $reservation->expires_at && $reservation->expires_at->isFuture()) {
            // Live reservation = commitment made at checkout with full
            // validation (≤30min old). Completion proceeds on that hold;
            // state drift inside the TTL window is bounded by design
            // (pre-existing POLICY 4 semantics, identical exposure for all
            // legacy rules). Only a stale/missing reservation triggers full
            // revalidation below.
            return;
        }

        if (!$order->user) {
            // Fail closed: no identity means no eligibility (dynamic rules,
            // assignment checks, and per-user limits cannot be evaluated).
            // Occurs when the account was deleted after checkout.
            throw new \App\Exceptions\CouponConsumptionException(
                \App\Exceptions\CouponConsumptionException::REASON_NOT_ELIGIBLE,
                __('coupon.not_eligible'),
                ['order_id' => $order->id, 'coupon_id' => $coupon->id, 'reason' => 'missing_user'],
            );
        }

        if ($reservation) {
            $reservation->delete();
        }

        // Revalidate against the ORDER's own items (M7: the product gate
        // must hold at completion too — pending-order reuse can edit items
        // between checkout and payment). Orders without items skip the gate
        // exactly as the checkout-time validator does for empty item sets.
        $orderItems = $order->orderItems()->get()->map(
            fn ($item) => ['product_id' => $item->product_id]
        );
        // AREA_IN saved-address remediation: payment-time revalidation
        // evaluates the order user's CURRENT saved addresses (same rule as
        // claim/apply/checkout). The ORDER's persisted delivery area
        // (snapshot) drives shipping only, never coupon eligibility.
        // Applies when no live reservation exists; a live reservation is
        // the checkout-time commitment (see revalidateAndReacquireReservation).
        $validation = \App\Services\Coupon\CouponOrchestrator::validate($coupon, $order->user, $orderItems);
        if (!$validation['valid']) {
            throw new \App\Exceptions\CouponConsumptionException(
                \App\Exceptions\CouponConsumptionException::REASON_NOT_ELIGIBLE,
                $validation['message'] ?? __('coupon.not_eligible'),
                ['order_id' => $order->id, 'coupon_id' => $coupon->id, 'reason' => $validation['reason']],
            );
        }

        try {
            $this->couponReservationService->reserve($order, $coupon);
        } catch (\RuntimeException $e) {
            throw new \App\Exceptions\CouponConsumptionException(
                \App\Exceptions\CouponConsumptionException::REASON_NO_CAPACITY,
                $e->getMessage(),
                ['order_id' => $order->id, 'coupon_id' => $coupon->id],
                $e,
            );
        }
    }

    /**
     * Public checkout must not create an order for inactive products.
     * Inactive = status != publish OR out-of-stock with no available quantity.
     */
    private function assertCartProductsActive(Cart $cart): void
    {
        $productIds = $cart->items->pluck('product_id')->filter()->unique()->values();
        if ($productIds->isEmpty()) {
            return;
        }
        $activeCount = Product::query()->active()->whereIn('id', $productIds)->count();
        if ($activeCount !== $productIds->count()) {
            throw new \InvalidArgumentException(__('product.not_available'));
        }
    }
}