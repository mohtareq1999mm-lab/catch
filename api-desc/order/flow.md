# Data Flow - Order Feature

## Flow: Customer Order List (index)

```
Customer App
  |
  GET /api/v1/general/orders?status=completed&limit=15
  Authorization: Bearer <token>
  |
  v
auth:sanctum middleware + throttle:authenticated
  |
  v
App\Http\Controllers\Api\General\OrderController@index
  |
  +-- OrderService::paginateForUser($request)
  |     +-- getLimit() → 15 (default, max 100)
  |     +-- Order::query()->forUser(userId)
  |     +-- when(status) -> where('status', status)      // pending|processing|completed|delivered|cancelled
  |     +-- with([orderItems.product(+avg rating, media), productVariant.attributeProducts.attributeValue,
  |               transactions, pickupLocation, latestInvoice])
  |     +-- paginate(15)->withQueryString()
  |     +-- enrich each item's product with pricing
  |
  v
new App\OrderCollection($paginator)  →  App\OrderResource items
  |
  v
JSON Response
  {
    status:200, message:"Data fetched successfully", success:true,
    data: { data:[ OrderResource... ], links:{ current_page, ..., last_page_url, first_page_url } }
  }
```

## Flow: Customer Invoice View (invoiceByOrderId — canonical)

```
Customer
  |
  GET /api/v1/general/orders/{orderId}/invoice
  Authorization: Bearer <token>
  |
  v
auth:sanctum + throttle:authenticated
  |
  v
App\Http\Controllers\Api\General\OrderController@invoiceByOrderId
  |
  +-- Order::where('user_id', auth id)->findOrFail(orderId)
  |     missing order OR foreign order → ModelNotFound → Handler JSON 404 (no leak)
  |
  +-- $order->latestInvoice()->first()
        ├─ null (pending — nothing created yet) → 404 {status:404,message:"Not found",success:false}
        └─ Invoice → CustomerInvoiceResource → 200
             (identical payload to legacy uuid route)
```

## Flow: Customer Invoice View — REMOVED legacy route

> `GET /orders/invoice/{uuid}` and `OrderController::invoice()` were **removed 2026-08-22**. Use the canonical Order-ID flow above (`invoiceByOrderId`).

## Flow: Admin Order List (index)

```
Admin Client
  |
  GET /api/v1/orders?status=completed&search=ahmed&limit=15
  Authorization: Bearer <token>
  |
  v
auth:sanctum + throttle:admin
  |
  v
permission:view-orders middleware (Spatie)
  |
  v
Marvel\Http\Controllers\Order\OrderController@index($request)
  |
  +-- getLimit($request) → 15
  +-- Order::query()
  |     +-- with(['user', 'orderItems.product', 'orderItems.productVariant.attributeProducts.attributeValue', 'transactions', 'pickupLocation'])
  |     +-- where('status', 'completed')
  |     +-- where(function($q) { $q->where('name','LIKE','%ahmed%')->orWhere(...) })
  |     +-- paginate(15) → LengthAwarePaginator        // ordered by created_at DESC via global scope
  |
  v
new Marvel\OrderCollection($paginator)
  |
  v
JSON Response (200): { status, message, success, data: { data:[ minimal OrderResource ], links:{} } }
```

## Flow: Admin Order Detail (show)

```
Admin Client
  |
  GET /api/v1/orders/42      (42 = id or tracking number)
  Authorization: Bearer <token>
  |
  v
auth:sanctum + throttle:admin
  |
  v
permission:view-order middleware (Spatie)
  |
  v
Marvel\OrderController@show($request, '42')
  |
  +-- Order::query()
  |     +-- with([...5 relations...])
  |     +-- findOrFail('42')  -- also works with tracking number
  |
  v
new Marvel\OrderResource($order)
  |  -- conditionally includes customer_name, financial fields, order_items, transactions
  |     via mergeWhen(routeIs('orders.show'), [...])
  v
JSON Response (200): { status, message, success, data: { full OrderResource } }
```

## Flow: Admin Order Status Change (updateStatus)

The single HTTP entry point for arbitrary transitions:

