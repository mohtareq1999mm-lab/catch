# End-to-End Sequence: Cart → Archived Order

> **Complete execution trace with all paths, locks, events, and failures**

---

## 1. Online Payment Flow (MyFatoorah)

```
CUSTOMER                  FRONTEND              API                      GATEWAY               DB                    QUEUE
   │                        │                     │                         │                    │                      │
   ├── Browse products ────→│                     │                         │                    │                      │
   │                        │                     │                         │                    │                      │
   ├── Add to cart ─────────→│  POST /cart        │                         │                    │                      │
   │                        │────────────────────→│ CartController@store   │                    │                      │
   │                        │                     │─────────────────────────┼───────────────────→│ RESERVE stock        │
   │                        │                     │                         │                    │ INSERT cart_item     │
   │                        │                     │─────────────────────────┼───────────────────→│ TOUCH cart          │
   │                        │←────────────────────│ CartResource            │                    │                      │
   │                        │                     │                         │                    │                      │
   ├── Apply coupon ────────→│  POST /coupons/apply│                        │                    │                      │
   │                        │────────────────────→│ CouponOrchestrator     │                    │                      │
   │                        │                     │─────────────────────────┼───────────────────→│ UPDATE cart.coupon  │
   │                        │←────────────────────│ { valid: true }         │                    │                      │
   │                        │                     │                         │                    │                      │
   ├── Select promotion ────→│  GET /checkout/promotions                    │                    │                      │
   │                        │────────────────────→│ PromotionEligibility   │                    │                      │
   │                        │←────────────────────│ Eligible promotions    │                    │                      │
   │                        │                     │                         │                    │                      │
   ├── Submit checkout ─────→│  POST /checkout     │                         │                    │                      │
   │                        │────────────────────→│ OrderCreateRequest     │                    │                      │
   │                        │                     │ (validation)            │                    │                      │
   │                        │                     │                         │                    │                      │
   │                        │                     │ ┌─────────────────────────────────────────────────────┐
   │                        │                     │ │ DB TRANSACTION START                                │
   │                        │                     │ │                                                   │
   │                        │                     │ │ LOCK cart + items                                  │
   │                        │                     │ │ refreshCartItemPrices()                             │
   │                        │                     │ │ LOCK coupon row                                     │
   │                        │                     │ │ validate coupon                                     │
   │                        │                     │ │ calculateCheckoutTotals()                            │
   │                        │                     │ │ check minimum order amount                          │
   │                        │                     │ │ resolve shipping price                              │
   │                        │                     │ │ INSERT order (status=pending)                       │
   │                        │                     │ │ INSERT order_items                                  │
   │                        │                     │ │ dispatch OrderCreated (after commit)                │
   │                        │                     │ └───────────────────┬─────────────────────────────────┘
   │                        │                     │                     │                    │                      │
   │                        │                     │                     │                    │                      │
   │                        │                     │ PAYMENT ROUTING     │                    │                      │
   │                        │                     │ payment_method      │                    │                      │
   │                        │                     │ = "online"          │                    │                      │
   │                        │                     │                     │                    │                      │
   │                        │                     │ PaymentCheckoutHandler::handleOnlinePayment()           │
   │                        │                     │ ───────────────────→│ createInvoice()     │                      │
   │                        │                     │                     │                    │                      │
   │                        │                     │←────────────────────│ { redirectUrl }     │                      │
   │                        │                     │                     │                    │                      │
   │                        │                     │ INSERT Transaction (status=pending)       │                      │
   │                        │←────────────────────│ { url: redirectUrl }│                    │                      │
   │                        │                     │                     │                    │                      │
   ├── Redirect to gateway ─→│                     │                     │                    │                      │
   │                        │───────────────────────────────────────────→│                    │                      │
   │                        │                     │                     │                    │                      │
   ├── Enter payment info ──→│                     │                     │                    │                      │
   │                        │                     │                     │                    │                      │
   ├── Complete payment ────→│                     │                     │                    │                      │
   │                        │                     │                     │                    │                      │
   │                        │                     │←── callback ─────────│ paymentId          │                      │
   │                        │                     │  paymentId          │                    │                      │
   │                        │                     │                     │                    │                      │
   │                        │                     │ ┌─────────────────────────────────────────────────────┐
   │                        │                     │ │ DB TRANSACTION START                                │
   │                        │                     │ │                                                   │
   │                        │                     │ │ LOCK Transaction row (FOR UPDATE)                   │
   │                        │                     │ │ LOCK Order row (FOR UPDATE)                         │
   │                        │                     │ │ CHECK order.status === 'pending'                    │
   │                        │                     │ │ VERIFY amount match (within 0.01)                   │
   │                        │                     │ │ VERIFY currency match                               │
   │                        │                     │ │                                                   │
   │                        │                     │ │ UPDATE Transaction: status=paid, paid_at=now       │
   │                        │                     │ │ UPDATE Order: payment_status=SUCCESS, paid_at=now  │
   │                        │                     │ │                                                   │
   │                        │                     │ │ finalizeItemsByShippingMethod():                    │
   │                        │                     │ │   FOREACH cart_item:                                │
   │                        │                     │ │     UPDATE products:                                │
   │                        │                     │ │       reserved_quantity -= qty                      │
   │                        │                     │ │       stock_quantity -= qty                         │
   │                        │                     │ │       sold_quantity += qty                          │
   │                        │                     │ │                                                   │
   │                        │                     │ │ finalizePromotionUsageAfterPayment():                │
   │                        │                     │ │   UPDATE promotions SET usage = usage + 1           │
   │                        │                     │ │                                                   │
   │                        │                     │ │ changeOrderStatus(invoiceId, 'completed'):           │
   │                        │                     │ │   UPDATE orders SET status=completed,               │
   │                        │                     │ │     completed_at=now, fulfillment_status=PROCESSING │
   │                        │                     │ │                                                   │
   │                        │                     │ │   recordCouponUsage():                              │
   │                        │                     │ │     LOCK coupon_assignment row                      │
   │                        │                     │ │     UPDATE coupons SET used = used + 1              │
   │                        │                     │ │     UPDATE coupon_assignments SET used = used + 1   │
   │                        │                     │ │     INSERT coupon_assignment_usage                  │
   │                        │                     │ │     UPDATE orders SET coupon_consumed = 1           │
   │                        │                     │ │                                                   │
   │                        │                     │ │ event(new PaymentSucceeded(order))                  │
   │                        │                     │ └───────────────────┬─────────────────────────────────┘
   │                        │                     │                     │                    │                      │
   │                        │                     │                     │                    │              ╔══════════════════════╗
   │                        │                     │                     │                    │              ║ GenerateInvoiceListener ║
   │                        │                     │                     │                    │              ║ (high queue, 5 retries) ║
   │                        │                     │                     │                    │              ╚══════════════════════╝
   │                        │                     │                     │                    │                      │
   │                        │                     │                     │                    │              ┌──────────────────────┐
   │                        │                     │                     │                    │              │ DB TRANSACTION       │
   │                        │                     │                     │                    │              │                      │
   │                        │                     │                     │                    │              │ LOCK invoices table  │
   │                        │                     │                     │                    │              │   (check existing)   │
   │                        │                     │                     │                    │              │                      │
   │                        │                     │                     │                    │              │ LOCK invoice_sequences│
   │                        │                     │                     │                    │              │   (SELECT FOR UPDATE) │
   │                        │                     │                     │                    │              │   UPDATE last_sequence│
   │                        │                     │                     │                    │              │                      │
   │                        │                     │                     │                    │              │ buildFullSnapshot()  │
   │                        │                     │                     │                    │              │ validateSnapshot()   │
   │                        │                     │                     │                    │              │ computeHash()        │
   │                        │                     │                     │                    │              │ computeVerification()│
   │                        │                     │                     │                    │              │                      │
   │                        │                     │                     │                    │              │ INSERT invoice       │
   │                        │                     │                     │                    │              │ INSERT timeline      │
   │                        │                     │                     │                    │              │                      │
   │                        │                     │                     │                    │              │ afterCommit:         │
   │                        │                     │                     │                    │              │   InvoiceCreated     │
   │                        │                     │                     │                    │              │   GenerateInvoicePdf │
   │                        │                     │                     │                    │              └──────────────────────┘
   │                        │                     │                     │                    │                      │
   │                        │                     │                     │                    │              GenerateInvoicePdfJob
   │                        │                     │                     │                    │              (low queue, 3 retries)
   │                        │                     │                     │                    │                      │
   │                        │                     │                     │                    │              ┌──────────────────────┐
   │                        │                     │                     │                    │              │ UPDATE invoice:       │
   │                        │                     │                     │                    │              │   status=pdf_generating│
   │                        │                     │                     │                    │              │                      │
   │                        │                     │                     │                    │              │ Generate PDF file    │
   │                        │                     │                     │                    │              │                      │
   │                        │                     │                     │                    │              │ IF success:           │
   │                        │                     │                     │                    │              │   status=ready        │
   │                        │                     │                     │                    │              │   pdf_generated_at   │
   │                        │                     │                     │                    │              │   pdf_path            │
   │                        │                     │                     │                    │              │                      │
   │                        │                     │                     │                    │              │ IF failure (3 retry): │
   │                        │                     │                     │                    │              │   status=failed       │
   │                        │                     │                     │                    │              │   last_generation_err │
   │                        │                     │                     │                    │              └──────────────────────┘
   │                        │                     │                     │                    │                      │
   │                        │                     │←── redirect ────────│                    │                      │
   │                        │                     │  /payment/success   │                    │                      │
   │                        │←────────────────────│ redirect URL        │                    │                      │
   │                        │                     │                     │                    │                      │
   ├── See success page ────→│                     │                     │                    │                      │
   │                        │                     │                     │                    │                      │
   ├── View invoice ────────→│  GET /invoices/my-invoices                 │                    │                      │
   │                        │────────────────────→│ InvoiceController    │                    │                      │
   │                        │                     │  myInvoices()         │                    │                      │
   │                        │←────────────────────│ InvoiceResource[]    │                    │                      │
   │                        │                     │                     │                    │                      │
   ├── Download PDF ────────→│  GET /invoices/{uuid}/download             │                    │                      │
   │                        │────────────────────→│ InvoiceController    │                    │                      │
   │                        │                     │  download()           │                    │                      │
   │                        │                     │  RecordTimeline       │                    │                      │
   │                        │                     │  Set downloaded_at   │                    │                      │
   │                        │                     │  Check ownership      │                    │                      │
   │                        │←────────────────────│ { url: pdf_url }     │                    │                      │
   │                        │                     │                     │                    │                      │
   ├── Scan QR ─────────────→│  GET /invoices/verify/{uuid}              │                    │                      │
   │                        │────────────────────→│ InvoiceController    │                    │                      │
   │                        │                     │  verify()             │                    │                      │
   │                        │                     │  hash_equals check   │                    │                      │
   │                        │                     │  Increment verify_cnt│                    │                      │
   │                        │                     │  RecordTimeline       │                    │                      │
   │                        │←────────────────────│ { authentic: true }  │                    │                      │
   │                        │                     │                     │                    │                      │
```

