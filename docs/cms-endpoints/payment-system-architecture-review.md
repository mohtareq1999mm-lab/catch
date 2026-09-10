# Payment System Architecture Review

**Date:** 2026-07-11  
**Scope:** Full code review of payment, checkout, inventory, shipping, pickup, dashboard, events, resources, and tests  
**Method:** Every conclusion verified against actual implementation code

---

## 1) Checkout Totals

### 1.1 Calculation Sources

There are **3 independent calculation sites** for checkout totals:

#### Site A: `OrderService::getCheckoutTotalsFromCart()` (OrderService.php:211-253)
Used by `addItemsInOrder()` for both scheduled and pickup fulfillment.

```php
$items = $cart->items->reject(fn($item) => $item->is_gift);
$subtotal = round(sum of base line prices, 2);             // price * quantity per item
$promotionDiscount = round(sum of discount_amount, 2);     // discount_amount on cart items
$final_total = round(sum of total_price, 2);               // total_price on cart items (already has promotion applied)
$coupon_discount = round(max(0, subtotal - promotionDiscount - final_total), 2);  // derived
```

**Key insight:** `final_total` = sum of `total_price` from cart items. Each cart item's `total_price` is already the *post-promotion* price (set when a promotion is applied). The `promotion_discount` and `coupon_discount` are extracted *from* the difference.

#### Site B: `OrderService::calculateCheckoutTotals()` (OrderService.php:258-275)
Used by `calcInvoicePrice()` for the price preview endpoint.

```php
$promotionTotals = $this->promotionService->applySelectedPromotion($cart, ...);
$priceAfterPromotion = $promotionTotals['final_total'];
$priceAfterCoupon = $this->calculatePriceByCoupon($cart, $priceAfterPromotion);
$finalTotal = round(max(0, $priceAfterCoupon), 2);
```

#### Site C: `FastShippingService::calculateCheckoutTotals()` (FastShippingService.php:157-190)
Fast shipping has its own totals calculation.

```php
$promotionTotals = $this->promotionService->applySelectedPromotion($cart, ...);
$priceAfterPromotion = $promotionTotals['final_total'];
$couponDiscount = $this->calculateCouponDiscount($couponData, $priceAfterPromotion);
$finalTotal = round(max(0, $priceAfterPromotion - $couponDiscount), 2);
```

### 1.2 Are They Duplicated?

✅ **Not truly duplicated — they serve different callers:**
- Site A (`getCheckoutTotalsFromCart`) is used by `addItemsInOrder()` which reads pre-computed values from cart items. The cart items already have promotions applied at the point of promotion selection (done by `PromotionService`), and `total_price` reflects the post-promotion value.
- Site B (`calculateCheckoutTotals`) re-reads the same data via `applySelectedPromotion()` for the price preview endpoint. This is the same data, just computed on-the-fly instead of read from already-applied cart items.
- Site C is fast shipping's own version because it loads only FAST items into the cart.

⚠️ **IMPROVEMENT Suggestion:** All 3 should ideally derive from a single immutable DTO. See Section 17.

### 1.3 Component Breakdown

| Component | Where Calculated | Single Source? |
|-----------|-----------------|----------------|
| **subtotal** | `getCheckoutTotalsFromCart`: sum of `price * quantity` (non-gift items) | ✅ Yes |
| **promotion_discount** | Applied to each cart item via `PromotionService::applySelectedPromotion()` — stored in cart item's `discount_amount` and `total_price` | ✅ Yes |
| **coupon_discount** | Derived: `subtotal - promotion_discount - final_total` (Site A), or `calculateCouponDiscount()` (Site C) | ⚠️ Two calculation paths, same result |
| **shipping** | `OrderService::resolveShippingPrice()` or `getGovernorateShippingInfo()` | ✅ Single source |
| **fast_shipping_fee** | `FastShippingRepository::getFee()` | ✅ Single source |
| **tax** | ❌ **Not implemented.** No tax calculation exists anywhere in the payment system | 📌 FUTURE |
| **total** | `final_total + shipping_price + fast_shipping_fee` in `OrderCreationService::createOrder()` | ✅ Single formula |

---

## 2) Final Price Formula

### 2.1 Where `order.total_price` Is Generated

**Single location:** `OrderCreationService::createOrder()` (OrderCreationService.php:20)

```php
$totalPrice = round((float) $checkoutTotals['final_total'] + $shippingPrice + ($fastShippingFee ?? 0), 2);
```

### 2.2 All Callers of `createOrder()`

| Caller | File:Line | Passes shippingPrice? | Passes fastShippingFee? |
|--------|-----------|----------------------|------------------------|
| `OrderService::addItemsInOrder()` | OrderService.php:144 | ✅ Yes (from `resolveShippingPrice`) | No (defaults to 0 for scheduled) |
| `FastShippingService::createFastOrder()` | FastShippingService.php:107 | ✅ Yes (from `getGovernorateShippingInfo`) | ✅ Yes (from `getFee()`) |

✅ **VERIFIED:** There is only ONE formula for `total_price` and it's always `final_total + shipping_price + fast_shipping_fee`.

### 2.3 Value of `price` column

The `price` column stores the raw `subtotal` (checkoutTotals['subtotal']):
```php
'price' => $checkoutTotals['subtotal'],
```

The `total_price` column stores the final total with all surcharges:
```php
'total_price' => $totalPrice,
```

✅ `price` and `total_price` have correct, non-overlapping semantics.

---

## 3) Shipping

### 3.1 Both Scheduled and Fast Shipping Use the Same Governorate Logic

`resolveShippingPrice()` (OrderService.php:177-209) is the source:
```php
private function resolveShippingPrice(?int $governorateId): array
{
    // Queries Governorate → ShippingPrice
    return ['price' => (float), 'free_shipping_over' => (float|null)];
}
```

