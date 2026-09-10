# API Documentation - Order Feature

The Order feature has **two separate endpoint groups** with different response shapes:

| Group | Base path | Controller | Response resources |
|-------|-----------|-----------|--------------------|
| **Customer** (frontend app) | `/api/v1/general/orders` | `App\Http\Controllers\Api\General\OrderController` | `App\Http\Resources\Order\*` |
| **Admin** (dashboard) | `/api/v1/orders` | `Marvel\Http\Controllers\Order\OrderController` | `Marvel\Http\Resources\Order\*` |

All endpoints require `auth:sanctum`. Customer endpoints additionally use `throttle:authenticated`; admin endpoints use `throttle:admin` plus Spatie permissions.

Standard success envelope (both groups, via `Marvel\Traits\ApiResponse`):

```json
{
    "status": 200,
    "message": "Data fetched successfully",
    "success": true,
    "data": {}
}
```

> **Status values:** The API uses the database status values `pending`, `processing`, `completed`, `delivered`, `cancelled`. The legacy `Marvel\Enums\OrderStatus` class (`order-pending`, `order-processing`, ...) is **not** used by any validation or transition rule. See section 5 for the full status machine.

---

## CUSTOMER ENDPOINTS

## 1. My Orders List

**GET** `/api/v1/general/orders`

### Authentication

- `auth:sanctum`
- `throttle:authenticated`

### Query Parameters

| Param | Type | Required | Description |
|-------|------|----------|-------------|
| `limit` | int | No | Per page (default 15, max 100, min 1) |
| `page` | int | No | Page number |
| `status` | string | No | Filter by order status: `pending`, `processing`, `completed`, `delivered`, `cancelled` |

The list is always scoped to the authenticated user (`Order::forUser($userId)`). It cannot be used to read another user's orders.

### Response

```json
{
    "status": 200,
    "message": "Data fetched successfully",
    "success": true,
    "data": {
        "data": [
            {
                "id": 1,
                "order_number": "ORD-20260720-0001",
                "status": "completed",
                "subtotal": 200.0,
                "discount": 20.0,
                "coupon": "SAVE10",
                "coupon_discount": 20.0,
                "coupon_discount_type": "percentage",
                "promotion_discount": 0.0,
                "total": 210.0,
                "converted_total": 210.0,
                "currency": "EGP",
                "base_currency": "EGP",
                "catalog_currency": "EGP",
                "exchange_rate": null,
                "promotion": null,
                "fulfillment_type": "delivery",
                "payment_method": "online",
                "shipping_price": 30.0,
                "fast_shipping_fee": 0.0,
                "created_at": "2026-07-20T10:30:00+00:00",
                "order_items": [
                    {
                        "id": 1,
                        "quantity": 2,
                        "unit_price": 100.0,
                        "total_price": 200.0,
                        "converted_unit_price": 100.0,
                        "converted_total_price": 200.0,
                        "promotion_discount_amount": 0.0,
                        "is_gift": false,
                        "promotion_id": null,
                        "product": {
                            "id": 10,
                            "name": "Widget",
                            "slug": "widget",
                            "price": 100.0,
                            "has_variants": false,
                            "current_price": 100.0,
                            "currency": "EGP",
                            "quantity": 50,
                            "in_stock": true,
                            "discount_active": false,
                            "flash_sale_active": false,
                            "is_fast_shipping_available": false,
                            "ratings": 4.5,
                            "tags": [],
                            "image": {
                                "thumbnail": "http://example.com/storage/products/1/w1.jpg",
                                "original": []
                            }
                        },
                        "variant": null
                    }
                ],
                "payment_gateway": "myfatoorah",
                "order_has_invoice": true,
                "invoice_id": "550e8400-e29b-41d4-a716-446655440000"
            }
        ],
        "links": {
            "current_page": 1,
            "from": 1,
            "to": 1,
            "last_page": 1,
            "path": "http://example.com/api/v1/general/orders",
            "per_page": 15,
            "total": 1,
            "next_page_url": null,
            "prev_page_url": null,
            "last_page_url": "http://example.com/api/v1/general/orders?page=1",
            "first_page_url": "http://example.com/api/v1/general/orders?page=1"
        }
    }
}
```

### Field notes

