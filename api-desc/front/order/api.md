# API Documentation - Order Feature

## Endpoints

---

### 1. List My Orders (Customer)

**GET** `/api/v1/general/orders`

**Purpose:** Retrieve authenticated user's order history.

#### Authentication

| Aspect | Detail |
|--------|--------|
| Required | Yes |
| Guard | `sanctum` |
| Throttle | `throttle:authenticated` |

#### Query Parameters

| Parameter | Type | Description |
|-----------|------|-------------|
| `page` | `integer` | Page number |
| `limit` | `integer` | Items per page (default: 15, max: 100) |
| `status` | `string` | Filter by order status — one of: `pending`, `processing`, `completed`, `delivered`, `cancelled` |

> Only these five values exist. Values like `refunded`, `failed`, `at_local_facility`, `out_for_delivery`, `ready_for_pickup` are NOT order statuses and will simply match nothing.

#### Success Response (200)

```json
{
    "status": 200,
    "message": "Data fetched successfully",
    "success": true,
    "data": {
        "data": [
            {
                "id": 1,
                "order_number": "ORD-00000001",
                "status": "pending",
                "subtotal": 100.0,
                "discount": 0,
                "coupon": null,
                "total": 120.00,
                "converted_total": 120.00,
                "currency": "EGP",
                "base_currency": "EGP",
                "fulfillment_type": "delivery",
                "payment_method": "cod",
                "shipping_price": 20.00,
                "created_at": "2026-08-19T14:00:00+00:00",
                "order_items": [],
                "payment_gateway": null,
                "order_has_invoice": false,
                "invoice_id": null
            }
        ],
        "links": { "current_page": 1, "per_page": 15, "total": 75, "last_page": 5 }
    }
}
```

---

### 2. Order Details (Customer)

**GET** `/api/v1/general/orders/{id}`

**Purpose:** Retrieve the authenticated user's **own** order details. The Order must belong to the authenticated User — another User's Order always returns `404`.

#### Authentication

| Aspect | Detail |
|--------|--------|
| Required | Yes |
| Guard | `sanctum` |

#### Route Parameters

| Parameter | Type | Description |
|-----------|------|-------------|
| `id` | `integer` | Order ID (numeric only, `whereNumber`) |

#### Ownership & Security

- The authenticated User is determined **exclusively** from the token — no `user_id` is accepted from the request.
- Ownership is enforced at the query level (`forUser` scope).
- Requesting another User's Order returns `404 Not Found` (never `403`), so the existence of another User's Order is not revealed.

#### Success Response (200)

Same structure as list item, plus full `order_items`, `pickup_location` (pickup orders), `invoice_summary` indicator fields.

#### Error Responses

| Status | When | Body |
|--------|------|------|
| `401` | Unauthenticated | `{ "status": 401, "success": false }` |
| `404` | Order not found OR not owned by the authenticated User | `{ "status": 404, "message": "Not found", "success": false }` |

---

### 3. Checkout (Create Order)

**POST** `/api/v1/general/checkout`

**Purpose:** Create a new order from the user's cart. Supports COD, online payment, and cashier payment.

#### Authentication

| Aspect | Detail |
|--------|--------|
| Required | Yes |
| Guard | `sanctum` |

#### Request Parameters

| Parameter | Type | Required | Description |
|-----------|------|----------|-------------|
| `name` | `string` | Yes | Customer name |
| `user_phone` | `string` | Yes | Customer phone |
| `user_email` | `string` | Yes | Customer email |
| `address` | `array` | Yes | Delivery address |
| `payment_method` | `string` | No | `online` (default), `cod`, `pay_at_cashier` |
| `fulfillment_type` | `string` | No | `delivery` (default), `pickup` |
| `governorate_id` | `integer` | Required if delivery | Governorate ID |
| `pickup_location_id` | `integer` | Required if pickup | Pickup location ID |
| `gateway` | `string` | No | Payment gateway name (online only; default `myfatoorah`) |
| `notes` | `string` | No | Order notes |