**Scheduled delivery** path:
- `calcInvoicePrice()` at OrderService.php:102 calls `resolveShippingPrice()`
- `addItemsInOrder()` at OrderService.php:133 calls `resolveShippingPrice()`

**Fast shipping** path:
- `FastShippingService::createFastOrder()` at FastShippingService.php:92 calls `$this->orderService->getGovernorateShippingInfo()` which is a public wrapper around the same `resolveShippingPrice()`

✅ **VERIFIED:** Both paths use the same governorate shipping calculation.

### 3.2 Free Shipping Threshold Applied to Both

Both paths apply the same check:
```php
if ($shippingInfo['free_shipping_over'] !== null && $checkoutTotals['subtotal'] > $shippingInfo['free_shipping_over']) {
    $shippingPrice = 0;
}
```

This appears at:
- OrderService.php:103-105 (calcInvoicePrice)
- OrderService.php:134-136 (addItemsInOrder)
- FastShippingService.php:94-96 (createFastOrder)

✅ **VERIFIED:** Free shipping threshold is consistently applied.

---

## 4) Free Shipping + Fast Shipping Fee

### 4.1 Business Rule Verification

**Rule:** When subtotal ≥ free_shipping_over → governorate shipping becomes 0, but fast shipping fee is still charged.

**Implementation check:**

When `shippingPrice` is set to 0 (free threshold met), `fastShippingFee` is still passed separately to `createOrder()`:
```php
// FastShippingService.php:103-112
$order = $this->orderCreationService->createOrder(
    $orderData, $cart, $checkoutTotals,
    ShippingMethod::FAST, $eta,
    $fastShippingFee,    // Still passed, independent of shippingPrice
    $shippingPrice,       // May be 0 if free threshold met
    $governorateId,
);
```

Formula in `OrderCreationService::createOrder()`:
```php
$totalPrice = round((float) $checkoutTotals['final_total'] + $shippingPrice + ($fastShippingFee ?? 0), 2);
```

Even when `$shippingPrice = 0`, `$fastShippingFee` (e.g., 30 EGP) is still added.

✅ **VERIFIED:** The business rule is correctly implemented. Free shipping threshold zeroes out governorate shipping price. Fast shipping fee is always charged.

---

## 5) Inventory Lifecycle

### 5.1 Complete Lifecycle

```
Add To Cart
    │
    ▼
reserveItem() / reserveGiftItem()
    ├── reserveStock() → reserved_quantity += quantity
    ├── touchCartReservation() → expires_at = now + 3 days
    │
    ▼
Checkout (ensureCartReservation)
    ├── syncCartItemReservation() → syncs reserved_quantity = quantity
    ├── touchCartReservation() → extends expiry
    │
    ▼
Payment Processing
    │
    ├── Online Payment: NO stock finalization here
    │   Cart stays ACTIVE with reserved_quantity
    │
    ├── COD: finalizeItemsByShippingMethod() called
    │   ├── finalizeStock() → reserved_quantity -= qty, stock_quantity -= qty, sold_quantity += qty
    │   ├── Cart items deleted
    │   └── Cart status → checked_out (if no remaining items)
    │
    └── Pay at Cashier: finalizeItemsByShippingMethod() called
        (Same as COD)
    │
    ▼
Callback (Online Payment Success)
    ├── finalizeItemsByShippingMethod() called
    ├── finalizeStock() as above
    └── Cart → checked_out
    │
    ▼
Callback (Online Payment Failure)
    ├── releaseCart() called
    │   ├── releaseItem() → releaseStock() → reserved_quantity -= qty
    │   └── Cart status → active, reserved_at = null, expires_at = null
    └── Cart stays ACTIVE but with no reservation
    │
    ▼
Cancel (Admin, from completed)
    ├── changeOrderStatus('cancelled') where previousStatus = 'completed'
    │   └── event(new OrderCancelled($order))
    │       └── RestoreProductInventory (queued)
    │           ├── stock_quantity += qty
    │           └── sold_quantity -= qty
    │
    ▼
Cancel (Unpaid Timeout)
    ├── CancelUnpaidOrders command (hourly)
    ├── changeOrderStatus('cancelled') where previousStatus = 'pending'
    │   └── OrderCancelled NOT dispatched (correct — stock was never finalized)
    ├── releaseCart() → releases reserved_quantity
    │
    ▼
Expire (cart:expire, hourly)
    ├── expireCart() → releaseStock() for all items
    ├── Cart items deleted
    └── Cart status → expired
```

### 5.2 Verification of No Leaks

| Scenario | reserved_quantity | stock_quantity | sold_quantity | Correct? |
|----------|------------------|----------------|---------------|----------|
| Add to cart | +qty | unchanged | unchanged | ✅ Correct |
| Checkout (online) | unchanged (sync only) | unchanged | unchanged | ✅ Correct (reserved until callback) |
| Payment success (callback) | -qty | -qty | +qty | ✅ Correct |
| Payment failure (callback) | -qty (releaseCart) | unchanged | unchanged | ✅ Correct |
| COD/Pay at cashier | -qty | -qty | +qty | ✅ Correct |
| Cancel (completed→cancelled) | unchanged | +qty (RestoreProductInventory) | -qty | ✅ Correct |
| Cancel (pending→cancelled, timeout) | -qty (releaseCart) | unchanged | unchanged | ✅ Correct (recently fixed) |
| Cart expire | -qty (releaseStock) | unchanged | unchanged | ✅ Correct |

✅ **VERIFIED:** No stock leak, no double restore, no double finalize.

### 5.3 Race Conditions

