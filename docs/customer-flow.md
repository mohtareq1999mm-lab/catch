# Customer Flow: End-to-End Checkout & Order Journey

> **Source code verified**: Every statement is backed by source code.  
> **File reference format**: `Class@method` or `FilePath:line`  

---

## 1. Browsing & Cart Management

### 1.1 Cart Initiation

The cart is automatically created when the authenticated user adds the first item.

**Endpoint**: `POST /api/v1/general/cart` (Marvel `CartController@store`)  
**Auth**: `auth:sanctum`

**Validation** (`CartCreateRequest`):
- `item.product_id` (required, exists:products)
- `item.quantity` (required, integer, min:1)
- `item.product_variant_id` (optional, exists:product_variants)
- `item.attributes` (optional)
- `item.shipping_method` (optional, default: SCHEDULED)

**Execution**:
1. `CartInventoryService::reserveItem()` locks the product/variant row with `lockForUpdate`
2. Checks available stock: `max(0, stock_quantity - reserved_quantity)` >= requested quantity
3. If insufficient stock, throws exception → 400 response
4. Reserves stock by incrementing `reserved_quantity` on product/variant
5. Creates/updates `CartItem` record with price snapshot from `ProductPricingService`
6. `CartRepository::revalidatePromotion()` clears promotion/discount if present

**Database writes**:
- `cart_items`: INSERT or UPDATE (quantity, price, total_price, reserved_quantity)
- `products.reserved_quantity` or `product_variants.reserved_quantity`: INCREMENT by quantity
- `carts.updated_at`: TOUCH

**Customer sees**: Updated cart with item, quantity, price, current total.

### 1.2 Quantity Change

**Endpoint**: `PUT /api/v1/general/cart/{id}` (`CartController@update`)

**Execution**:
1. `CartInventoryService::reserveItem()` recalculates reservation:
   - Releases old quantity: `reserved_quantity -= old_qty`
   - Reserves new quantity: `reserved_quantity += new_qty`
   - If insufficient stock, rejects the change
2. Updates `CartItem` price/total_price
3. Revalidates promotion (may clear if ineligible)

### 1.3 Cart Item Removal

**Endpoint**: `DELETE /api/v1/general/cart/{id}` (`CartController@destroy`)

**Execution**:
1. `CartInventoryService::releaseItem()` releases reserved stock
2. Deletes `CartItem` record
3. Revalidates promotion → clears if no eligible items remain

### 1.4 View Cart

**Endpoint**: `GET /api/v1/general/cart` (`CartController@index`)

**Auth**: `auth:sanctum`

**Customer sees**: List of items grouped by `shipping_method`:
- `normal_items` (SCHEDULED shipping)
- `fast_items` (FAST shipping)

Each item: product_id, name, image, quantity, price, total_price, attributes, shipping_method, promotion_id, discount_amount, is_gift.

Cart totals: `subtotal`, `coupon_discount`, `total_after_coupon`.

---

## 2. Coupon Application

### 2.1 Apply Coupon

**Endpoint**: `POST /api/v1/general/coupons/apply` → `CouponService@addCouponToCart`  
**Auth**: `auth:sanctum`

**Execution** (`CouponService:34-60`):
1. `CouponOrchestrator::validateByCode()`:
   - `CouponAssignmentValidator::validate()`: checks if user has an assignment with remaining quota + not expired
   - `CouponValidator::validate()`: checks coupon status=active, date range, global limiter, per-user usage, product restrictions
2. If invalid → returns error with reason
3. If valid → stores coupon code on `cart.coupon` column
4. Cart is re-fetched with `CartResource` which calls `CouponCalculator::calculate()` to compute discount

**Coupon Types** (`CouponType` enum):
- `fixed_rate`: Fixed amount subtracted from total
- `percentage`: Percentage discount (with optional `max_discount_amount` cap)
- `free_shipping`: Sets shipping to 0

**Customer sees**: 
- Applied coupon code displayed
- Updated total with discount
- Error message if invalid (expired, exhausted, product mismatch)