Validation notes:

- COD + pickup combination → **422**
- Empty/missing active cart → **400**

#### Response Behavior

The response depends on the payment method and comes from the payment handlers:

- **COD:** creates a pending transaction; handler-specific JSON payload.
- **Online:** delegates to gateway checkout (redirect/session payload).
- **Cashier:** creates a pending transaction with QR data.

> The order is created with `status = "pending"`. It does NOT become `completed` at checkout time — completion happens via payment success paths below.

---

### 4. Mark COD as Paid (Admin)

**POST** `/api/v1/general/checkout/cod/{orderId}/mark-paid`

**Purpose:** Mark a COD order as paid by admin/staff. Moves order `pending → completed`.

#### Authentication & Permission

| Aspect | Detail |
|--------|--------|
| Required | Yes |
| Guard | `sanctum` |
| Permission | `update-order-status` |

#### Side Effects (one DB transaction)

- Latest pending COD transaction → `paid` + `paid_at`
- Order → `completed`, `payment_status → payment-success`, `completed_at`
- Coupon usage recorded, promotion usage finalized, inventory finalized
- `PaymentSucceeded` event dispatched (queued listeners follow)

#### Responses

| Status | When |
|--------|------|
| `200` | Success — `{ "success": true, "message": "...payment..." }` |
| `403` | Missing permission |
| `404` | Order not found |
| `422` | No pending COD transaction / already paid |

### 4b. Mark Cashier as Paid (Admin)

**POST** `/api/v1/general/checkout/cashier/{orderId}/mark-paid` — identical contract for `pay_at_cashier` orders.

---

### 5. Payment Callbacks (Public)

**ANY** `/api/v1/general/checkout/callback`
**ANY** `/api/v1/general/checkout/error-callback`

**Purpose:** Gateway-facing endpoints (no auth). Verify payment with the gateway, update transaction + order status, fire events.

| Parameter | Type | Description |
|-----------|------|-------------|
| `paymentId` | `string` | Gateway payment ID (query or body) |

Behavior summary:

- Verified failure → transaction `failed`, `PaymentFailed` event, redirect/JSON per client type.
- Verified success (idempotent — only while order is `pending`) → transaction `paid`, order `payment-status success`, inventory + promotion finalized, status transitioned to `completed` through `OrderService::changeOrderStatus()` (fires `OrderStatusChanged`), then `PaymentSucceeded` after commit.

---

### 6. Change Order Status (Admin)

**PATCH** `/api/v1/orders/{id}/status`

**Purpose:** Admin-driven lifecycle transitions (the ONLY endpoint for arbitrary status changes).

#### Authentication & Permission

| Aspect | Detail |
|--------|--------|
| Required | Yes |
| Guard | `sanctum` + `throttle:admin` |
| Permission | `update-order-status` |

#### Path Parameters

| Parameter | Type | Description |
|-----------|------|-------------|
| `id` | `integer` | Numeric Order ID |

#### Request Body

```json
{ "status": "processing" }
```

Valid values (exactly these five): `pending`, `processing`, `completed`, `delivered`, `cancelled`.

#### Transition Rules (enforced server-side)

```text
pending    ──→ processing | completed | cancelled
processing ──→ completed   | cancelled
completed  ──→ delivered
delivered     (terminal)
cancelled     (terminal)
```

Same-status re-set is allowed. Anything else → `422`.

#### Side Effects by Target Status

| Target | What happens |
|--------|--------------|
| `processing` (from `pending`) | fulfillment_status → `processing`; **Invoice created (first leave-pending)**; payment status untouched (`payment-pending`); NO payment event |
| `completed` | **Invoice if not already existing**; payment_status forced to `payment-success`, `paid_at` + `completed_at`, transaction→`paid`, coupon usage consumed, promotion usage finalized, `PaymentSucceeded` fired exactly once → invoice listener no-ops + payment notification |
| `cancelled` | **Invoice (if first transition)**; `cancelled_at`, transaction→`failed`, promotion usage decremented, inventory restored once (paid orders), customer notified asynchronously (DB + Pusher); NO `PaymentSucceeded` |
| `delivered` | fulfillment_status → `delivered`, `OrderDelivered` fired → customer delivery notification (DB + Pusher); invoice never duplicated |

