# Backend - Order Feature

There are **two** order controllers with different responsibilities.

---

## Controller (Admin) - `packages/marvel/src/Http/Controllers/Order/OrderController.php`

Located in the Marvel package namespace `Marvel\Http\Controllers\Order`. Extends `CoreController`, uses `ApiResponse` trait. Injects `App\Services\General\OrderService`.

### Constructor

```php
$this->middleware('permission:'.Permission::VIEW_ORDERS)->only(['index']);
$this->middleware('permission:'.Permission::VIEW_ORDER)->only(['show']);
$this->middleware('permission:'.Permission::UPDATE_ORDER_STATUS)->only(['updateStatus']);
```

### index(Request $request)

1. Extract `limit` via `getLimit()` — default 15, max 100, min 1
2. Build query: `Order::query()->with(relations)`
3. Apply conditional filters:
   - `status` — exact match (`pending|processing|completed|delivered|cancelled`)
   - `user_id` — exact match
   - `user_email` — `LIKE %...%`
   - `promotion_id` — exact match
   - `promotion_name` — subquery on `promotion_id WHERE promotion.name LIKE %...%`
   - `product_id` — `whereHas('orderItems')`
   - `product_name` — `whereHas('orderItems.product')` with `name LIKE %...%`
   - `flash_sale_name` — nested `whereHas` (orderItems.product.flash_sales)
   - `shipping_method` — exact match
   - `created_from` / `created_to` — date range
   - `search` — `WHERE name LIKE %...% OR user_email LIKE %...% OR user_phone LIKE %...%`
4. Paginate: `->paginate($limit)->withQueryString()`
5. Return `apiResponse(FETCH_DATA_SUCCESSFULLY, 200, true, new OrderCollection($orders))`

### show(Request $request, string $param)

1. `Order::query()->with(relations)->findOrFail($param)` — resolves by ID or tracking number
2. Return `apiResponse(..., new OrderResource($order))`

### updateStatus(OrderStatusUpdateRequest $request, string $param)

The admin status-change endpoint (`PATCH orders/{id}/status`):

1. `Order::query()->find($param)` → **404** if missing
2. `$this->orderService->changeOrderStatus(null, $request->status, $order->id)`
   - Catches `\RuntimeException` (forbidden transition) → **422** with the translated message (`checkout.invalid_order_status_transition`)
3. Return `apiResponse(ORDER_STATUS_UPDATED_SUCCESSFULLY, 200, true, new OrderResource($order->load(relations)))`

All side effects (payment status sync, timestamps, coupon/promotion usage, fulfillment status, events) are handled inside the service within one DB transaction — see `App\Services\General\OrderService` below.

### relations(): array

```php
['user', 'orderItems.product', 'orderItems.productVariant.attributeProducts.attributeValue', 'transactions', 'pickupLocation']
```

### getLimit(Request $request): int

```php
$limit = (int) $request->get('limit', 15);
if ($limit <= 0) return 15;
return min($limit, 100);
```

---

## Controller (Customer) - `app/Http/Controllers/Api/General/OrderController.php`

Located in `App\Http\Controllers\Api\General`. Uses `ApiResponse`, `HasCache`; depends on `OrderService`, `CartInventoryService`, `PaymentGatewayFactory`, `PaymentCheckoutHandler`.

### index(Request $request): JsonResponse

1. `OrderService::paginateForUser($request)` — scopes to `Order::forUser(userId)`, applies optional `status` filter, eager loads relations (see below), paginates (default 15, max 100)
2. Enriches each order item's product with pricing (`ProductService::enrichProductWithPricing`)
3. Caches the result (`FrontendResource::ORDERS->value` + md5(fullUrl))
4. Returns `apiResponse(FETCH_DATA_SUCCESSFULLY, 200, true, new App\OrderCollection($orders))`

### show(Request $request, int $orderId): JsonResponse

1. `OrderService::getOrderForUser($request, $orderId)` — query scoped to the token user; returns `null` for another user's or a nonexistent order → **404**
2. Returns `OrderResource::make($order)`

### invoiceByOrderId(Request $request, int $orderId): JsonResponse — CANONICAL

Route: `GET orders/{orderId}/invoice` + `whereNumber('orderId')`.

1. `Order::where('user_id', auth id)->findOrFail($orderId)` — ownership scoped in query; missing/foreign order both → Handler JSON 404 (no existence leak)
2. `$order->latestInvoice()->first()` — same relation behind `order_has_invoice` / `invoice_id`; `null` for pending → `404 {status:404, message:"Not found", success:false}`
3. Returns `CustomerInvoiceResource::make($invoice)` — resolves the correction when one exists (matches what `invoice_id` advertises)

> **REMOVED:** the legacy `invoice($request, string $uuid)` method and its route `orders/invoice/{uuid}` were deleted 2026-08-22 (superseded by this endpoint).

