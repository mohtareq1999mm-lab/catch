# Data Flow - Order Feature

## Flow 0: List Orders with Status Filter

```
Client (Auth)
  |
  GET /api/v1/general/orders?status=pending&limit=15&page=1
  |
  v
OrderController@index(Request)
  |
  v
OrderService::paginateForUser($request)
  |
  +-- $limit = $request->get('limit', 15)          // max 100
  +-- $userId = from auth token
  |
  +-- Order::query()
  |     |-- forUser($userId)                       // WHERE user_id = ?
  |     |-- when(status present)                   // WHERE status IN (pending|processing|completed|delivered|cancelled)
  |     |-- with(orderListRelations)               // eager loads incl. latestInvoice, transactions
  |     |-- paginate($limit)                        // ORDER BY created_at DESC (global scope)
  |
  v
Response: Paginated orders filtered by status
```

## Flow 1: View Own Order Details

```
Client (Auth)
  |
  GET /api/v1/general/orders/{id}
  |
  v
OrderController@show → OrderService::getOrderForUser($request, $orderId)
  |
  +-- Ownership enforced at query level (forUser scope)
  +-- Not found OR another user's order → null → 404
  |
  v
Response: OrderResource for the owner only
```

## Flow 2: Checkout → Order Creation

```
Client (Auth)
  |
  POST /api/v1/general/checkout        { name, user_phone, ..., payment_method, fulfillment_type }
  |
  v
OrderController@checkout(OrderCreateRequest)
  |
  +-- resolve active cart; ensureCartReservation()          // 400 if no cart / reservation fails
  +-- reject COD+pickup                                      // 422
  |
  v
OrderService::addItemsInOrder($request)   [DB::transaction]
  |
  +-- lock cart, refresh item prices via ProductPricingService
  +-- re-validate coupon (drop invalid), compute CheckoutTotals (promotion + coupon)
  +-- enforce minimum order amount                            // InvalidArgumentException → 422
  |
  v
OrderCreationService::createOrder(...)    → Order row, status='pending',
                                            payment_status='payment-pending',
                                            fulfillment_status='pending'
OrderCreationService::createOrderItems(..) → per-item price snapshots
OrderCreationService::finalizeOrder(...)   → dispatches App\Events\OrderCreated   (queued listeners follow)
  |
  v
Payment delegation:
  online        → PaymentCheckoutHandler::handleOnlinePayment     (gateway redirect/session payload)
  cod           → handleCodPayment        (creates pending COD transaction)
  pay_at_cashier→ handleCashierQrPayment  (creates pending cashier transaction w/ QR data)
```

## Flow 3: Payment Callback (Online)

```
Payment Gateway (public, no auth)
  |
  ANY /api/v1/general/checkout/callback?paymentId=xxx
  |
  v
OrderController@checkoutCallback
  |
  +-- find transaction by gateway_transaction_id / invoice_id
  +-- Gateway::verifyPayment(paymentId); amount & currency checked against order
  |
  +-- FAILURE:
  |     transaction.status = failed (+ gateway_response merged)
  |     event(App\Events\PaymentFailed)         → queued user/admin notifications
  |     mobile JSON or redirect to /payment/failed
  |
  +-- SUCCESS  [DB::transaction — idempotent: skipped unless locked order still 'pending']:
        transaction → paid + paid_at
        order.payment_status = 'payment-success'; paid_at = now()
        inventory finalized (cart finalize, else deduct per order)
        promotion usage finalized
        OrderService::changeOrderStatus(invoice_id,'completed')
            └── fires OrderStatusChanged (+ side effects: completed_at, tx paid,
                coupon usage recorded, fulfillment advanced)
        after commit: event(App\Events\PaymentSucceeded(order->fresh()))   // exactly once
  |
  v
mobile JSON or redirect to /payment/success
```

COD / Cashier equivalents:

```
Admin POST /general/checkout/cod|cashier/{orderId}/mark-paid      (permission update-order-status)
  → markCodAsPaid()/markCashierPaid()  [one DB transaction]
      pending tx → paid; order → completed; coupon/promotion/inventory finalized;
      event(PaymentSucceeded) fired inside transaction
  → 422 when no pending transaction exists (idempotent guard)
```

Unpaid timeout: console command `CancelUnpaidOrders` locks stale pending orders and fires
`OrderCancelled` + `PaymentFailed`.

## Flow 4: Admin Status Change (REAL endpoint)

```
Admin
  |
  PATCH /api/v1/orders/{id}/status       { "status": "processing" }
  Authorization: Bearer <token>
  |
  v
auth:sanctum + throttle:admin                     (packages/marvel/src/Rest/Routes.php:115 group, :167 route)
  |
  v
permission:update-order-status                    (controller constructor middleware)
  |
  v
OrderStatusUpdateRequest                          (FormRequest)
  |-- status required|string|in(pending,processing,completed,delivered,cancelled)
  |-- bad value → 422 validation error (never touches the service)
  |
  v
Marvel\Http\Controllers\Order\OrderController@updateStatus
  |
  +-- Order::find(id) → null ? → 404 NOT_FOUND
  |
  +-- App\Services\General\OrderService::changeOrderStatus(null, status, orderId)
        [DB::transaction]
        |-- lockForUpdate on order
        |-- transition check vs matrix below
        |     pending    → pending|processing|completed|cancelled
        |     processing → processing|completed|cancelled
        |     completed  → completed|delivered
        |     delivered  → delivered            (terminal)
        |     cancelled  → cancelled            (terminal)
        |     violation → RuntimeException(__('checkout.invalid_order_status_transition')) → 422
        |
        |-- column updates by target:
        |     processing → fulfillment_status=processing
        |     completed  → payment_status sync + completed_at + tx→paid/paid_at
        |                 + coupon usage + promotion finalize + fulfillment advance
        |     cancelled  → cancelled_at + tx→failed + promotion decrement
        |                 + fulfillment_status=cancelled   (first time only)
        |     delivered  → fulfillment_status=delivered
        |
        |-- event(App\Events\OrderStatusChanged)              // always, queued meem-medium
        |-- event(App\Events\OrderCancelled)                  // real cancellations only
  |
  v
200 { status:200, message:"Order status updated successfully",
      success:true, data: Marvel\OrderResource }

Asynchronous continuation (after the HTTP response):
  queue meem-medium:
    OrderStatusChanged → SendOrderStatusChangedNotification → LogActivityJob('order_status_changed')
    OrderCancelled     → RestoreProductInventory            (stock restored once, paid orders only)
                       → SendOrderCancelledNotification     (activity log)
                       → SendUserOrderCancelledNotification (customer DB + Pusher 'order.cancelled')
    OrderDelivered     → SendUserOrderDeliveredNotification (customer DB + Pusher)
    Invoice            → GenerateInvoicePdfJob              (PDF rendering)
  queue meem-high:
    completed          → PaymentSucceeded → GenerateInvoiceListener → no-ops if invoice
                                                               already exists (first-leave rule)
                       → payment-success customer notification
  queue default: consumed by the meem-medium worker (framework fallback)

SYNCHRONOUS (inside the PATCH transaction): status update + fulfillment sync +
Invoice creation on first leave-pending — an invoice EXISTS when the 200 returns.
```

> Frontend contract: the PATCH response already reflects the new `status` on refetch. Activity logs and notifications are processed **asynchronously** on `meem-medium`; a 200 does not imply they have run.
