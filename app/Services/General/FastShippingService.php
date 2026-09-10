<?php

namespace App\Services\General;

use App\DTOs\CheckoutTotals;
use App\Services\Checkout\OrderCreationService;
use App\Services\Coupon\CouponValidator;
use Carbon\Carbon;
use Exception;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Marvel\Database\Models\Cart;
use Marvel\Database\Models\Governorate;
use Marvel\Database\Models\Order;
use Marvel\Database\Models\Product;
use Marvel\Database\Repositories\FastShippingRepository;
use Marvel\Enums\DiscountType;
use Marvel\Enums\ShippingMethod;

class FastShippingService
{
    public function __construct(
        private FastShippingRepository $fastShippingRepo,
        private OrderService $orderService,
        private PromotionService $promotionService,
        private CartInventoryService $cartInventoryService,
        private OrderCreationService $orderCreationService,
        private \App\Services\Inventory\OrderReservationService $orderReservationService,
    ) {}

    public function getStatus(): array
    {
        return $this->fastShippingRepo->getStatus();
    }

    public function getFastShippingProducts(Request $request): LengthAwarePaginator
    {
        $limit = $this->getLimit($request);
        $term = trim((string) $request->get('search', ''));

        $query = Product::query()
            ->active()
            ->fastShippingAvailable()
            ->with(['categories', 'variations', 'flash_sales' => fn($q) => $q->valid()])
            ->withAvg(['reviews' => fn($q) => $q->approved()], 'rating')
            ->withCount(['reviews' => fn($q) => $q->approved()]);

        if ($term !== '') {
            $query->where(function ($q) use ($term) {
                $q->where('name', 'like', "%{$term}%")
                    ->orWhere('description', 'like', "%{$term}%");
            });
        }

        return $query->orderByDesc('id')->paginate($limit);
    }

    public function createFastOrder(Request $request): Order
    {
        $user = $request->user();
        $cart = $this->cartInventoryService->getActiveCartForUser($user);

        if (!$cart || !$cart->items()->exists()) {
            throw new \InvalidArgumentException('Cart is empty.');
        }

        $governorate = Governorate::query()->find($request->input('governorate_id'));
        if (!$governorate) {
            throw new \InvalidArgumentException('Governorate not found.');
        }

        $cart->load(['items' => fn($q) => $q->where('shipping_method', ShippingMethod::FAST), 'items.product', 'items.productVariant']);

        if ($cart->items->isEmpty()) {
            throw new \InvalidArgumentException('No fast shipping items in cart.');
        }

        $errors = $this->fastShippingRepo->validateCheckout($governorate, $cart->items);

        if (!empty($errors)) {
            throw new \InvalidArgumentException(implode(' ', $errors));
        }

        try {
            DB::beginTransaction();

            $cart = Cart::query()
                ->where('user_id', $user->id)
                ->where('status', 'active')
                ->lockForUpdate()
                ->with(['items' => fn($q) => $q->where('shipping_method', ShippingMethod::FAST), 'items.product', 'items.productVariant'])
                ->first();

            if (!$cart || $cart->items->isEmpty()) {
                DB::rollBack();
                throw new \InvalidArgumentException('Cart is empty.');
            }

            if ($cart->coupon) {
                $validation = CouponValidator::validateByCode($cart->coupon, $user, $cart->items);
                if (!$validation['valid']) {
                    $cart->update(['coupon' => null]);
                }
            }

            $selectedPromotionId = (int) $request->input('selected_promotion_id') ?: null;
            $selectedGiftProductId = (int) $request->input('selected_gift_product_id') ?: null;
            $checkoutTotals = $this->orderService->calculateCheckoutTotals($cart, $selectedPromotionId, $selectedGiftProductId, ShippingMethod::FAST);
            $fastShippingFee = $this->fastShippingRepo->getFee();
            $eta = $this->fastShippingRepo->calculateEta();

            $governorateId = (int) $request->input('governorate_id');
            $shippingInfo = $this->orderService->getGovernorateShippingInfo($governorateId);
            $shippingPrice = $this->orderService->resolveFreeShippingByThreshold($checkoutTotals->subtotal, $shippingInfo['free_shipping_over'], $shippingInfo['price']);
            $shippingPrice = $this->orderService->resolveFreeShippingByCoupon($checkoutTotals->couponDiscountType, $shippingPrice);

            $pendingOrder = $this->orderCreationService->findPendingOrderForUser((int) $user->id);

            $checkoutTotals = $this->orderService->withTaxes(
                $checkoutTotals,
                $cart,
                $shippingPrice,
                $fastShippingFee
            );

            $orderData = $request->only(['name', 'user_phone', 'user_email', 'address', 'notes',
                'fulfillment_type', 'payment_method', 'payment_gateway', 'pickup_location_id',
            ]);
            $orderData['user_id'] = $user->id;

            if ($pendingOrder) {
                $order = $this->orderCreationService->updateOrder(
                    $pendingOrder,
                    $orderData,
                    $cart,
                    $checkoutTotals,
                    ShippingMethod::FAST,
                    $eta,
                    $fastShippingFee,
                    $shippingPrice,
                    $governorateId,
                );
                $this->orderCreationService->syncOrderItems($order, $cart, $checkoutTotals->giftItems, $checkoutTotals);
                $this->orderCreationService->updateTransactionAmount($order);

                // Pending-order reuse must still own a live reservation.
                $this->orderReservationService->reserveForOrder($order);
            } else {
                $order = $this->orderCreationService->createOrder(
                    $orderData,
                    $cart,
                    $checkoutTotals,
                    ShippingMethod::FAST,
                    $eta,
                    $fastShippingFee,
                    $shippingPrice,
                    $governorateId,
                );

                if (!$order) {
                    DB::rollBack();
                    throw new Exception('Failed to create order.');
                }

                if (!$this->orderCreationService->createOrderItems($order, $cart, $checkoutTotals->giftItems, $checkoutTotals)) {
                    DB::rollBack();
                    throw new Exception('Failed to add items to order.');
                }

                $this->orderReservationService->reserveForOrder($order);
            }

            // The ordered FAST slice leaves the cart; the cart row survives.
            $this->cartInventoryService->clearCheckedOutSlice($cart, ShippingMethod::FAST);

            DB::commit();

            // Dispatch strictly after commit: listeners must never observe an
            // order whose inventory reservation failed.
            $this->orderCreationService->finalizeOrder($order, $checkoutTotals);

            return $order->load(['orderItems.product', 'orderItems.productVariant']);
        } catch (Exception $e) {
            DB::rollBack();
            throw $e;
        }
    }

    public function paginateFastOrders(Request $request): LengthAwarePaginator
    {
        $limit = $this->getLimit($request);
        $userId = (int) $request->user()->id;

        return Order::query()
            ->fast()
            ->forUser($userId)
            ->with(['orderItems.product.media', 'orderItems.productVariant.attributeProducts.attributeValue'])
            ->paginate($limit)
            ->withQueryString();
    }

    private function getLimit(Request $request): int
    {
        $limit = (int) $request->get('limit', 15);

        if ($limit <= 0) {
            return 15;
        }

        return min($limit, 100);
    }
}