- `discount` = `coupon_discount + promotion_discount` (computed in the resource).
- `order_has_invoice` (bool) and `invoice_id` (latest invoice **uuid** or `null`) indicate whether the order has an invoice.
- **Canonical invoice lookup:** use the **Order ID** directly — `GET /api/v1/general/orders/{orderId}/invoice` (see next section). Extracting `invoice_id` and calling the legacy UUID route is no longer necessary.
- `invoice_summary` is **only present** when the `invoices` relation is loaded. It is NOT emitted by the list endpoint (only `latestInvoice` is loaded).
- `pickup_location` is only emitted when `fulfillment_type === "pickup"`.
- `product` is a `ProductMiniResource` when the relation is loaded, otherwise `{ id, name, sku }`.
- `variant` is an `OrderProductVariantResource` when loaded, otherwise `{ id, attributes }`, otherwise `null`.
- Monetary values are rounded to 2 decimals (`roundMoney`).

## 2. Customer Order Invoice by Order ID (canonical)

**GET** `/api/v1/general/orders/{orderId}/invoice`

### Authentication

- `auth:sanctum`
- `throttle:authenticated`
- `{orderId}` numeric-only (`whereNumber`)

### Resolution

```
Order ID → load Order scoped to authenticated user
        → missing OR foreign order → 404 (identical body, no existence leak)
        → resolve latestInvoice()  (same relation behind order_has_invoice / invoice_id)
        → null (pending order, nothing created yet) → 404 { status:404, message:"Not found", success:false }
        → 200 + CustomerInvoiceResource (same payload as the legacy UUID route)
```

### Lifecycle contract

```text
pending                    → GET invoice → 404   (no Invoice exists)
pending → processing       → Invoice created once → 200
processing → completed     → same Invoice        → 200
completed → delivered      → same Invoice        → 200
pending → cancelled        → Invoice created once → 200
```

If a correction document exists, this endpoint returns the **latest** document — exactly the invoice the list's `invoice_id` already points to. The original remains reachable through the legacy UUID route.

### Response

Identical JSON to the legacy endpoint below (`CustomerInvoiceResource`, incl. `snapshot`).

---

## ~~2b~~. Legacy UUID Invoice View — REMOVED

> **Removed (2026-08-22).** `GET /api/v1/general/orders/invoice/{uuid}` and `OrderController::invoice()` no longer exist; requests fail routing with 404. Canonical lookup: **endpoint 2** (`GET /api/v1/general/orders/{orderId}/invoice`).

### Authentication

- `auth:sanctum`
- `throttle:authenticated`

### Authorization

- Only the **owner** of the invoice's order can view it.
- Non-owner: **403** `You are not authorized to perform this action`.
- Not found: **404**.

### Response

```json
{
    "status": 200,
    "message": "Data fetched successfully",
    "success": true,
    "data": {
        "uuid": "550e8400-e29b-41d4-a716-446655440000",
        "invoice_number": "INV-2026-000001",
        "status": "ready",
        "subtotal": 200.0,
        "shipping_price": 30.0,
        "total_discount": 20.0,
        "total": 210.0,
        "currency": "EGP",
        "payment_method": "online",
        "payment_gateway": "myfatoorah",
        "generated_at": "2026-07-20T10:35:00+00:00",
        "pdf_generated_at": "2026-07-20T10:40:00+00:00",
        "verification_url": "http://example.com/api/v1/general/invoices/verify/550e8400-e29b-41d4-a716-446655440000",
        "snapshot": {
            "order": { "id": 1, "order_number": "ORD-20260720-0001" },
            "customer": { "name": "Ahmed" },
            "pricing_breakdown": {
                "subtotal": 200.0,
                "total_discount": 20.0,
                "total": 210.0
            }
        }
    }
}
```

This uses `App\Http\Resources\Invoice\CustomerInvoiceResource`. `snapshot` is only present when the invoice has stored data. `download_url` in this resource points to a route that does NOT exist - use `GET /api/v1/invoices/{uuid}/download` for downloads (see the Invoice documentation).

> **Legacy route retained for backward compatibility.** Canonical lookup is endpoint 2 (Order ID). This UUID variant stays because existing clients/tests consume it (`OrderInvoiceEndpointTest`, frontend docs).

---

## ADMIN ENDPOINTS

## 3. Admin Order List

**GET** `/api/v1/orders`

### Authentication

- `auth:sanctum`
- `throttle:admin`
- Permission: **`view-orders`** (Spatie, `Permission::VIEW_ORDERS`)

### Query Parameters