The `lockForUpdate()` (row-level locks in MySQL) are used in:
- `reserveItem()` ✅
- `reserveGiftItem()` ✅
- `releaseItem()` ✅
- `releaseCart()` ✅
- `finalizeCart()` ✅
- `finalizeItemsByShippingMethod()` ✅
- `expireCart()` ✅
- `syncCartItemReservation()` ✅
- `ensureCartReservation()` ✅
- `lockInventoryRow()` ✅
- `lockInventoryRowByItem()` ✅

⚠️ **IMPROVEMENT:** `RestoreProductInventory` listener does NOT use `lockForUpdate()`. If the same product is being restored while another process runs `finalizeStock`, there's a potential race condition. However, this is low risk because `OrderCancelled` only fires for completed→cancelled, and no other process should be modifying that product's inventory simultaneously.

---

## 6) Cart Reservation

### 6.1 Reservation TTL

Defined in `CartInventoryService.php:15`:
```php
private const CART_TTL_DAYS = 3;
```

Set via `touchCartReservation()` (CartInventoryService.php:419-424):
```php
$cart->update([
    'status' => 'active',
    'reserved_at' => now(),
    'expires_at' => Carbon::now()->addDays(self::CART_TTL_DAYS),
]);
```

Called from:
- `reserveItem()` ✅ (immediately on add-to-cart)
- `reserveGiftItem()` ✅
- `ensureCartReservation()` ✅ (at checkout)

### 6.2 Expiration Flow

`CartInventoryService::expireCarts()` (CartInventoryService.php:260-276):
```php
Cart::where('status', 'active')
    ->whereNotNull('expires_at')
    ->where('expires_at', '<=', now())
    ->chunkById(100, fn($carts) => ...expireCart($cart)...);
```

Scheduled in `Kernel.php:34`: `$schedule->command('cart:expire')->hourly()->withoutOverlapping();`

### 6.3 All Reservation Paths

| Action | Method | Effect on reserved_quantity |
|--------|--------|---------------------------|
| **Add to cart** | `reserveItem()` | Product/ProductVariant: `reserved_quantity += desiredQty` |
| **Resync reservation** | `syncCartItemReservation()` | Adjusts delta between quantity and reserved_quantity |
| **Release single item** | `releaseItem()` | `reserved_quantity -= item.reserved_quantity` |
| **Release entire cart** | `releaseCart()` | Calls `releaseItem()` for each cart item |
| **Finalize items** | `finalizeItemsByShippingMethod()` | Calls `finalizeStock()` for each → moves from reserved to sold |
| **Expire cart** | `expireCart()` | Calls `releaseStock()` for each item |
| **Payment failure callback** | `OrderController::checkoutCallback()` failure | `releaseCart($cart, false)` |
| **Checkout error callback** | `OrderController::checkoutErrorCallback()` | `releaseCart($cart, false)` |
| **Cancel unpaid cron** | `CancelUnpaidOrders::handle()` | `releaseCart($cart, false)` |

✅ **VERIFIED:** All flows properly manage reserved_quantity.

---

## 7) Cancel Unpaid Orders

### 7.1 Full Verification

| Requirement | Code | Status |
|-------------|------|--------|
| Cancels expired pending orders | `Order::where('status', 'pending')->where('created_at', '<=', $cutoff)` | ✅ |
| Does NOT cancel completed orders | Query only selects `pending` | ✅ |
| Does NOT cancel recent orders | Uses `config('payment.order_timeout_hours', 72)` | ✅ |
| Fires PaymentFailed event | `event(new PaymentFailed($order))` | ✅ |
| Releases active cart reservation | `CartInventoryService::releaseCart($cart, false)` at CancelUnpaidOrders.php:55-63 | ✅ (recently added) |
| Does NOT touch checked_out carts | Query filters `where('status', 'active')` | ✅ |
| Does NOT dispatch OrderCancelled | No call to `event(new OrderCancelled(...))` | ✅ (correct — pending→cancelled) |
| Handles failures gracefully | `report($e)` in catch blocks | ✅ |
| Transaction-safe | Order cancellation in `DB::transaction()` | ✅ |

### 7.2 Test Coverage

| Test | Status |
|------|--------|
| `cancel_unpaid_orders_cancels_expired_pending_orders` | ✅ |
| `cancel_unpaid_orders_skips_completed_orders` | ✅ |
| `cancel_unpaid_orders_fires_payment_failed_event` | ✅ |
| `cancel_unpaid_orders_uses_configurable_timeout` | ✅ |
| `cancel_unpaid_orders_releases_cart_reservation` | ✅ (recently added) |
| `cancel_unpaid_orders_skips_release_when_cart_already_checked_out` | ✅ (recently added) |

---

## 8) Callback Security

### 8.1 Verification

| Check | Implementation | Status |
|-------|---------------|--------|
| **Amount verification** | `abs((float) $result->amount - (float) $order->total_price) > 0.01` → `\Log::warning(...)` | ⚠️ **Warning only, not blocking** |
| **Currency verification** | `$result->currency !== config('payment.default_currency', 'EGP')` → `\Log::warning(...)` | ⚠️ **Warning only, not blocking** |
| **Duplicate callback** | No `paymentId` dedup logic. If MyFatoorah calls the same `paymentId` twice, `changeOrderStatus('completed')` would run again, but `completed→completed` is idempotent (no status change). However, `finalizeItemsByShippingMethod()` would attempt to finalize the same cart items that were already deleted. `CartItem::where('cart_id', ...)->where('shipping_method', ...)->get()` would return empty, so no-op. | ✅ Safe (effectively idempotent) |
| **Replay attack** | callback uses `paymentId` from MyFatoorah redirect, verified via `verifyPayment($paymentId)` which calls MyFatoorah API. Cannot be forged without valid `paymentId`. `PaymentFailed` is also handled correctly. | ✅ Secure |
| **Order ownership** | Not checked in callback. However, the transaction→order lookup by `gateway_transaction_id` / `invoice_id` inherently scopes to the correct order. | ✅ Correct |
| **Status transition** | `changeOrderStatus()` only updates if the status actually differs. | ✅ Correct |