---

## 2. COD Flow

```
CUSTOMER                  FRONTEND              API                      DB                    QUEUE
   │                        │                     │                         │                    │
   ├── Submit checkout ─────→│  POST /checkout     │                         │                    │
   │                        │  payment_method=cod  │                         │                    │
   │                        │────────────────────→│ orderService          │                    │
   │                        │                     │ → addItemsInOrder()     │                    │
   │                        │                     │─────────────────────────→│ INSERT order       │
   │                        │                     │─────────────────────────→│ INSERT order_items │
   │                        │                     │                         │                    │
   │                        │                     │ handleCodPayment()      │                    │
   │                        │                     │─────────────────────────→│ INSERT Transaction │
   │                        │                     │                         │  (cod, pending)     │
   │                        │←────────────────────│ { order_id }           │                    │
   │                        │                     │                         │                    │
   │  (customer receives order, pays on delivery)                         │                    │
   │                        │                     │                         │                    │
ADMIN                     │                     │                         │                    │
   │                        │                     │                         │                    │
   ├── Mark COD paid ──────→│  POST /checkout/cod/{id}/mark-paid           │                    │
   │                        │────────────────────→│ markCodAsPaid()        │                    │
   │                        │                     │                         │                    │
   │                        │                     │ ┌────────────────────────────────────────────┐
   │                        │                     │ │ DB TRANSACTION                             │
   │                        │                     │ │ LOCK Transaction (cod, pending) FOR UPDATE │
   │                        │                     │ │ LOCK Order FOR UPDATE                      │
   │                        │                     │ │ UPDATE Transaction: paid, paid_at          │
   │                        │                     │ │ UPDATE Order: completed, payment_status    │
   │                        │                     │ │ recordCouponUsage()                        │
   │                        │                     │ │ finalizePromotionUsageAfterPayment()       │
   │                        │                     │ │ finalizeInventoryAfterPayment()            │
   │                        │                     │ │ event(PaymentSucceeded)                    │
   │                        │                     │ └────────────────────┬───────────────────────┘
   │                        │                     │                      │                    │
   │                        │                     │                      │          GenerateInvoiceListener
   │                        │←────────────────────│ SUCCESS              │          (same as online)
```