| Param | Type | Required | Description |
|-------|------|----------|-------------|
| `limit` | int | No | Per page (default 15, max 100) |
| `page` | int | No | Page number |
| `status` | string | No | Filter by order status (`pending`, `processing`, `completed`, `delivered`, `cancelled`) |
| `user_id` | int | No | Filter by user ID |
| `user_email` | string | No | Partial match on user email |
| `promotion_id` | int | No | Filter by promotion ID |
| `promotion_name` | string | No | Orders whose promotion name matches (`LIKE`) |
| `product_id` | int | No | Orders containing this product |
| `product_name` | string | No | Orders containing product with name like |
| `flash_sale_name` | string | No | Orders with flash sale products (title LIKE) |
| `shipping_method` | string | No | Filter by shipping method |
| `created_from` | date | No | Start date (Y-m-d) |
| `created_to` | date | No | End date (Y-m-d) |
| `search` | string | No | Search `name`, `user_email`, `user_phone` (`LIKE`) |

### Response

The list uses `Marvel\Http\Resources\Order\OrderCollection`. List items do **NOT** include `order_items`, `transactions`, or financial fields (those are `orders.show`-only):

```json
{
    "status": 200,
    "message": "Data fetched successfully",
    "success": true,
    "data": {
        "data": [
            {
                "id": 1,
                "order_number": "ORD-20260720-0001",
                "status": "completed",
                "payment_status": "payment-success",
                "shipping_method": "standard",
                "expected_delivery_at": "2026-07-25T00:00:00+00:00",
                "customer": {
                    "id": 5,
                    "name": "Ahmed",
                    "email": "ahmed@example.com",
                    "phone": "+201234567890"
                },
                "created_at": "2026-07-20T10:30:00+00:00",
                "updated_at": "2026-07-20T11:00:00+00:00",
                "fast_shipping_fee": 0.0,
                "pickup_location": {
                    "id": 3,
                    "store_name": "Cairo Store",
                    "address": "5 Tahrir St",
                    "phone": "+201111111111",
                    "email": "store@example.com",
                    "working_hours": "10:00-22:00",
                    "latitude": "30.0444",
                    "longitude": "31.2357",
                    "status": true
                }
            }
        ],
        "links": {
            "current_page": 1,
            "from": 1,
            "to": 1,
            "last_page": 1,
            "path": "http://example.com/api/v1/orders",
            "per_page": 15,
            "total": 1,
            "next_page_url": null,
            "prev_page_url": null
        }
    }
}
```

### Field notes

- `customer` is emitted only when the `user` relation is loaded (it is). `phone` maps from `user->phone_number`.
- `pickup_location` is emitted only when `fulfillment_type === "pickup"`.
- Ordering is applied by a global scope on the model (`ORDER BY created_at DESC`).

## 4. Admin Order Detail

**GET** `/api/v1/orders/{id}`

`{id}` can be the primary ID **or** tracking number.

### Authentication

- `auth:sanctum`
- `throttle:admin`
- Permission: **`view-order`** (Spatie, `Permission::VIEW_ORDER`)

### Response

The detail uses `Marvel\Http\Resources\Order\OrderResource`. Full detail fields are only merged on the `orders.show` route:

```json
{
    "status": 200,
    "message": "Data fetched successfully",
    "success": true,
    "data": {
        "id": 1,
        "order_number": "ORD-20260720-0001",
        "status": "completed",
        "payment_status": "payment-success",
        "shipping_method": "standard",
        "expected_delivery_at": "2026-07-25T00:00:00+00:00",
        "customer": {
            "id": 5,
            "name": "Ahmed",
            "email": "ahmed@example.com",
            "phone": "+201234567890"
        },
        "customer_name": "Ahmed",
        "customer_phone": "+201234567890",
        "customer_email": "ahmed@example.com",
        "address": "5 Tahrir St, Cairo",
        "notes": null,
        "price": 200.0,
        "shipping_price": 30.0,
        "total_price": 230.0,
        "coupon": null,
        "coupon_discount": 0.0,
        "promotion": null,
        "order_items": [
            {
                "id": 1,
                "product_id": 10,
                "product_variant_id": null,
                "product_name": "Widget",
                "product_sku": "WDG-100",
                "quantity": 2,
                "unit_price": 100.0,
                "total_price": 200.0,
                "discount_price": 0.0,
                "flash_sale_price": null,
                "promotion_discount_amount": 0.0,
                "is_gift": false,
                "promotion_id": null,
                "attributes": null,
                "product": {
                    "id": 10,
                    "name": "Widget",
                    "slug": "widget",
                    "image": "http://example.com/storage/products/1/w1.jpg"
                },
                "variant": null
            }
        ],
        "transactions": [
            {
                "id": 1,
                "uuid": "abc12345-1111-1111-1111-111111111111",
                "invoice_id": "MF-20260720-001",
                "payment_method": "myfatoorah",
                "status": "paid",
                "amount": 230.0,
                "created_at": "2026-07-20T10:35:00+00:00"
            }
        ],
        "created_at": "2026-07-20T10:30:00+00:00",
        "updated_at": "2026-07-20T11:00:00+00:00",
        "fast_shipping_fee": 0.0,
        "pickup_location": null
    }
}
```