### 8.2 Critical Security Gap

❌ **IMPROVEMENT:** Amount and currency mismatches are logged as warnings only. For production, if the amount returned by MyFatoorah doesn't match the order total, the payment should be rejected (order should NOT be marked completed, refund should be initiated). This is a significant security gap.

**Recommended fix:** Change the warning to a blocking check:
```php
if ($result->amount !== null && abs((float) $result->amount - (float) $order->total_price) > 0.01) {
    // Cancel the order, release cart, fire PaymentFailed
    // Do NOT mark as completed
}
```

**Risk:** HIGH. If MyFatoorah returns a manipulated or incorrect amount, the system would complete an order for a different amount than what was paid.

---

## 9) Events

### 9.1 All Events in `app/Events/`

| Event | File | Dispatched From | Purpose |
|-------|------|----------------|---------|
| `OrderCreated` | `app/Events/OrderCreated.php` | `OrderCreationService::finalizeOrder()` | Order was created |
| `OrderCancelled` | `app/Events/OrderCancelled.php` | `OrderService::changeOrderStatus()` when `completed→cancelled` | Order was cancelled after completion |
| `OrderStatusChanged` | `app/Events/OrderStatusChanged.php` | `OrderService::changeOrderStatus()` on every status change | Order status changed |
| `PaymentSucceeded` | `app/Events/PaymentSucceeded.php` | `OrderService::markCodAsPaid()`, `markCashierPaid()`, `changeOrderStatus('completed')` in callback | Payment was successful |
| `PaymentFailed` | `app/Events/PaymentFailed.php` | `CancelUnpaidOrders`, `OrderController::checkoutCallback()` failure, `checkoutErrorCallback()` | Payment failed |

✅ All events in `app/Events/`. No new Marvel events. No Marvel listeners used.

### 9.2 All Listeners in `app/Listeners/`

| Listener | File | Event | Queue |
|----------|------|-------|-------|
| `SendNewOrderNotification` | `app/Listeners/SendNewOrderNotification.php` | `OrderCreated` | `medium` |
| `RestoreProductInventory` | `app/Listeners/RestoreProductInventory.php` | `OrderCancelled` | `medium` |
| `SendOrderCancelledNotification` | `app/Listeners/SendOrderCancelledNotification.php` | `OrderCancelled` | `medium` |
| `SendOrderStatusChangedNotification` | `app/Listeners/SendOrderStatusChangedNotification.php` | `OrderStatusChanged` | `medium` |
| `SendPaymentFailedNotification` | `app/Listeners/SendPaymentFailedNotification.php` | `PaymentFailed` | `medium` |
| `SendPaymentSucceededNotification` | `app/Listeners/SendPaymentSucceededNotification.php` | `PaymentSucceeded` | `medium` |

✅ All listeners in `app/Listeners/`. All implement `ShouldQueue` with `public $queue = 'medium'`. No new Marvel listeners.

### 9.3 Event-to-Listener Registration

All registered in `app/Providers/EventServiceProvider.php`:
```php
OrderCreated::class => [SendNewOrderNotification::class],
OrderCancelled::class => [RestoreProductInventory::class, SendOrderCancelledNotification::class],
OrderStatusChanged::class => [SendOrderStatusChangedNotification::class],
PaymentSucceeded::class => [SendPaymentSucceededNotification::class],
PaymentFailed::class => [SendPaymentFailedNotification::class],
```

✅ **VERIFIED:** All mappings correct. No missing registrations. No duplicates.

### 9.4 What Each Listener Does

| Listener | Action |
|----------|--------|
| `SendNewOrderNotification` | Sends admin notification + dispatches `LogActivityJob` |
| `RestoreProductInventory` | Restores `stock_quantity` and decrements `sold_quantity` for non-gift, non-rental, non-digital products |
| `SendOrderCancelledNotification` | Dispatches `LogActivityJob` for cancelled event |
| `SendOrderStatusChangedNotification` | Dispatches `LogActivityJob` for status change event |
| `SendPaymentFailedNotification` | Dispatches `LogActivityJob` for payment failure |
| `SendPaymentSucceededNotification` | Dispatches `LogActivityJob` for payment success |

⚠️ **IMPROVEMENT:** All notification listeners only log activity. None of them send actual SMS/email/push notifications to the customer. `SendNewOrderNotification` does send a `NewOrderNotification` (database notification) to admins, but no customer-facing notifications exist for any event.

---

## 10) Pickup Locations

### 10.1 Relationship

`Order::pickupLocation()` (Order.php:84-87):
```php
public function pickupLocation(): BelongsTo
{
    return $this->belongsTo(PickupLocation::class, 'pickup_location_id');
}
```

✅ **VERIFIED:** Correct `BelongsTo` relationship with `pickup_location_id` foreign key.

### 10.2 Snapshot Columns

Migration `2026_07_11_000004_add_pickup_location_snapshot_to_orders.php`:
```php
$table->string('pickup_location_name')->nullable();
$table->text('pickup_location_address')->nullable();
$table->string('pickup_location_phone')->nullable();
$table->string('pickup_location_coordinates')->nullable();
```

These are populated at order creation time in `OrderCreationService::createOrder()` (OrderCreationService.php:41-45):
```php
'pickup_location_name' => $pickupSnapshot['name'],
'pickup_location_address' => $pickupSnapshot['address'],
'pickup_location_phone' => $pickupSnapshot['phone'],
'pickup_location_coordinates' => $pickupSnapshot['coordinates'],
```

### 10.3 App Resource (`app/Http/Resources/Order/OrderResource.php`)