#### Invoice Contract

```text
pending ──(first VALID different status)──→ Invoice created exactly once
```

- Applies whether the first transition is `processing`, `completed`, or `cancelled`.
- Same-status re-set (`pending→pending`) is NOT a leave — no invoice.
- All later transitions never duplicate the invoice.
- Invoice ≠ PaymentSuccess: an unpaid-but-processing order is fully invoiced.

#### Queue Inventory (verified from deploy/supervisor/)

| Queue | Connection | Worker | Order-related jobs |
|-------|-----------|--------|--------------------|
| `meem-high` | database | 2 procs, tries=5, timeout=90 | GenerateInvoiceListener, SendPasswordResetEmailJob, frontend webhooks |
| `meem-medium` | database | 2 procs, tries=3, timeout=900 | all order lifecycle listeners, LogActivityJob, GenerateInvoicePdfJob, notifications |
| `default` | database | consumed by meem-medium worker (`--queue=meem-medium,default`) | framework fallback only |

> A 200 response means status + invoice are committed synchronously; notifications/invoice PDF/webhooks are asynchronous on these queues.

#### Asynchronous Behavior (important for frontend)

```text
PATCH 200 returned
   ↓ (already committed: new status visible on next fetch)
queued listeners run on meem-medium:
   - activity log entry
   - on cancel: inventory restore + customer notification
```

A 200 means the database state changed — it does NOT mean notifications were already delivered.

#### Responses

| Status | When | Body |
|--------|------|------|
| `200` | Transition applied | envelope with updated Marvel `OrderResource` in `data` |
| `401` | Unauthenticated | error envelope |
| `403` | Missing `update-order-status` | error envelope |
| `404` | Unknown order id | `{ "status": 404, "message": "Not found", "success": false }` |
| `422` | Invalid value OR forbidden transition | `{ "status": 422, "message": "<transition/validation message>", "success": false }` |

---

## Enum Reference (actual stored values)

### Order Statuses (DB enum)

```text
pending | processing | completed | delivered | cancelled
```

> ⚠️ The legacy PHP enum class `Marvel\Enums\OrderStatus` uses display strings like `order-pending`. Those are **not valid** request/response values. Always use the raw values above.

### Fulfillment Statuses

```text
pending | processing | ready_for_pickup | out_for_delivery | delivered | cancelled
```

(Internal sync during order-status changes; exposed indirectly.)

### Payment Statuses (stored on orders)

```text
payment-pending | payment-success | payment-failed | payment-refunded
```

---

## Business Rules

1. **Status machine:** strict matrix above; enforced in `App\Services\General\OrderService`. Cancelled and Delivered are terminal.
2. **Single authority:** every status change path (admin PATCH, mark-paid endpoints, gateway callbacks) passes through the same service validation — no direct DB writes from controllers.
3. **Inventory lock:** items reserved at checkout; stock decremented when payment succeeds; restored exactly once on first cancellation (`inventory_restored_at` guard).
4. **Payment verification:** online payments verified via public gateway callbacks; COD/Cashier marked by staff with `update-order-status`.
5. **Coupon + promotion consumption:** consumed when an order reaches `completed`; promotion usage decremented on first-time cancellation; coupon quota is never returned (anti-abuse policy).
6. **Price snapshots:** order items preserve prices at time of order (immutable).
7. **Order number:** auto-generated `ORD-{id}` zero-padded to 8 digits.
8. **Idempotency:** repeated callbacks/mark-paid are guarded by transaction states and `coupon_consumed`/`promotion_consumed` flags; same-status PATCH re-fires events but performs no financial side effects twice.
