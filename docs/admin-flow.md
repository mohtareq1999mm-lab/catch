# Admin Flow: Order & Payment Management

> **Source code verified**  
> **Permission system**: Spatie Laravel Permission with roles (super_admin, store_owner, staff, customer)

---

## 1. Order Management

### 1.1 Order List (Admin)

**Endpoint**: `GET /api/v1/general/orders` (`OrderController@index`)  
**Auth**: `auth:sanctum` (admin only via middleware)  
**Rate limit**: 10/min

**Filters available** (via the Marvel `OrderController` in `packages/marvel/src/Http/Controllers/Order/OrderController.php`):
- `?status=completed,pending`
- `?user_id=123`
- `?user_email=user@example.com`
- `?promotion_id=5`
- `?product_id=10`
- `?flash_sale_id=3`
- `?shipping_method=SCHEDULED`
- `?fulfillment_type=delivery`
- `?from=2026-01-01&to=2026-07-27`
- `?search=text` (searches order_number, customer name, phone, email)

**Visible data per order**:
- `id`, `order_number`, `status`, `payment_status`, `fulfillment_status`
- `customer` name, email, phone, address
- `price`, `total_price`, `shipping_price`, `coupon_discount`, `promotion_discount`
- `payment_method`, `payment_gateway`
- `created_at`, `paid_at`, `completed_at`, `cancelled_at`
- `order_items` with product details, quantities, pricing
- `transactions` with status, amount, gateway
- `pickup_location` (if applicable)

### 1.2 Order Status Transition Matrix

| Current Status | Allowed Next Status | Trigger | URL/Method |
|---------------|-------------------|---------|------------|
| `pending` | `processing`, `completed`, `cancelled` | Admin update | `PUT /orders/{id}` |
| `processing` | `processing`, `completed`, `cancelled` | Admin update | `PUT /orders/{id}` |
| `completed` | `completed`, `delivered` | Admin update | `PUT /orders/{id}` |
| `delivered` | `delivered` (terminal) | — | — |
| `cancelled` | `cancelled` (terminal) | — | — |

**Disallowed transitions** (will throw `RuntimeException`):
- `delivered → anything`
- `cancelled → anything`
- `pending → delivered` (skip)
- `completed → pending` (reverse)

### 1.3 Fulfillment Status Transitions

Automatically synced when order status changes (`OrderService@changeOrderStatus`):

| Order Status Change | Fulfillment Status Effect |
|-------------------|--------------------------|
| `pending → processing` | `pending → PROCESSING` |
| `pending → completed` | `pending → PROCESSING` (auto-advance) |
| `→ completed` | `fulfillment_status = PROCESSING` (if was PENDING) |
| `→ cancelled` | `fulfillment_status = CANCELLED` |
| `→ delivered` | `fulfillment_status = DELIVERED` |

Manual fulfillment transitions (not implemented in app code — would need extension):
| From | To | 
|------|----|
| `processing` | `ready_for_pickup`, `out_for_delivery` |
| `ready_for_pickup` | `delivered` |
| `out_for_delivery` | `delivered` |

**Note**: `OrderService@changeOrderStatus` also updates fulfillment status, but only for order status changes. Direct fulfillment updates are handled by the Marvel legacy trait.

### 1.4 Status Update Endpoint

**Marvel legacy**: `PUT /api/v1/orders/{id}` (`OrderController@updateOrder`)  
**Marvel new**: `PUT /api/v1/orders/{id}` (`Order\OrderController`)  
**App**: No direct order update endpoint; admin uses `markCodAsPaid` and `markCashierPaid` for payment completion.

**Validation** (`OrderUpdateRequest`):
- `order_status` (in: processing, completed, at_local_facility, out_for_delivery, cancelled)
- `coupon_id`, `shop_id`, `products`, `amount`, `paid_total`, `total` (all optional)

---

## 2. Payment Management

### 2.1 Mark COD as Paid

**Button visible when**: Order has `payment_method=cod` and transaction status=pending  
**Endpoint**: `POST /api/v1/general/checkout/cod/{orderId}/mark-paid`  
**Permission required**: `update-order-status`

**Exact execution** (`OrderService@markCodAsPaid`, lines 604-646):
1. Lock transaction row (`lockForUpdate` where payment_method=cod, status=pending)
2. Lock order row (`lockForUpdate`)
3. Transaction → `status=paid, paid_at=now`
4. Order → `status=completed, payment_status=SUCCESS, completed_at=now`
5. `fulfillment_status → PROCESSING` (if was PENDING)
6. `recordCouponUsage(order)` — locks assignment, increments counters, creates audit trail
7. `finalizePromotionUsageAfterPayment()` — increments promotion.usage
8. `finalizeInventoryAfterPayment()` — deducts stock from cart or order
9. Fire `App\Events\PaymentSucceeded(order)` (after commit, synchronous in this context)

### 2.2 Mark Cashier Payment as Paid

**Button visible when**: Order has `payment_method=pay_at_cashier` and transaction status=pending  
**Endpoint**: `POST /api/v1/general/checkout/cashier/{orderId}/mark-paid`  
**Permission required**: `update-order-status`

**Execution**: Identical to COD flow, matches `payment_method=pay_at_cashier`.