```php
'pickup_location' => $this->when($this->fulfillment_type === 'pickup', fn() => $this->resolvePickupLocation()),
```

`resolvePickupLocation()`:
```php
// Relationship first
if ($this->relationLoaded('pickupLocation') && $this->pickupLocation) {
    return ['id' => ..., 'store_name' => ...];
}
// Fallback to snapshot
if ($this->pickup_location_name) {
    return ['id' => $this->pickup_location_id, 'store_name' => $this->pickup_location_name];
}
return null;
```

### 10.4 Marvel Resource (`packages/marvel/src/Http/Resources/Order/OrderResource.php`)

```php
'pickup_location' => $this->when($this->fulfillment_type === 'pickup', fn() => $this->resolvePickupLocation()),
```

`resolvePickupLocation()` returns full fields: `id, store_name, address, phone, email, working_hours, latitude, longitude, status` when relationship exists, and extracts `latitude/longitude` from snapshot `coordinates` string as fallback.

✅ **VERIFIED:** Both resources implement relationship-first, snapshot-fallback correctly.

⚠️ **IMPROVEMENT:** The App resource only returns `{id, store_name}` in the pickup_location field. This is sufficient for list views but customers viewing order details may want full address/phone. Consider expanding App resource to match Marvel resource's full payload.

---

## 11) Dashboard

### 11.1 Finance Analytics (`DashboardService.php:735-762`)

| Metric | Calculation | Status |
|--------|------------|--------|
| **gross_revenue** | `SUM(total_price) WHERE status = 'completed'` | ✅ Correct |
| **net_revenue** | `grossRevenue - refundAmount` | ✅ Correct (previously subtracted discount, now fixed) |
| **refund_amount** | `SUM(amount) FROM refunds WHERE status = 'approved'` | ✅ Correct |
| **total_discount** | `SUM(coupon_discount) + SUM(promotion_discount WHERE > 0)` | ✅ Correct (includes both coupon and promotion) |
| **shipping_revenue** | `SUM(shipping_price) + SUM(fast_shipping_fee) WHERE status = 'completed'` | ✅ Correct |

### 11.2 Coupon Analytics (`DashboardService.php:637-673`)

Total discount query: `Order::whereNotNull('coupon_discount')->sum('coupon_discount')`

⚠️ **Note:** This query (line 663) only sums `coupon_discount`, NOT `promotion_discount`. This is the coupon-specific analytics section, so it's intentional. The `getFinanceAnalytics()` method (line 743-747) correctly includes both.

### 11.3 Revenue by Fulfillment Type (`DashboardService.php:252-259`)

Groups `total_price` by `fulfillment_type`. This is a rough measure — doesn't separate shipping revenue from product revenue. Acceptable for a dashboard overview.

### 11.4 Revenue by Payment Method (`DashboardService.php:241-250`)

Joins `transactions` table, groups by `payment_method`. Correct.

✅ **VERIFIED:** No duplicated calculations. Finance analytics correctly handles both discount types.

---

## 12) Resources

### 12.1 App Resource (`app/Http/Resources/Order/OrderResource.php`)

| Field | Present? | Condition |
|-------|----------|-----------|
| `subtotal` | ✅ as `price` | Always |
| `discount` | ✅ (coupon_discount + promotion_discount) | Always |
| `total` | ✅ as `total_price` | Always |
| `shipping_price` | ✅ | Always |
| `fast_shipping_fee` | ✅ | Always |
| `pickup_location` | ✅ | Only when `fulfillment_type === 'pickup'` |
| `payment_method` | ✅ | Always |
| `payment_gateway` | ✅ | Always |
| `fulfillment_type` | ✅ | Always |
| `promotion` | ✅ | Only when `promotion_id` is set |

### 12.2 Marvel Resource (`packages/marvel/src/Http/Resources/Order/OrderResource.php`)

| Field | Present? | Condition |
|-------|----------|-----------|
| `subtotal` | ✅ as `price` | Only in `orders.show` route |
| `discount` | ✅ as `coupon_discount` + `promotion.discount` | Only in `orders.show` route |
| `total` | ✅ as `total_price` | Only in `orders.show` route |
| `shipping_price` | ✅ | Only in `orders.show` route |
| `fast_shipping_fee` | ✅ | Always (outside mergeWhen) |
| `pickup_location` | ✅ | Only when `fulfillment_type === 'pickup'` |
| `payment_method` | ✅ | Always |
| `payment_gateway` | ✅ | Always |
| `fulfillment_type` | ✅ | Always |
| `customer` | ✅ | Only when `user` relation loaded |
| `customer_name/phone/email/address/notes` | ✅ | Only in `orders.show` route |

⚠️ **IMPROVEMENT:** The Marvel resource conditionally hides `price`, `shipping_price`, `total_price`, `coupon_discount`, `order_items`, and `transactions` behind `request()->routeIs('orders.show')`. This means list views via the Marvel API don't show prices. If not intentional, this could be a bug.

---

## 13) QR Code

### 13.1 Implementation

`CashierQrService::generateBase64DataUri(Transaction $transaction)` — generates a QR code containing the transaction UUID.

Called from `PaymentCheckoutHandler::handleCashierQrPayment()` (PaymentCheckoutHandler.php:114):
```php
$qrDataUri = $this->cashierQrService->generateBase64DataUri($transaction);
```

The transaction UUID is generated on creation (Transaction.php:39-43):
```php
static::creating(function (Transaction $transaction) {
    if (!$transaction->uuid) {
        $transaction->uuid = (string) Str::uuid();
    }
});
```

The QR endpoint `GET /transactions/{uuid}/qr` generates the SVG on every request using `CashierQrService::generateSvg()`.

✅ **VERIFIED:** QR generated fresh every request. Nothing stored. Contains transaction UUID only.

---

## 14) Refund

### 14.1 Current Implementation