### checkout(OrderCreateRequest $request)

1. Resolves active cart + ensures inventory reservation
2. Rejects COD + pickup combination (**422**)
3. `OrderService::addItemsInOrder($request)` inside a DB transaction → creates order (`pending`) + items with price snapshots + dispatches `OrderCreated`
4. Delegates payment:
   - `online` → `PaymentCheckoutHandler::handleOnlinePayment()` (gateway redirect/session)
   - `cod` → `PaymentCheckoutHandler::handleCodPayment()` (creates pending COD transaction)
   - `pay_at_cashier` → `PaymentCheckoutHandler::handleCashierQrPayment()` (creates pending cashier transaction)

### markCodAsPaid / markCashierPaid(int $orderId)

Admin endpoints (`POST checkout/cod/{id}/mark-paid`, `POST checkout/cashier/{id}/mark-paid`, permission `update-order-status`). Both delegate to `OrderService::markCodAsPaid($order)` / `markCashierPaid($order)`, which now run the **canonical** lifecycle inside one DB transaction:

1. Latest matching `pending` transaction locked and set to `paid` (+`paid_at`)
2. Promotion usage finalized; inventory finalized (payment-domain effects)
3. `OrderService::changeOrderStatus(null,'completed',$orderId)` — transition validation, column sync (`payment_status=payment-success`, `completed_at`, `paid_at`), coupon usage, fulfillment advance
4. Events emitted by the canonical transition: `OrderStatusChanged` + exactly one `PaymentSucceeded` (invoice via meem-high, payment notifications)
- No pending transaction found → **422**

### checkoutCallback(Request $request) — public online-payment callback

- Verifies payment via gateway factory (`verifyPayment`)
- Failure path: transaction marked `failed`; `App\Events\PaymentFailed` dispatched; mobile JSON or redirect response
- Amount/currency mismatch blocks completion (ignored only against apitest gateway host)
- Success path (inside `DB::transaction`, idempotent — skipped unless order is still `pending`):
  1. Transaction row locked and set to `paid` + `paid_at`
  2. Order `payment_status = payment-success`, `paid_at = now()`
  3. Inventory finalized (cart-based finalize or per-order deduct)
  4. Promotion usage finalized
  5. `OrderService::changeOrderStatus($invoiceId, 'completed')` → fires `OrderStatusChanged`
- After commit: `App\Events\PaymentSucceeded` dispatched once (`$processed` flag)
- Response: JSON envelope for `type=mobile`, otherwise redirect to frontend `/payment/success`

### checkoutErrorCallback(Request $request) — public error callback

- If gateway reports success, redirects/treats as success
- Otherwise marks transaction `failed` (idempotent — skips when already `failed`) and dispatches `App\Events\PaymentFailed`

---

## Service - `app/Services/General/OrderService.php` (single source of truth for status)

### Transition matrices (authoritative)

```php
private static array $allowedOrderTransitions = [
    'pending'    => ['pending', 'processing', 'completed', 'cancelled'],
    'processing' => ['processing', 'completed', 'cancelled'],
    'completed'  => ['completed', 'delivered'],
    'delivered'  => ['delivered'],   // terminal
    'cancelled'  => ['cancelled'],   // terminal
];

private static array $allowedFulfillmentTransitions = [
    'pending'          => ['pending', 'processing', 'cancelled'],
    'processing'       => ['processing', 'ready_for_pickup', 'out_for_delivery', 'cancelled'],
    'ready_for_pickup' => ['ready_for_pickup', 'delivered', 'cancelled'],
    'out_for_delivery' => ['out_for_delivery', 'delivered', 'cancelled'],
    'delivered'        => ['delivered'], // terminal
    'cancelled'        => ['cancelled'], // terminal
];
```

### changeOrderStatus($invoiceId, $status, $orderId = null)

Runs in `DB::transaction`:

1. Resolve order by transaction `invoice_id` (locked) or by `orderId` (locked); return `false` if not found
2. Validate `canTransitionOrderStatus(from, to)` → `RuntimeException(__('checkout.invalid_order_status_transition'))` on violation
3. Apply column updates:
   - `status`
   - on `completed`: `payment_status` forced to `PAYMENT_STATUS_SUCCESS`, `paid_at` (preserved if already set), `completed_at`
   - on first-time `cancelled`: `cancelled_at`
   - fulfillment_status mapped (`processing→processing`, `cancelled→cancelled`, `delivered→delivered`; `completed` advances `pending→processing`), each validated against the fulfillment matrix