### 2.2 Coupon Validation Rules (`CouponValidator:12-60`)

| Condition | Validation | Error |
|-----------|-----------|-------|
| Status | `coupon.status` must be truthy | "Coupon is not active" |
| Date range | `start_date <= now <= end_date` | "Coupon is not valid yet" / "Coupon has expired" |
| Global limiter | `coupon.used < coupon.limiter` | "Coupon usage limit reached" |
| Per-user usage | No existing `CouponUsage` for this user+coupon | "Coupon already used" |
| Product scope | Cart items must match coupon's product list (if restricted) | "Coupon not applicable to selected products" |
| Assignment quota | `assignment.used < assignment.max_uses` | "Assignment quota exhausted" |
| Assignment expiry | `assignment.expires_at > now` | "Assignment has expired" |

---

## 3. Promotion Selection

### 3.1 View Eligible Promotions

**Endpoint**: `GET /api/v1/general/checkout/promotions` → `OrderController@eligiblePromotions`  
**Auth**: `auth:sanctum`

**Execution** (`PromotionService:48-75`):
1. Loads user's cart
2. `PromotionService::eligiblePromotions()` loads all active promotions
3. `PromotionEligibilityResolver::eligible()` filters via strategy pattern:
   - Checks `isValid()` (status, date range, min order amount, required quantity)
   - Checks product scope (all products or specific)
   - Returns `PromotionResult` with discount/gift details
4. Returns payload: `{ eligible_promotions: [...], cart_subtotal, items }`

**Customer sees**: List of eligible promotions with calculated discount amounts and gift items.

### 3.2 Select Promotion

During checkout (`OrderCreateRequest`), customer submits `selected_promotion_id`.

The promotion is applied during `OrderService@addItemsInOrder` → `calculateCheckoutTotals()`.

**How promotion applies** (`PromotionService@applySelectedPromotion`):
1. Clears existing gift items from cart
2. Calls `PromotionEligibilityResolver::resolve()` 
3. For `DiscountOutcome`: proportionally allocates discount across matched items using largest-remainder method (`PromotionApplicator@applyOutcome`)
4. For `GiftOutcome`: picks free gift item, calls `CartInventoryService::reserveGiftItem()` to reserve stock
5. Returns `CheckoutTotals` DTO

**Promotion Mount Types**:
- `percentage`: `discount = matchedSubtotal * value / 100`
- `fixed_rate`: `discount = min(value, matchedSubtotal)` 
- `gift`: Adds free products (no monetary discount, items at $0)

**Customer sees**: Discount applied to eligible items, gift item in cart (if applicable), updated subtotal.

---

## 4. Checkout

### 4.1 Checkout Initiation

**Endpoint**: `POST /api/v1/general/checkout` → `OrderController@checkout`  
**Auth**: `auth:sanctum`  
**Rate limit**: 10 requests/minute (applied by RouteServiceProvider)

**Validation** (`OrderCreateRequest`):
- `name` (required, string)
- `user_phone` (required, string)
- `user_email` (required, email)
- `address` (required, string|array)
- `notes` (optional, string)
- `fulfillment_type` (required, in:delivery,pickup)
- `payment_method` (required, in:online,cod,pay_at_cashier)
- `gateway` (required_if:online, string)
- `governorate_id` (required_if:delivery, exists:governorates)
- `pickup_location_id` (integer|exists_if:pickup)
- `selected_promotion_id` (optional, integer)

**Business Rules**:
- `cod + pickup` → rejected: "COD not available for pickup" (`OrderController:87-89`)
- `pay_at_cashier` → forces `fulfillment_type=pickup` (`OrderCreateRequest`)

### 4.2 Full Checkout Execution Trace