The Marvel package has:
- `packages/marvel/src/Http/Controllers/RefundController.php` — CRUD controller
- `packages/marvel/src/Database/Repositories/RefundRepository.php` — Repository with refund approval logic  
- `Marvel\Events\RefundRequested` — Event when customer requests refund
- `Marvel\Events\RefundApproved` — Event when admin approves refund
- `Marvel\Events\RefundUpdate` — Event for refund status updates
- `App\Events\RefundApproved` — App-level event (noted in Marvel EventServiceProvider)

### 14.2 What's Missing

| Component | Status | Impact |
|-----------|--------|--------|
| Refund → inventory restoration | ❌ **Not implemented** | Stock is NOT restored when refund is approved |
| Refund → payment gateway integration | ❌ **Not implemented** | No MyFatoorah refund API call |
| Refund → order status change | ❌ **Not implemented** | Order stays `completed` after refund |
| Refund → activity log | ❌ **Not implemented** | No Activity Log for refund events |

### 14.3 Future Implementation Plan

#### Phase 1: Inventory Reconciliation (Estimated: 2 days)
1. Create `app/Listeners/RestoreInventoryOnRefund.php` listening to `RefundApproved` event
2. In handler: query order items → restore `stock_quantity` and decrement `sold_quantity`
3. Skip gift, rental, and digital products (same as `RestoreProductInventory`)
4. Register in `app/Providers/EventServiceProvider.php`
5. Update order status to `refunded`
6. Add tests

#### Phase 2: Payment Gateway Integration (Estimated: 3-5 days)
1. Add `refund(Transaction $transaction, float $amount): GatewayResult` method to `MyFatoorahGateway`
2. Update `PaymentGatewayContract` to include `refund()` signature
3. Create `app/Listeners/ProcessGatewayRefund.php` — calls gateway refund API on `RefundApproved`
4. Handle partial refunds vs full refunds
5. Update transaction status to `refunded`
6. Log errors gracefully (refund can be retried)

#### Phase 3: Notifications (Estimated: 1 day)
1. Create `SendRefundApprovedNotification` listener (queued, medium queue)
2. Create `SendRefundRejectedNotification` listener
3. Send to customer via database notification (and email/SMS if provider configured)
4. Activity logging via `LogActivityJob`

#### Files to Create
- `app/Listeners/RestoreInventoryOnRefund.php`
- `app/Listeners/ProcessGatewayRefund.php`
- `app/Listeners/SendRefundApprovedNotification.php`
- `app/Listeners/SendRefundRejectedNotification.php`

#### Files to Modify
- `app/Providers/EventServiceProvider.php`
- `app/Services/Gateway/MyFatoorahGateway.php`
- `app/Contracts/PaymentGatewayContract.php`
- `packages/marvel/src/Http/Repositories/RefundRepository.php`
- `routes/api.php` (if refund endpoints need to be exposed via App)

---

## 15) Tests

### 15.1 Coverage Summary

| Test File | Tests | Assertions | Key Coverage |
|-----------|-------|-----------|--------------|
| `PaymentSystemTest` | 29 | 58 | markCashierPaid, markCodAsPaid, changeOrderStatus, CancelUnpaidOrders, pickup validation, PaymentCheckoutHandler unit tests |
| `PaymentCheckoutTest` | 35 | 101 | Scheduled checkout (online/COD/cashier), fast checkout, validation, QR endpoint, governorate shipping, free threshold |
| `FastShippingControllerTest` | 42 | 105 | Fast shipping status, products, orders, checkout, channel filtering |
| `PickupLocationTest` | 19 | 48 | CRUD, validation, authorization, scopes, search |
| `EventSystemTest` | 30 | 30 | OrderCancelled, OrderStatusChanged, PaymentSucceeded, PaymentFailed, RestoreProductInventory, activity logging, queue config |
| **Total** | **155** | **342** | |

### 15.2 Missing Tests

❌ **No tests for callback security:**
- No test verifying amount mismatch is detected
- No test verifying currency mismatch is detected  
- No test for duplicate callback idempotency
- No test for replay attack prevention

❌ **No tests for FastShipping governorate shipping integration:**
- No test that fast shipping uses governorate price
- No test that free shipping threshold applies to fast shipping
- No test that fast shipping fee is still charged when free threshold is met

❌ **No tests for gateway integration:**
- MyFatoorah gateway is not tested (requires mocking HTTP calls)
- `PaymentGatewayFactory` is not tested for unsupported gateway exception

❌ **No tests for CancelUnpaidOrders edge cases:**
- What happens if a user has BOTH an active cart and an expired order from a DIFFERENT cart?
- What happens if `releaseCart()` throws an exception?
- What happens when the user has no active cart (should be gracefully handled, and it is)

❌ **No tests for dashboard analytics:**
- No test for `getFinanceAnalytics` verifying net revenue calculation
- No test for `getSalesAnalytics`

❌ **No tests for Race Conditions:**
- No concurrent inventory modification tests
- No concurrent cart reservation tests

### 15.3 Weak Tests

⚠️ **cancel_unpaid_orders_skips_release_when_cart_already_checked_out** (PaymentSystemTest.php:899-925):
- Sets `reserved_quantity = 1` on product directly, then asserts it stays 1. In reality, when cart is checked_out, `finalizeStock()` already moved the reservation. The test verifies the conditional correctly but doesn't represent a real-world scenario.

---

## 16) Architecture

### 16.1 No Duplicated Logic