4. On `completed`: `recordCouponUsage($order)`; promotion usage finalized; transaction set to `paid`+`paid_at`
5. On `cancelled`: transaction set to `failed`; promotion usage decremented
6. **Invoice — first leave of `pending`**: when `previousStatus === 'pending' && $status !== 'pending'` (same-status re-set excluded), `$this->invoiceService->generateFromOrder($order)` runs inside the same transaction. The service is idempotent (existing-invoice lock), so completion paths that also fire `PaymentSucceeded` still end with exactly ONE invoice. Failures are `report()`ed and never block the operational status change.
7. Events (inside the transaction):
   - `event(new \App\Events\OrderStatusChanged($order))` — always
   - `event(new \App\Events\OrderCancelled($order))` — only when `cancelled && previous !== cancelled`
   - `event(new \App\Events\OrderDelivered($order))` — only when `delivered && previous !== delivered` (drives customer delivery notification, meem-medium)
   - `event(new \App\Events\PaymentSucceeded($order))` — when `completed`, unless the caller owns the payment event: gateway callback passes `$emitPaymentSuccess = false` and fires it itself after commit. Guarantees **one completion = one PaymentSucceeded = one invoice** across all four entry points.

### markCodAsPaid / markCashierPaid / finalizePromotionUsageAfterPayment / finalizeInventoryAfterPayment / recordCouponUsage

See controller summary above. `recordCouponUsage` is idempotent via `coupon_consumed` flag, unique constraints and locks; assigned-coupon consumption fires `AssignedCouponConsumed` after commit.

---

## API Resources

### Marvel (admin)

#### OrderCollection

Extends `ResourceCollection`, wraps `Marvel\OrderResource`. Returns `data` array + `links` (`current_page`, `from`, `to`, `last_page`, `path`, `per_page`, `total`, `next_page_url`, `prev_page_url`).

#### OrderResource

| Scope | Fields |
|-------|--------|
| Always | `id`, `order_number`, `status`, `payment_status`, `shipping_method`, `expected_delivery_at`, `customer` (when `user` relation loaded → `{id, name, email, phone}`), `created_at`, `updated_at`, `fast_shipping_fee`, `pickup_location` (when `fulfillment_type === 'pickup'`) |
| Only on `orders.show` | `customer_name`, `customer_phone`, `customer_email`, `address`, `notes`, `price`, `shipping_price`, `total_price`, `coupon`, `coupon_discount`, `promotion`, `order_items`, `transactions` |

> Note: `updateStatus` returns this resource on route `orders.update-status`; fields merged under `routeIs('orders.show')` are therefore not present in the PATCH response.

### App (customer)

#### OrderCollection

Extends `ResourceCollection`, wraps `App\OrderResource`. Same `links` as Marvel plus `last_page_url` and `first_page_url`.

#### OrderResource

| Field | Source |
|-------|--------|
| `id`, `order_number`, `status` | order columns |
| `subtotal` | `price` (rounded) |
| `discount` | `coupon_discount + promotion_discount` (rounded) |
| `coupon`, `coupon_discount`, `coupon_discount_type` | order columns |
| `promotion_discount` | order column (rounded) |
| `total` | `total_price` (rounded) |
| `converted_total` | `converted_total_price` (rounded) |
| `currency` / `base_currency` / `catalog_currency` | `currency_code` / `base_currency_code` / `catalog_currency_code`, fallback via `CurrencyService::getBaseCode()` |
| `exchange_rate` | `currency_rate` |
| `promotion` | `{id, type, code}` when `promotion_id` set, else `null` |
| `fulfillment_type`, `payment_method`, `payment_gateway` | order columns |
| `shipping_price`, `fast_shipping_fee` | order columns (rounded) |
| `pickup_location` | only when `fulfillment_type === 'pickup'` |
| `invoice_summary` | only when `invoices` relation loaded → `{uuid, invoice_number, status, total, currency, verification_url}` |
| `order_items` | `App\OrderItemResource` collection |
| `order_has_invoice` | `latestInvoice !== null` |
| `invoice_id` | `latestInvoice?->uuid` |
| `created_at` | ISO8601 |

#### OrderItemResource (App)

`id`, `quantity`, `unit_price`, `total_price`, `converted_unit_price`, `converted_total_price` (converted using order `currency_rate`), `promotion_discount_amount`, `is_gift`, `promotion_id`, `product` (`ProductMiniResource` or `{id, name, sku}` fallback), `variant` (`OrderProductVariantResource` or `{id, attributes}` fallback, `null` if no variant).

#### OrderProductVariantResource

`id`, `price`, `current_price`, `in_stock`, `attributes` (when `attributeProducts` loaded → `{value_id, value}` list).

#### CustomerInvoiceResource

Used by `GET /api/v1/general/orders/invoice/{uuid}` — see the Invoice documentation. Returns `uuid`, `invoice_number`, `status`, `subtotal`, `shipping_price`, `total_discount`, `total`, `currency`, `payment_method`, `payment_gateway`, `generated_at`, `pdf_generated_at`, `verification_url`, `download_url`, `snapshot`.