---

## 3. Pay-at-Cashier Flow

```
CUSTOMER                  FRONTEND              API                      DB                    QUEUE
   │                        │                     │                         │                    │
   ├── Submit checkout ─────→│  POST /checkout     │                         │                    │
   │                        │ payment_method=      │                         │                    │
   │                        │ pay_at_cashier      │                         │                    │
   │                        │────────────────────→│ orderService          │                    │
   │                        │                     │ → addItemsInOrder()     │                    │
   │                        │                     │─────────────────────────→│ INSERT order       │
   │                        │                     │                         │                    │
   │                        │                     │ handleCashierQrPayment()│                    │
   │                        │                     │─────────────────────────→│ INSERT Transaction │
   │                        │                     │                         │  (cashier, pending) │
   │                        │                     │ CashierQrService::      │                    │
   │                        │                     │   generateBase64DataUri │                    │
   │                        │←────────────────────│ { qr_code, order_id,   │                    │
   │                        │                     │   transaction_uuid }    │                    │
   │                        │                     │                         │                    │
   ├── Show QR to cashier ──→│                     │                         │                    │
   │                        │                     │                         │                    │
ADMIN                     │                     │                         │                    │
   ├── Scan QR / mark paid ─→│  POST /checkout/cashier/{id}/mark-paid      │                    │
   │                        │────────────────────→│ markCashierPaid()      │                    │
   │                        │                     │                         │                    │
   │                        │                     │ ┌────────────────────────────────────────────┐
   │                        │                     │ │ (identical to COD flow, but matches        │
   │                        │                     │ │  payment_method=pay_at_cashier)             │
   │                        │                     │ └────────────────────────────────────────────┘
```