```
OrderController@checkout (line 67-124)
│
├─ 1. Validate request (OrderCreateRequest)
│
├─ 2. Get active cart: CartInventoryService::getActiveCartForUser()
│   └─ Loads cart with items, products, flash_sales, variants
│   └─ If no cart → 400 CART_NOT_FOUND
│
├─ 3. EnsureCartReservation: CartInventoryService::ensureCartReservation()
│   └─ Locks each product/variant row
│   └─ Checks current stock >= reserved_quantity
│   └─ If stock lost → 400 error
│
├─ 4. OrderService::addItemsInOrder(request) [DB TRANSACTION]
│   │
│   ├─ 4a. Lock cart: Cart::lockForUpdate() + items + products + flash_sales + variants
│   │
│   ├─ 4b. RefreshCartItemPrices: recalculates prices via ProductPricingService
│   │   └─ Updates each cart item price/total_price if changed
│   │
│   ├─ 4c. Validate coupon:
│   │   ├─ Lock coupon row
│   │   ├─ CouponOrchestrator::validate() → checks all conditions
│   │   ├─ If free_shipping → sets flag
│   │   └─ If invalid → removes coupon from cart
│   │
│   ├─ 4d. Identify selected promotion from cart items
│   │   └─ Checks cart items for promotion_id and is_gift
│   │
│   ├─ 4e. CalculateCheckoutTotals(cart, promotionId, giftProductId)
│   │   ├─ PromoService::applySelectedPromotion() → discount/gift allocation
│   │   ├─ CalculatePriceByCoupon() → coupon discount on post-promotion total
│   │   └─ Returns CheckoutTotals DTO (subtotal, promoDiscount, couponDiscount, finalTotal, giftItems, coupon)
│   │
│   ├─ 4f. Minimum order check against Settings::minimum_order_amount
│   │   └─ If subtotal < minimum → rollback + error
│   │
│   ├─ 4g. Resolve shipping:
│   │   ├─ Load Governorate + ShippingPrice
│   │   ├─ Free shipping threshold: subtotal > free_shipping_over
│   │   └─ Free shipping coupon: if coupon.type = free_shipping
│   │
│   ├─ 4h. OrderCreationService::createOrder(data, cart, totals, shippingPrice, governorateId)
│   │   ├─ Creates Order record with snapshot values
│   │   ├─ Copies coupon snapshot: code, discount_type, discount, max_discount_amount
│   │   ├─ Copies promotion snapshot: id, code, type, discount
│   │   ├─ Copies pickup location snapshot (if pickup)
│   │   └─ Sets order.status = 'pending'
│   │
│   ├─ 4i. OrderCreationService::createOrderItems(order, cart)
│   │   └─ Creates OrderProduct records with pricing breakdown:
│   │       product_id, variant_id, name, sku, quantity = cart.quantity
│   │       unit_price = cart.price (current calculated price)
│   │       total_price = cart.total_price (after discounts)
│   │       discount_price, flash_sale_price, promotion_discount_amount
│   │       is_gift, promotion_id
│   │
│   ├─ 4j. OrderCreationService::finalizeOrder(order, totals)
│   │   └─ Dispatches OrderCreated event (App\Events\OrderCreated)
│   │
│   └─ 4k. DB::commit()
│       └─ Returns order with orderItems loaded
│
├─ 5. Payment routing (based on payment_method):
│   │
│   ├─ IF "online":
│   │   └─ PaymentCheckoutHandler::handleOnlinePayment(request, order, total, gateway)
│   │       ├─ PaymentGatewayFactory::make(gateway) → GatewayContract
│   │       ├─ gateway->createInvoice(order, amount, callbackUrl, errorUrl)
│   │       ├─ Creates Transaction (status=pending, gateway_transaction_id, invoice_id)
│   │       └─ Returns redirect URL → customer redirected to payment gateway
│   │
│   ├─ IF "cod":
│   │   └─ PaymentCheckoutHandler::handleCodPayment(request, order)
│   │       ├─ Creates Transaction (status=pending, payment_method=cod)
│   │       ├─ CartInventoryService::finalizeItemsByShippingMethod() [via OrderController]
│   │       └─ Returns success with order_id
│   │
│   └─ IF "pay_at_cashier":
│       └─ PaymentCheckoutHandler::handleCashierQrPayment(request, order)
│           ├─ Creates Transaction (status=pending, payment_method=pay_at_cashier)
│           ├─ CashierQrService::generateBase64DataUri(transaction) → QR SVG
│           └─ Returns QR code + transaction UUID
│
└─ 6. Response to customer
```