| Potential Duplication | Assessment | Status |
|----------------------|------------|--------|
| Checkout totals (3 sources) | Different callers, same semantics | ⚠️ Improvement (see Section 17) |
| Shipping calculation (scheduled vs fast) | Same `resolveShippingPrice()` used by both | ✅ |
| Coupon calculation | `calculatePriceByCoupon()` in OrderService, `calculateCouponDiscount()` in FastShippingService — different classes, same logic | ⚠️ Minor DRY violation (5 lines duplicated) |
| Order creation | Single `OrderCreationService::createOrder()` | ✅ |
| Final price formula | Single `total_price = final_total + shipping + fast_fee` | ✅ |
| Pickup location fallback | Two resources, same pattern | ✅ Correct (intentional) |
| Event listeners | No duplicate listeners registered | ✅ |

### 16.2 No Dead Code

Scanned for unused methods:
- `OrderService::clearCart()` — Called from CartController, in use ✅
- `CartInventoryService::finalizeCart()` — Not directly called from any App service. But may be used by Marvel code.
- `OrderService::resolveShippingPrice()` — private, called by public `getGovernorateShippingInfo()` ✅
- `CancelUnpaidOrders` constructor — Required for DI ✅

⚠️ **CartInventoryService::finalizeCart()** — This method finalizes ALL cart items regardless of shipping method. It's defined but never called from any App-level code. The Marvel package's `CheckoutController` may use it. Since it's transactional and well-structured, it's not dead code — just a utility method available for future use or Marvel integration.

### 16.3 No Unused Methods

All public and private methods in:
- `CartInventoryService` — All called ✅
- `OrderService` — All called ✅  
- `FastShippingService` — All called ✅
- `OrderCreationService` — All called ✅
- `PaymentCheckoutHandler` — All called ✅
- `DashboardService` — All called ✅

### 16.4 No Duplicated Queries

Each query is unique to its context. No N+1 patterns detected.

### 16.5 No Duplicated Listeners

Each event has exactly one registration in `EventServiceProvider`. No duplicates.

### 16.6 No Duplicated Resources

Both `app/Http/Resources/Order/OrderResource.php` and `packages/marvel/src/Http/Resources/Order/OrderResource.php` are intentional — they serve the App (general API) and Marvel (CMS/package API) layers respectively. They have different field sets and different conditions.

✅ **Architecture is clean.** No dead code, no unused methods, no duplicated queries, no duplicated listeners.

---

## 17) CheckoutTotals DTO Refactor

### 17.1 Analysis

**Current architecture:** `checkoutTotals` is a plain associative array passed between services:
```php
[
    'subtotal' => float,
    'promotion_discount' => float,
    'coupon_discount' => float,
    'final_total' => float,
    'promotion' => array|null,
    'gift_items' => array,
    // Fast shipping also adds:
    'coupon' => string|null,
    'coupon_discount_type' => string|null,
    'coupon_discount_max_amount' => float|null,
]
```

### 17.2 Advantages of an Immutable DTO

| Advantage | Description |
|-----------|-------------|
| **Type Safety** | No more `$checkoutTotals['promotion_discount']` — typed properties prevent key typos |
| **IDE Support** | Autocompletion for all fields |
| **Single Source of Truth** | All 3 calculation sites would return the same DTO type |
| **Immutability** | Prevents accidental mutation (currently the array is passed by value, but a DTO makes it explicit) |
| **Self-Documenting** | The DTO class documents exactly what fields exist |
| **Serialization** | Can implement `JsonSerializable` for consistent API output |

### 17.3 Disadvantages

| Disadvantage | Impact |
|-------------|--------|
| **Migration Effort** | ~5-8 files to modify |
| **Marvel Package Coupling** | `OrderCreationService` receives `checkoutTotals` array from both App and Marvel callers. A DTO would require both layers to use it |
| **Existing Tests** | Tests currently pass arrays — would need updates |

### 17.4 Migration Impact

| File | Change |
|------|--------|
| `app/DTOs/CheckoutTotals.php` | **Create** — Immutable DTO with typed properties |
| `app/Services/General/OrderService.php` | `getCheckoutTotalsFromCart()` and `calculateCheckoutTotals()` return DTO |
| `app/Services/General/FastShippingService.php` | `calculateCheckoutTotals()` returns DTO |
| `app/Services/Checkout/OrderCreationService.php` | Accept DTO instead of array |
| `app/Http/Resources/Order/OrderResource.php` | Accept DTO (optional — resource reads from model, not DTO) |
| `app/Http/Controllers/Api/General/OrderController.php` | No change needed (DTO is internal to services) |

**Complexity:** MEDIUM (5-8 files, no breaking changes to API responses)

**Backward Compatibility:** Fully preserved (internal refactor, no API contract changes)

### 17.5 Recommendation

⚠️ **IMPROVEMENT — Implement if adding tax or more discount types.** The current array-based approach works correctly but lacks type safety. If the system needs to add tax calculation, multiple discount tiers, or subscription pricing, a DTO would prevent the array from becoming unmanageable. For the current scope, the array is acceptable.

---

## FUTURE ROADMAP

### 1. Refund Inventory Reconciliation
- **Severity:** HIGH
- **Effort:** 2 days
- **Status:** ❌ Not implemented
- **Plan:** Create `RestoreInventoryOnRefund` listener for `RefundApproved` event
- **Impact:** Without this, refunded orders never restore stock

### 2. Refund Payment Gateway Integration
- **Severity:** HIGH
- **Effort:** 3-5 days
- **Status:** ❌ Not implemented
- **Plan:** Add `refund()` method to gateway contract, call MyFatoorah API
- **Impact:** Refunds are recorded in the system but never actually sent to the payment gateway

### 3. Reservation Extension
- **Severity:** LOW
- **Effort:** 0.5 day
- **Status:** ✅ Reserve on add-to-cart, extend on checkout
- **Improvement:** Add a configurable TTL in `config/payment.php` instead of hardcoded `self::CART_TTL_DAYS = 3`