---

## 4. Failure Flows

### 4.1 Payment Gateway Failure

```
GATEWAY                  API                      DB                    QUEUE
   │                        │                         │                    │
   ├── error-callback ──────→│ checkoutErrorCallback  │                    │
   │                        │                         │                    │
   │                        │ ┌───────────────────────────────────────────┐
   │                        │ │ DB TRANSACTION                            │
   │                        │ │ LOCK Transaction FOR UPDATE               │
   │                        │ │ IF already failed → return (idempotent)   │
   │                        │ │ UPDATE Transaction: status=failed,        │
   │                        │ │   error_message, gateway_response         │
   │                        │ └───────────────────────────────────────────┘
   │                        │                         │                    │
   │                        │ event(PaymentFailed)     │                    │
   │                        │─────────────────────────→│ SendPaymentFailedNotification
   │                        │                         │ → LogActivityJob
   │                        │                         │                    │
   │                        │ Redirect to /payment/failed                  │
```

### 4.2 Amount/Currency Mismatch

```
API → detects: |gateway.amount - order.total_price| > 0.01
API → BLOCKS order (does NOT update order status)
API → updates Transaction: error_message
API → fires PaymentFailed
API → redirects to /payment/failed
```

### 4.3 Duplicate Callback

```
API → LOCK Transaction FOR UPDATE
API → LOCK Order FOR UPDATE
API → CHECK order.status === 'pending' → FALSE (already completed)
API → RETURN (no-op, idempotent)
```