---

## Routes

### Admin — `packages/marvel/src/Rest/Routes.php:165-167` (loaded under `api/v1`, group middleware `auth:sanctum` + `throttle:admin`)

```php
Route::get('orders', [OrderController::class, 'index'])->name('orders.index');
Route::get('orders/{id}', [OrderController::class, 'show'])->name('orders.show');
Route::patch('orders/{id}/status', [OrderController::class, 'updateStatus'])->name('orders.update-status')->whereNumber('id');
```

Permission middleware (`view-orders`, `view-order`, `update-order-status`) comes from the controller constructor.

### Customer — `routes/api.php` (under `v1/general`, `auth:sanctum` + `throttle:authenticated`)

```php
Route::post('checkout/cod/{orderId}/mark-paid', ...)->middleware(['permission:update-order-status']);
Route::post('checkout/cashier/{orderId}/mark-paid', ...)->middleware(['permission:update-order-status']);
Route::any('checkout/callback', ...);            // public (gateway redirect)
Route::any('checkout/error-callback', ...);      // public (gateway error redirect)
Route::get('orders', [OrderController::class, 'index']);
Route::get('orders/{orderId}/invoice', [OrderController::class, 'invoiceByOrderId'])->whereNumber('orderId');
Route::get('orders/{id}', [OrderController::class, 'show'])->whereNumber('id');
```

---

## Status & Payment Values (source of truth: model constants)

`packages/marvel/src/Database/Models/Order.php`:

```php
// Order status — matches DB enum (migration 2026_08_19_000001)
ORDER_STATUS_PENDING    = 'pending'
ORDER_STATUS_PROCESSING = 'processing'
ORDER_STATUS_COMPLETED  = 'completed'
ORDER_STATUS_CANCELLED  = 'cancelled'
ORDER_STATUS_DELIVERED  = 'delivered'

// Payment status (stored values)
PAYMENT_STATUS_PENDING  = 'payment-pending'
PAYMENT_STATUS_SUCCESS  = 'payment-success'
PAYMENT_STATUS_FAILED   = 'payment-failed'
PAYMENT_STATUS_REFUNDED = 'payment-refunded'

// Fulfillment status
FULFILLMENT_STATUS_PENDING           = 'pending'
FULFILLMENT_STATUS_PROCESSING        = 'processing'
FULFILLMENT_STATUS_READY_FOR_PICKUP  = 'ready_for_pickup'
FULFILLMENT_STATUS_OUT_FOR_DELIVERY  = 'out_for_delivery'
FULFILLMENT_STATUS_DELIVERED         = 'delivered'
FULFILLMENT_STATUS_CANCELLED         = 'cancelled'
```

> ⚠️ `Marvel\Enums\OrderStatus` (`order-pending`, `order-processing`, …) is legacy and is **not** used by validation, transitions, or stored values. Do not use its values in API requests.
>
> ⚠️ `Marvel\Enums\PaymentStatus` contains additional display values (`cash_on_delivery`, `wallet`, …) but the `orders.payment_status` column stores only the four model constants above.

---

## Permissions

| Permission | Value | Used by |
|------------|-------|---------|
| `VIEW_ORDERS` | `view-orders` | Admin list |
| `VIEW_ORDER` | `view-order` | Admin detail |
| `UPDATE_ORDER_STATUS` | `update-order-status` | `PATCH orders/{id}/status`, both `mark-paid` endpoints |

## Model - `packages/marvel/src/Database/Models/Order.php`

Key columns:

| Column | Type | Description |
|--------|------|-------------|
| `id` | bigint | Primary key |
| `order_number` | string | Auto-generated `ORD-{id padded to 8}` |
| `user_id` | bigint | FK to users |
| `status` | enum/string | `pending\|processing\|completed\|delivered\|cancelled` |
| `payment_status` | string, nullable | `payment-pending\|payment-success\|payment-failed\|payment-refunded` |
| `fulfillment_status` | string, nullable | see constants above |
| `coupon_consumed` / `promotion_consumed` | boolean | idempotency guards |
| `paid_at` / `completed_at` / `cancelled_at` | timestamp, nullable | lifecycle timestamps |
| `inventory_restored_at` | timestamp, nullable | prevents double restoration |
| `name` | string | Customer name |
| `user_email` | string | Customer email |
| `user_phone` | string | Customer phone |
| `price` | decimal | Subtotal |
| `shipping_price` | decimal | Shipping cost |
| `total_price` | decimal | Grand total |
| `coupon_discount` | decimal | Coupon discount |
| `promotion_discount` | decimal | Promotion discount |
| `shipping_method` | string | `SCHEDULED` \| `FAST` |
| `expected_delivery_at` | timestamp | ETA |
| `notes` | text | Order notes |
| `fulfillment_type` | string | `delivery` or `pickup` |