```
Admin Client
  |
  PATCH /api/v1/orders/{id}/status        { "status": "processing" }
  Authorization: Bearer <token>
  |
  v
auth:sanctum + throttle:admin                    (Routes.php group, line 115)
  |
  v
permission:update-order-status                   (controller constructor)
  |
  v
OrderStatusUpdateRequest                         (FormRequest validation)
  |-- status: required|string|in(pending|processing|completed|delivered|cancelled)
  |-- invalid value → 422 before any logic runs
  |
  v
Marvel\OrderController@updateStatus($request, $id)
  |
  +-- Order::find($id) → null ? → 404 NOT_FOUND
  |
  +-- OrderService::changeOrderStatus(null, status, orderId)
        |
        DB::transaction:
          |-- resolve order by orderId (lockForUpdate)
          |-- canTransitionOrderStatus(current, requested)?
          |     NO  → RuntimeException(__('checkout.invalid_order_status_transition'))
          |            controller catches → 422
          |-- apply updates:
          |     completed  → payment_status=payment-success (forced), paid_at,
          |                 completed_at, tx→paid+paid_at, recordCouponUsage(),
          |                 promotion finalize, fulfillment pending→processing
          |     cancelled  → cancelled_at (first time), tx→failed,
          |                 promotion decrementUsage, fulfillment→cancelled
          |     processing → fulfillment→processing (if allowed)
          |     delivered  → fulfillment→delivered
          |
          |-- INVOICE: first valid leave of pending → InvoiceService::generateFromOrder()
          |             (exactly once per order; same-status no-op excluded;
          |              failures reported, never block the transition)
          |
          |-- event(new App\Events\OrderStatusChanged($order))       // ALWAYS
          |-- event(new App\Events\OrderCancelled($order))           // only on real cancel
          |-- event(new App\Events\OrderDelivered($order))           // only on real deliver
          |-- event(new App\Events\PaymentSucceeded($order))         // on completed,
          |                                                          // exactly once (gateway
          |                                                          // callback opts out)
        |
        (events fire INSIDE the transaction — listeners are queued and run after commit)
  |
  v
200 { status:200, message:"Order status updated successfully", success:true,
      data: Marvel\OrderResource (relations loaded) }
```

### Asynchronous continuation after the 200 response

```
Queue: meem-medium (database connection)
  App\Events\OrderStatusChanged → SendOrderStatusChangedNotification
      └── LogActivityJob('order_status_changed', ...)   → activity_log
  App\Events\OrderCancelled   → RestoreProductInventory          // stock restored once, paid orders only
                              → SendOrderCancelledNotification   // LogActivityJob('order_cancelled')
                              → SendUserOrderCancelledNotification // DB + Pusher 'order.cancelled'
  App\Events\OrderDelivered   → SendUserOrderDeliveredNotification // DB + Pusher delivery notification
  Invoice                     → GenerateInvoicePdfJob              // PDF rendering

Queue: meem-high (database connection)
  App\Events\PaymentSucceeded → GenerateInvoiceListener            // no-ops when the
                                                                   // first-leave invoice exists
                              → payment-success customer notifications
```

> Frontend expectation: the PATCH response reflects the new `status`, and the **invoice already exists** at response time (created synchronously on first leave-pending). Activity-log rows, notifications and the invoice PDF are produced asynchronously by `meem-medium` / `meem-high` workers.

## Flow: Payment Callback (online)

```
Payment Gateway
  |
  ANY /api/v1/general/checkout/callback?paymentId=xxx     (public, no auth)
  |
  v
OrderController@checkoutCallback(Request)
  |
  +-- resolve transaction by gateway_transaction_id / invoice_id
  +-- PaymentGatewayFactory::make(...) → Gateway::verifyPayment(paymentId)
  |     |-- validates amount & currency against the order (mismatch blocks; apitest host exempt)
  |
  +-- FAILURE:
  |     |-- transaction.status = failed (+ merged gateway_response, error_message)
  |     |-- event(App\Events\PaymentFailed)               → queued user/admin notifications
  |     |-- mobile JSON or redirect to /payment/failed
  |
  +-- SUCCESS (DB::transaction, idempotent — skipped if locked order is not 'pending'):
        |-- transaction → paid + paid_at
        |-- order.payment_status = payment-success, paid_at = now()
        |-- inventory finalized (cart finalize, else per-order deduct)
        |-- promotion usage finalized
        |-- OrderService::changeOrderStatus(invoice_id, 'completed')
        |       └── fires OrderStatusChanged (and side effects above)
        |
        after commit:
        |-- event(App\Events\PaymentSucceeded(order->fresh()))   // exactly once ($processed flag)
        |
        v
        mobile JSON or redirect to /payment/success
```

Related flows using the same service:

- **COD:** admin `POST /checkout/cod/{orderId}/mark-paid` → `markCodAsPaid()` (transaction→paid, order→completed, coupon/promotion/inventory finalized, `PaymentSucceeded` fired inside the transaction).
- **Cashier QR:** same shape via `POST /checkout/cashier/{orderId}/mark-paid`.
- **Unpaid timeout:** console command `CancelUnpaidOrders` locks stale orders and dispatches `OrderCancelled` + `PaymentFailed`.