### 4.4 Invoice Generation Failure (Retry Exhausted)

```
GenerateInvoiceListener (retry 1/5) → FAIL → wait 10s
GenerateInvoiceListener (retry 2/5) → FAIL → wait 30s
GenerateInvoiceListener (retry 3/5) → FAIL → wait 60s
GenerateInvoiceListener (retry 4/5) → FAIL → wait 120s
GenerateInvoiceListener (retry 5/5) → FAIL → wait 300s
GenerateInvoiceListener → final FAILURE
  → Job fails with error logged
  → No invoice created for the order
  → Admin must manually regenerate via POST /invoices/{id}/regenerate
```

### 4.5 Unpaid Order Timeout

```
CRON: orders:cancel-unpaid (configurable, default 72h)
  ┌───────────────────────────────────────────────────────┐
  │ FOREACH order WHERE status=pending AND                │
  │   created_at < NOW() - 72h AND payment_method=online: │
  │                                                       │
  │   DB TRANSACTION:                                     │
  │     UPDATE orders SET status=cancelled, cancelled_at  │
  │     UPDATE transactions SET status=failed              │
  │     expireSingleCart(user_id) → release inventory     │
  │     event(OrderCancelled) → restore inventory         │
  └───────────────────────────────────────────────────────┘
```

### 4.6 Out-of-Stock at Checkout

```
ensureCartReservation(cart):
  FOREACH cart_item:
    LOCK product row
    CHECK stock_quantity - reserved_quantity >= item.quantity
    IF insufficient → THROW "Insufficient stock for product X"

→ 400 error returned to customer
→ Cart remains unchanged (customer can remove out-of-stock items)
```

---

## 5. Concurrent Operations & Race Conditions

### 5.1 Two Callbacks for Same Payment

```
Thread A: LOCK Transaction → status=pending
Thread B: LOCK Transaction → BLOCKED (waiting for A)
Thread A: UPDATE → status=paid
Thread A: LOCK Order → status=pending → UPDATE → status=completed
Thread A: COMMIT → UNLOCK
Thread B: acquires LOCK → sees status=paid → skips (no-op)
```

### 5.2 Two Admins Marking Same COD as Paid

```
Admin A: LOCK Transaction (cod, pending) FOR UPDATE ✓
Admin B: LOCK Transaction (cod, pending) FOR UPDATE → BLOCKED
Admin A: UPDATE → status=paid, COMMIT
Admin B: Acquires lock → Transaction already status=paid → "No pending COD transaction" error
```

### 5.3 Same User Two Concurrent Checkouts

```
Thread A: LOCK cart FOR UPDATE → creates order → COMMIT → releases cart lock
Thread B: LOCK cart FOR UPDATE → cart may be stale, creates second order
  (No cart-level lock preventing double checkout — relies on server-side
   session/cart being single-use in practice)
```

### 5.4 Coupon Assignment Race Condition

```
Two concurrent checkouts for same user with same assigned coupon:
Thread A: LOCK assignment FOR UPDATE → used=0, max_uses=1 → OK
Thread B: LOCK assignment FOR UPDATE → BLOCKED
Thread A: UPDATE used=1, INSERT usage → COMMIT
Thread B: Acquires lock → used=1, max_uses=1 → SKIP (quota exhausted)
→ Correctly prevents over-consumption
```