### 2.3 Cancel Unpaid Orders

**Command**: `php artisan orders:cancel-unpaid`  
**Scheduler**: Configurable (not registered in Kernel by default — would need addition)  
**Timeout**: 72 hours (`created_at < now - 72h`)  
**Execution** (`CancelUnpaidOrders:20-90`):
1. Find orders where `status=pending AND payment_method=online AND created_at < threshold`
2. For each:
   - Update order: `status=cancelled, cancelled_at=now`
   - Update transaction: `status=failed`
   - `CartInventoryService::expireSingleCart(order.user_id)` — releases reserved stock
   - Fire `App\Events\OrderCancelled(order)` — triggers inventory restore listener

---

## 3. Invoice Management

### 3.1 Invoice List (Admin)

**Endpoint**: `GET /api/v1/general/invoices` → `InvoiceController@index`  
**Permission**: `VIEW_INVOICES`

**Filters**:
- `?search=INV-2026` (invoice_number or order_number)
- `?status=generated` (any InvoiceStatus value)
- `?order_id=123`
- `?user_id=456`
- `?invoice_series=INV`
- `?currency=EGP`
- `?from=2026-01-01`
- `?to=2026-07-27`
- `?sort_by=created_at|total|status|invoice_number`
- `?sort_direction=asc|desc`

### 3.2 Invoice Detail

**Endpoint**: `GET /api/v1/general/invoices/{id}` → `InvoiceController@show`  
**Permission**: `VIEW_INVOICE`

**Response includes**:
- Full invoice fields (uuid, number, status, amounts)
- `snapshot` — the immutable order snapshot at generation time
- `timeline` — last 10 events (generated, verified, downloaded, etc.)
- `credit_notes_summary` — count + total amount
- `debit_notes_summary` — count + total amount
- `verification_url`, `download_url`, `qr_content`

### 3.3 Invoice Regeneration

**Endpoint**: `POST /api/v1/general/invoices/{id}/regenerate`  
**Permission**: `REGENERATE_INVOICE`

**Allowed when**: `invoice.status` is `failed`, `ready`, or `generated`  
**Effect**:
1. Update status → `pdf_generating`
2. Increment `generation_attempts`
3. Clear `last_generation_error`
4. Record timeline: `recordPdfRegenerated()`
5. Re-dispatch `GenerateInvoicePdfJob`

### 3.4 Invoice Verification (Admin)

**Endpoint**: `GET /api/v1/general/invoices/verify/{uuid}`  
**No auth required** (throttled: 60/min)

**Verification result**:
- `{ authentic: true, invoice: {...}, order: {...}, qr_content: url }` — if hash matches
- `{ authentic: false, tampered: true }` — if verification hash doesn't match (data tampered)

---

## 4. Hidden vs Visible Buttons Matrix

| Admin Action | Visible When | Permission | Endpoint |
|-------------|-------------|------------|----------|
| Mark COD paid | `payment=cod` + transaction `pending` | `update-order-status` | `POST /checkout/cod/{id}/mark-paid` |
| Mark Cashier paid | `payment=pay_at_cashier` + transaction `pending` | `update-order-status` | `POST /checkout/cashier/{id}/mark-paid` |
| Regenerate invoice | invoice `status` in [failed, ready, generated] | `REGENERATE_INVOICE` | `POST /invoices/{id}/regenerate` |
| View invoices list | Any | `VIEW_INVOICES` | `GET /invoices` |
| View single invoice | Any | `VIEW_INVOICE` | `GET /invoices/{id}` |
| View by UUID | Any | `VIEW_INVOICE` | `GET /invoices/uuid/{uuid}` |
| Download invoice | Invoice has PDF generated | `VIEW_INVOICE` OR own order | `GET /invoices/{uuid}/download` |
| Change order status | Order not in terminal state (delivered/cancelled) | `update-order-status` (in Marvel) | `PUT /orders/{id}` |
| Cancel unpaid orders | CLI only | Server access | `orders:cancel-unpaid` |

---

## 5. Notifications (Admin)

| Event | Admin Notification | Method |
|-------|-------------------|--------|
| `OrderCreated` | `SendNewOrderNotification` | `Notification::send()` + `LogActivityJob` |
| `PaymentSucceeded` | `LogActivityJob` | Activity log entry |
| `PaymentFailed` | `LogActivityJob` | Activity log entry |
| `OrderCancelled` | `RestoreProductInventory` | Queue + activity log |
| `OrderStatusChanged` | `LogActivityJob` | Activity log entry |

**Marvel legacy**: `Marvel\Events\OrderCreated` also broadcasts to Pusher private channels for real-time admin notifications.

---

## 6. Activity Log

All order-related actions are logged via `LogActivityJob` (dispatched to the `medium` queue):

| Event | Log Description | Properties |
|-------|----------------|------------|
| Order created | "Order created" | order_id, order_number, total_price, status |
| Payment succeeded | "Payment succeeded" | order_id, order_number, total_price, payment_gateway |
| Payment failed | "Payment failed" | order_id, order_number, total_price, payment_gateway |
| Order status changed | "Order status changed" | order_id, order_number, total_price, status |
| Order cancelled | "Order cancelled" | order_id, order_number, total_price, status |