### 4.3 Post-Checkout: What Customer Sees

**Online Payment**:
- Response: `{ success: true, data: { url: "https://gateway.com/pay/..." } }`
- Customer is redirected to payment gateway
- After payment → callback redirects to frontend success/failure page

**COD**:
- Response: `{ success: true, message: "Order placed successfully via COD", data: { order_id } }`
- Customer sees order confirmation with COD instructions

**Pay at Cashier**:
- Response: `{ success: true, data: { order_id, transaction_uuid, qr_code: "data:image/svg+xml;base64,..." } }`
- Customer receives QR code to scan at store

---

## 5. Payment Processing

### 5.1 Online Payment (MyFatoorah)

**Customer Journey**:
1. Redirected to MyFatoorah payment page
2. Enters card details / selects payment method
3. Completes payment
4. MyFatoorah calls `checkout/callback?paymentId=xxx`

**Callback Flow** (`OrderController@checkoutCallback`, lines 171-373):

```
checkoutCallback(request)
│
├─ 1. Extract paymentId from query/input
├─ 2. Find Transaction by gateway_transaction_id OR invoice_id
├─ 3. GatewayFactory::make(gatewayName)
├─ 4. gateway->verifyPayment(paymentId) → GatewayResult DTO
│
├─ 5. If verification fails:
│   ├─ Update transaction: status=failed, error_message, gateway_response
│   ├─ Fire App\Events\PaymentFailed(order)
│   └─ Redirect to frontend /payment/failed
│
├─ 6. If no order found (orphan payment):
│   └─ Return success redirect (payment recorded but no order linkage)
│
├─ 7. Financial Validation:
│   ├─ Amount mismatch: |gateway.amount - order.total_price| > 0.01 → BLOCK
│   ├─ Currency mismatch: gateway.currency ≠ config('payment.default_currency') → BLOCK
│   └─ If mismatch → block order, fire PaymentFailed, redirect to /payment/failed
│
├─ 8. [DB TRANSACTION] Process payment:
│   ├─ Lock transaction row: Transaction::lockForUpdate()
│   ├─ Lock order row: Order::lockForUpdate()
│   ├─ If order.status !== 'pending' → return (already processed)
│   ├─ Update transaction: status=paid, paid_at=now, gateway_response
│   ├─ Update order: payment_status=SUCCESS, paid_at=now
│   ├─ CartInventoryService::finalizeItemsByShippingMethod() → deducts stock
│   ├─ OrderService::finalizePromotionUsageAfterPayment() → increments promotion.usage
│   └─ OrderService::changeOrderStatus(invoiceId, 'completed')
│       └─ This records coupon usage via recordCouponUsage()
│
├─ 9. Fire App\Events\PaymentSucceeded(order) (after commit)
│
├─ 10. Redirect to frontend /payment/success
│    └─ If mobile: return JSON { status: 'success', order_id }
```

### 5.2 Callback Idempotency

The callback is designed to be idempotent via:
1. `Order::lockForUpdate()` + check `order.status !== 'pending'` → skip if already processed
2. `Transaction::lockForUpdate()` prevents concurrent callbacks from double-processing
3. `payment_status` column prevents re-processing

### 5.3 COD Payment Mark-as-Paid

**Endpoint**: `POST /api/v1/general/checkout/cod/{orderId}/mark-paid`  
**Auth**: `auth:sanctum` + permission:update-order-status (admin only)

**Execution** (`OrderService@markCodAsPaid`):
1. Lock transaction: `Transaction::lockForUpdate()` where payment_method=cod, status=pending
2. Update transaction: status=paid, paid_at=now
3. Update order: status=completed, payment_status=SUCCESS, completed_at=now, fulfillment_status=PROCESSING
4. `recordCouponUsage(order)` → locks assignment row, increments counters
5. `finalizePromotionUsageAfterPayment()` → increments promotion.usage
6. `finalizeInventoryAfterPayment()` → finalizeCart or deductStockForOrder
7. Fire `App\Events\PaymentSucceeded(order)`