### Admin OrderItemResource fields

| Field | Type | Notes |
|-------|------|-------|
| `id` | int | |
| `product_id` | int | |
| `product_variant_id` | int \| null | |
| `product_name` | string | |
| `product_sku` | string | |
| `quantity` | int | from `product_quantity` |
| `unit_price` | float | rounded, from `product_price` |
| `total_price` | float | rounded, from `product_total_price` |
| `discount_price` | float | rounded, from `product_discount_price` |
| `flash_sale_price` | float \| null | rounded, from `product_flash_sale_price` |
| `promotion_discount_amount` | float | rounded |
| `is_gift` | bool | |
| `promotion_id` | int \| null | |
| `attributes` | mixed | raw attributes |
| `product` | object | `{id, name, slug, image}` when relation loaded |
| `variant` | object \| null | `{id, sku, price, in_stock, attributes}` when loaded |

### Admin OrderTransactionResource fields

`id`, `uuid`, `invoice_id`, `payment_method`, `status`, `amount`, `created_at` (ISO8601).

---

## 5. Admin Change Order Status

**PATCH** `/api/v1/orders/{id}/status`

This is the **only HTTP endpoint for arbitrary status transitions** (e.g. `pending -> processing`, `completed -> delivered`). COD/cashier payment marking and gateway callbacks are separate flows (see Business Rules).

`{id}` is the numeric order ID (`whereNumber('id')`). Route name: `orders.update-status`.

Source: `packages/marvel/src/Rest/Routes.php:167`, controller `Marvel\Http\Controllers\Order\OrderController@updateStatus`.

### Authentication

- `auth:sanctum`
- `throttle:admin`
- Permission: **`update-order-status`** (Spatie, `Permission::UPDATE_ORDER_STATUS`) - applied via controller constructor middleware.

### Request Body

```json
{
    "status": "processing"
}
```

Validation (`Marvel\Http\Requests\OrderStatusUpdateRequest`):

| Field | Rules |
|-------|-------|
| `status` | `required`, `string`, `in` - one of the five database statuses below |

Valid values are exactly the five database statuses:

```text
pending | processing | completed | delivered | cancelled
```

Invalid values fail validation with **422** before reaching the service.

### Transition Validation

After validation, the transition is checked against the authoritative matrix in `App\Services\General\OrderService::$allowedOrderTransitions`:

```text
pending    -> pending, processing, completed, cancelled
processing -> processing, completed, cancelled
completed  -> completed, delivered
delivered  -> delivered            (terminal)
cancelled  -> cancelled            (terminal)
```

A forbidden transition returns **422** with the translated message key `checkout.invalid_order_status_transition` (includes `from`/`to` placeholders). Re-setting the same status is allowed.

### Side Effects (executed inside one DB transaction by `OrderService::changeOrderStatus`)

| Target status | Side effects |
|---------------|--------------|
| `processing` | fulfillment status set to `processing` (if allowed by the fulfillment transition matrix). **If this is the first valid transition away from `pending`: Invoice created (exactly once)** - payment status is NOT touched and NO payment event fires |
| `completed` | **Invoice if not already existing**; `payment_status` forced to `payment-success`; `paid_at` (preserved if already set) + `completed_at = now()`; latest matching transaction set to `paid` + `paid_at`; coupon usage recorded; promotion usage finalized; fulfillment status advanced if still `pending`; **`PaymentSucceeded` fired exactly once** - invoice listener no-ops, payment notifications sent |
| `cancelled` (first time) | **Invoice if first transition away from `pending`**; `cancelled_at = now()`; transaction set to `failed`; promotion usage decremented; fulfillment status set to `cancelled` - NO `PaymentSucceeded` |
| `delivered` | fulfillment status set to `delivered` - invoice never duplicated |

### Invoice Contract

```text
pending --(first VALID different status)--> Invoice x1   [synchronous, inside the PATCH transaction]
```