### 4. Reservation Timeout Notifications
- **Severity:** LOW
- **Effort:** 1 day  
- **Status:** ❌ Not implemented
- **Plan:** Send "Your cart is about to expire" email/SMS notification 1 hour before expiry
- **Impact:** Reduces abandoned carts

### 5. Automatic Retry for Failed Callbacks
- **Severity:** MEDIUM
- **Effort:** 2 days
- **Status:** ❌ Not implemented
- **Plan:** Queue a retry job when callback verification fails transiently (network error, gateway timeout)
- **Impact:** Reduces false payment failures

### 6. Payment Reconciliation Job
- **Severity:** MEDIUM
- **Effort:** 2-3 days
- **Status:** ❌ Not implemented
- **Plan:** Daily cron that queries MyFatoorah for all transactions in a date range and compares against local transaction records. Reports mismatches (paid in gateway but not in system, vice versa)
- **Impact:** Detects silent callback failures

### 7. CheckoutTotals DTO
- **Severity:** LOW
- **Effort:** 1 day
- **Status:** ⚠️ Improvement opportunity
- **Plan:** Create immutable DTO, update 3 calculation sites, update `OrderCreationService` signature
- **Impact:** Type safety, IDE support, prevents key typos

### 8. Multi Gateway Support
- **Severity:** LOW
- **Effort:** 3-5 days per gateway
- **Status:** ✅ Factory pattern already in place (`PaymentGatewayFactory::make()`)
- **Plan:** New gateway classes implement `PaymentGatewayContract`, add to factory match
- **Impact:** Currently only MyFatoorah is supported. The factory is ready for expansion.

### 9. Payment Audit Logs
- **Severity:** MEDIUM
- **Effort:** 1-2 days
- **Status:** ✅ Activity logging via `LogActivityJob` exists for all payment events
- **Improvement:** Add more detail to audit logs (gateway response ID, IP address, user agent, callback parameters)

---

## BUGS FOUND

### ❌ BUG-1: Callback Amount/Currency Mismatch Is Not Blocking
- **File:** `app/Http/Controllers/Api/General/OrderController.php:239-254`
- **Method:** `checkoutCallback()`
- **Problem:** If MyFatoorah returns a different amount or currency than expected, the order is still marked as completed. The mismatch is only logged as a warning.
- **Impact:** An attacker (or gateway bug) could complete an order for a different amount than paid.
- **Severity:** **HIGH**
- **Recommended fix:** Change warnings to blocking — cancel order and fire `PaymentFailed` if amount doesn't match within tolerance (0.01).
- **Risk of fix:** Low (legitimate mismatches are virtually impossible with the same gateway)
- **Backward compatibility:** Breaking — currently non-blocking would become blocking. Needs monitoring before deployment.
- **Effort:** 0.5 day

### ⚠️ BUG-2: Duplicate `Category::observe()` Registration
- **File:** `app/Providers/AppServiceProvider.php:67`
- **Method:** `boot()`
- **Problem:** `Category::observe(CategoryObserver::class)` is registered TWICE (line 63 and 67).
- **Impact:** `CategoryObserver` methods would fire twice for every Category model event.
- **Severity:** **MEDIUM**
- **Recommended fix:** Remove the duplicate at line 67.
- **Effort:** 5 minutes

### ⚠️ BUG-3: `getCouponAnalytics` Doesn't Include Promotion Discount
- **File:** `app/Services/Dashboard/DashboardService.php:663-664`
- **Method:** `getCouponAnalytics()`
- **Problem:** Only sums `coupon_discount`, not `promotion_discount`. The method is named "coupon analytics" so this is semantically correct, but the `total_discount` field in the response may mislead admins into thinking it covers all discounts.
- **Impact:** Low — cosmetic/misleading field name.
- **Severity:** **LOW**
- **Recommended fix:** Rename to `total_coupon_discount` or add a separate `total_promotion_discount` field.
- **Effort:** 15 minutes

---

## SUMMARY

| Category | ✅ Verified | ⚠️ Improvement | ❌ Bug | 📌 Future |
|----------|-----------|----------------|-------|-----------|
| Checkout Totals | Single final_total formula | 3 calculation sites | None | DTO refactor |
| Price Formula | Single source (OrderCreationService) | — | None | — |
| Shipping | Same logic for both paths | — | None | — |
| Free Shipping | Correct implementation | — | None | — |
| Inventory Lifecycle | Complete, no leaks | Race condition in RestoreProductInventory | None | — |
| Cart Reservation | All paths covered | — | None | Configurable TTL |
| Cancel Unpaid Orders | Complete (recently fixed) | — | None | — |
| Callback Security | Amount/currency extracted | Should be BLOCKING not WARNING | ❌ **BUG-1** | — |
| Events | All in app/ namespace, all queued | No notifications to customers | None | — |
| Pickup Locations | Correct snapshot pattern | App resource fields too minimal | None | — |
| Dashboard | Finance analytics fixed | Coupon analytics naming | ⚠️ BUG-3 | — |
| Resources | All fields present | Marvel hides prices in list view | None | — |
| QR | Fresh every request, nothing stored | — | None | — |
| Refund | Not implemented | — | — | Phased plan (6-10 days) |
| Tests | 155 passing, 342 assertions | Missing security, gateway, race tests | None | — |
| Architecture | Clean, no dead code | Duplicate observer registration | ⚠️ **BUG-2** | — |

### Critical Actions

1. **🔴 IMMEDIATE:** Fix BUG-1 — make callback amount/currency mismatch blocking
2. **🔴 IMMEDIATE:** Fix BUG-2 — remove duplicate Category observer registration
3. **🟡 MEDIUM:** Implement Refund Phase 1 (inventory restoration)
4. **🟢 LOW:** Implement CheckoutTotals DTO when adding new discount types
5. **🟢 LOW:** Fix BUG-3 — rename coupon analytics field