### 5.4 Cashier Payment Mark-as-Paid

**Endpoint**: `POST /api/v1/general/checkout/cashier/{orderId}/mark-paid`  
**Auth**: same as COD (admin)

**Execution**: Identical to COD flow, but matches `payment_method=pay_at_cashier`.

---

## 6. Order Status Visibility (Customer)

### 6.1 My Orders List

**Endpoint**: `GET /api/v1/general/orders` → `OrderController@index`  
**Auth**: `auth:sanctum`

**Response**: Paginated list with:
- `order_number`, `status`, `fulfillment_type`, `payment_method`
- `subtotal`, `coupon_discount`, `promotion_discount`, `total`
- `shipping_price`, `fast_shipping_fee`
- `pickup_location` (if pickup)
- `created_at`
- `order_items` with product info, quantities, prices

**Filtering**: Optional `?status=pending` to filter by status.

### 6.2 Customer-Restricted Actions

| Action | Permission | Notes |
|--------|-----------|-------|
| View own orders | Always | Scope `forUser()` in Order model |
| Cancel pending order | Not implemented | No customer-facing cancel endpoint |
| Download invoice | `view invoice` permission OR own order | `InvoiceController@download` checks ownership |
| View invoice | Own order's invoice | `myInvoices` filters by user_id |
| View transaction QR | Own order | `getTransactionQr` checks `order.user_id === auth.id` |

### 6.3 Invoice Download

**Endpoint**: `GET /api/v1/general/invoices/{uuid}/download`  
**Auth**: `auth:sanctum` + throttle:30,1

**Execution** (`InvoiceController@download`):
1. Find invoice by UUID
2. Check ownership: `invoice.user_id === auth.id` OR user has `VIEW_INVOICE` permission
3. If no PDF path → 404 "PDF not yet generated"
4. Record timeline: `InvoiceTimelineService::recordDownloaded()`
5. Update `downloaded_at` (first download only)
6. Return download URL to stored PDF

---

## 7. Invoice Verification (Customer)

### 7.1 QR Scan / Manual Verification

**Endpoint**: `GET /api/v1/general/invoices/verify/{uuid}`  
**Auth**: Optional (throttled: 60/min)

**Execution** (`InvoiceController@verify`):
1. `InvoiceService::verifyInvoice(uuid)`:
   - Find invoice by UUID
   - Compute expected verification hash: `SHA256(snapshot_hash + app_key)`
   - Compare with stored `verification_hash` using `hash_equals()`
2. If not found → 404
3. If hash mismatch → 409 with `{ authentic: false, tampered: true }`
4. If match → 200 with full invoice + order details
5. Increment `verify_count`, update `last_verified_at`, set `verified_at` if first
6. Record timeline: `InvoiceTimelineService::recordVerified()`

**QR Payload** (what the QR contains):
```json
{
  "uuid": "invoice-uuid",
  "invoice_number": "INV-2026-000001",
  "verification_hash": "sha256hex...",
  "issued_at": "2026-07-27T10:00:00+00:00",
  "verification_url": "https://.../api/v1/general/invoices/verify/{uuid}"
}
```

---

## 8. Post-Payment Flow

### 8.1 Immediate After Payment

1. **Order** → `status=completed`, `payment_status=SUCCESS`, `paid_at=now`
2. **Transaction** → `status=paid`, `paid_at=now`
3. **Inventory** → Reserved stock is finalized (deducted permanently)
4. **Promotion** → `promotion.usage` incremented
5. **Coupon** → Usage recorded (assignment increment + audit trail)
6. **Cart** → Items finalized, cart may be cleared

### 8.2 Asynchronous: Invoice Generation