- Applies whether the first transition is `processing`, `completed`, or `cancelled`.
- Same-status re-set (`pending->pending`) is NOT a leave - no invoice.
- Every later transition never duplicates the invoice (`InvoiceService` existing-row lock).
- Invoice != PaymentSuccess: a `processing`, still-unpaid order is fully invoiced.
- Invoice generation failures are reported and never block the operational status change.

Events dispatched (inside the transaction, before commit):

- `App\Events\OrderStatusChanged` - **always**
- `App\Events\OrderCancelled` - additionally on first-time cancellation
- `App\Events\OrderDelivered` - additionally on first-time delivery -> customer delivery notification (DB + Pusher)
- `App\Events\PaymentSucceeded` - on completion (exactly once per order lifecycle); the gateway callback path opts out because it owns the post-commit dispatch

Queued listeners run asynchronously:

| Queue | Work |
|-------|------|
| `meem-high` | `GenerateInvoiceListener` on PaymentSucceeded (no-ops when the first-leave invoice already exists); payment-success notification chain |
| `meem-medium` | activity logging (`LogActivityJob`); cancellation: inventory restore + customer DB/Pusher notification; delivery: customer DB/Pusher notification; invoice PDF job |

> **Async note:** a 200 response means status **and invoice** are committed synchronously; queued listeners (activity log, notifications, PDF) execute afterward. Do not assume notification delivery from the HTTP response.

### Success Response (200)

Returns the updated order using `Marvel\Http\Resources\Order\OrderResource` with relations `user`, `orderItems.product`, `orderItems.productVariant.attributeProducts.attributeValue`, `transactions`, `pickupLocation` loaded:

```json
{
    "status": 200,
    "message": "Order status updated successfully",
    "success": true,
    "data": { "...same shape as Admin Order Detail..." }
}
```

Note: fields merged under `routeIs('orders.show')` (see Business Rule 3) are not present in this response because the PATCH runs on route `orders.update-status`.

### Error Responses

| Status | When | Body |
|--------|------|------|
| `401` | Unauthenticated | standard error envelope |
| `403` | Missing `update-order-status` permission | standard error envelope |
| `404` | Order ID does not exist | `{ "status": 404, "message": "Not found", "success": false }` |
| `422` | Invalid `status` value OR forbidden transition | `{ "status": 422, "message": "<validation or transition message>", "success": false }` |

---

## Business Rules

1. **Permission gating (admin):** `index` requires `view-orders`, `show` requires `view-order`, `PATCH status` requires `update-order-status`.
2. **Dual resolution (admin):** `show` accepts ID or tracking number; `PATCH status` accepts numeric ID only.
3. **Conditional fields (admin):** `customer_name`, `customer_phone`, `customer_email`, `address`, `notes`, `price`, `shipping_price`, `total_price`, `coupon`, `coupon_discount`, `promotion`, `order_items`, `transactions` are only present on the `orders.show` route (merged via `routeIs('orders.show')`).
4. **Customer scoping:** the customer list (`/api/v1/general/orders`) is always filtered to `forUser($request->user()->id)`; customers have **no** status-change endpoint.
5. **Pagination:** default 15, max 100 (admin: `getLimit`, customer: `getLimit` in `OrderService`).
6. **Ordering:** both lists inherit the model global scope `ORDER BY created_at DESC`.
7. **Money rounding:** all monetary values are rounded to 2 decimals in resources.
8. **Invoice indicator (customer):** `order_has_invoice` / `invoice_id` tell the frontend whether an invoice exists for the order; `invoice_id` is the invoice **uuid**.
9. **Single status authority:** every status change path (admin PATCH, COD mark-paid, cashier mark-paid, online callback) goes through `OrderService` transition validation. There is no code path that writes `orders.status` directly from a controller. (Documented exception: the system-initiated `orders:cancel-unpaid` command bypasses the service to avoid decrementing never-consumed promotion usage; it emits `OrderStatusChanged` + `OrderCancelled` + `PaymentFailed` itself.)
10. **Invoice on first leave-pending:** an Invoice is created exactly once when an Order performs its first valid transition away from `pending` - regardless of whether that target is `processing`, `completed`, or `cancelled`. Same-status re-sets create nothing. Invoice generation is decoupled from payment: `completed` additionally fires `PaymentSucceeded` exactly once (the invoice listener no-ops if already created).
11. **Completion means payment settled:** `completed` forces `payment_status = payment-success`, stamps `paid_at`, and marks the matching transaction paid.