```
PaymentSucceeded fired
  └─ GenerateInvoiceListener (queued, high priority, 5 retries)
      └─ InvoiceService::generateFromOrder(order)
          ├─ DB transaction
          ├─ Check existing invoice (idempotency guard)
          ├─ InvoiceSnapshotService::buildFullSnapshot(order)
          ├─ InvoiceSnapshotValidator::validate(snapshot)
          ├─ SnapshotIntegrityService::computeHash(snapshot)
          ├─ InvoiceNumberService::generateNext() → "INV-2026-000001"
          ├─ Create Invoice record (status=generated)
          ├─ InvoiceTimelineService::recordGenerated()
          ├─ DB::afterCommit():
          │   ├─ InvoiceCreated event → LogInvoiceCreated (logs to file)
          │   └─ GenerateInvoicePdfJob dispatched (low queue)
          └─ Return Invoice
```

### 8.3 Asynchronous: PDF Generation

```
GenerateInvoicePdfJob (queued, low priority, 3 retries)
  ├─ Sets invoice status to 'pdf_generating'
  ├─ Generates PDF (placeholder implementation)
  ├─ On success: status='ready', pdf_generated_at=now, pdf_path
  └─ On failure (after retries exhausted):
      ├─ status='failed'
      ├─ last_generation_error = exception message
      └─ Error is reported
```

---

## 9. Error & Recovery Scenarios

### 9.1 Payment Gateway Timeout

If MyFatoorah does not call back:
- `CancelUnpaidOrders` command (scheduled: `orders:cancel-unpaid`)
- Finds orders with `status=pending AND created_at < now - 72h`
- Cancels each order, releases inventory via `CartInventoryService::expireSingleCart()`

### 9.2 Callback Failure (Gateway Error)

- Transaction updated to `status=failed` with error message
- `PaymentFailed` event fired
- `SendPaymentFailedNotification` listener logs activity
- Inventory NOT restored (order stays in `pending` state)
- `CancelUnpaidOrders` will eventually clean up

### 9.3 Amount Mismatch on Callback

- Logged as security warning
- Transaction updated with error
- `PaymentFailed` event fired
- Order NOT processed
- Customer redirected to failure page

### 9.4 Duplicate Callback

- `Transaction::lockForUpdate()` prevents concurrent processing
- `order.status !== 'pending'` check skips already-processed orders
- Second callback returns but does not re-process

### 9.5 Invoice Generation Failure

- `GenerateInvoiceListener` has 5 retries with exponential backoff: 10s, 30s, 60s, 120s, 300s
- After all retries exhausted: error is reported, logged
- Admin can manually regenerate via `POST /invoices/{id}/regenerate`
- Regeneration re-dispatches `GenerateInvoicePdfJob`

### 9.6 PDF Generation Failure

- `GenerateInvoicePdfJob` has 3 retries
- Final failure: `status=failed`, `last_generation_error` set
- Admin can trigger regeneration via endpoint

---

## 10. Timeline & Deadlines

| Event | Timeout | Handler |
|-------|---------|---------|
| Cart reservation | Not implemented (no expiry on cart reservation) | — |
| Payment processing | 72h (configurable) | `CancelUnpaidOrders` command |
| Invoice PDF generation | Retry 3x × backoff | `GenerateInvoicePdfJob` |
| Callback processing | Synchronous (must return within request) | N/A |

---

## 11. Customer Visibility Matrix

| Screen | Elements Visible | Elements Hidden |
|--------|-----------------|-----------------|
| Order list | order_number, status, total, items | Invoice details (unless loaded) |
| Order detail | All order fields, items, pricing | Timeline events, credit/debit notes summary |
| Invoice | Full invoice, QR, download link | sensitive fields (snapshot_hash internal) |
| Payment QR | QR image, transaction UUID | Gateway response, raw error messages |
| Failed payment | Error message, payment_id | Stack traces, gateway raw response |
| Promotions | Eligible promos with discount amounts | Promotion CRUD, usage counters |
| Coupons | Applied coupon code + discount | Assignment details, usage counts |
