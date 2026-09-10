# Phase 15: End-to-End Production Timeline — Production Operations Manual

> **Purpose:** Trace every single step from customer discovery through order completion, invoicing, shipping, delivery, and archiving. This is the single source of truth for how the platform behaves in production.

---

## 15.1 Complete Production Timeline

### Phase A: Discovery & Cart (t=0 to t=30s)

```
t=0:00  Customer visits website
         ├── Frontend loads
         ├── GET /api/v1/general/settings (public)
         ├── GET /api/v1/general/categories (public)
         └── GET /api/v1/general/banners (public)

t=0:05  Customer browses products
         ├── GET /api/v1/general/products?page=1 (public, paginated)
         │   ├── ProductController::index()
         │   └── Returns ProductCollection with pricing
         └── GET /api/v1/general/products/{slug} (public)
             ├── ProductController::getProductBySlug()
             └── Returns ProductResource with variants, reviews

t=0:15  Customer searches
         ├── GET /api/v1/general/search?query=xxx&category=yyy (public)
         └── SearchController::index()

t=0:20  Customer adds item to cart
         ├── POST /api/v1/general/carts/{id} (auth:sanctum) — FRONTEND ENDPOINT
         │   └── CartInventoryService::reserveItem()
         │       ├── DB::transaction()
         │       │   ├── Cart::lockForUpdate()
         │       │   ├── CartItem::lockForUpdate() (find existing)
         │       │   ├── Product/ProductVariant::lockForUpdate() (lock inventory row)
         │       │   ├── reserveStock() — checks available = stock - reserved
         │       │   │   └── throws QUANTITY_EXCEEDS_STOCK if insufficient
         │       │   ├── CartItem::create() or update() with price, quantity, reserved_quantity
         │       │   └── touchCartReservation() — extends expiry to now+3days
         │       └── DB::commit()
         ├── Database writes:
         │   ├── cart_items: new row (or updated)
         │   └── products/product_variants: reserved_quantity += delta
         └── Response: updated cart item

t=0:22  Customer views cart (frontend, no API call needed — cart data in frontend state)
         └── GET /api/v1/general/carts/{id} (auth:sanctum) if needed
```

### Phase B: Promotion & Coupon (t=0:25 to t=0:40)

```
t=0:25  Customer views eligible promotions
         ├── GET /api/v1/general/checkout/promotions (auth:sanctum)
         │   └── OrderController::eligiblePromotions()
         │       └── OrderService::eligiblePromotionsForUser()
         │           ├── getCartUser() — finds active cart with items
         │           └── PromotionService::eligiblePromotionsPayload(cart)
         │               ├── Promotion::valid() scope (status=true, not expired, not over limit)
         │               ├── PromotionEligibilityResolver::eligible(cart, promotions, subtotal)
         │               │   └── Tests each promotion: specific products, min order, gift availability
         │               └── Returns eligible promotions with discount/gift details
         └── Response: list of eligible promotions

t=0:30  Customer applies coupon
         ├── POST /api/v1/general/coupons/apply (auth:sanctum)
         │   └── CouponController::applyCoupon()
         │       └── CouponOrchestrator::validateByCode(code, user, items)
         │           ├── Coupon::valid() scope (status=true, dates, limiter)
         │           ├── Check per-user usage (CouponUsage unique constraint)
         │           ├── Check product restrictions (coupon_products pivot)
         │           └── Cart::update(coupon => code) — stores on cart
         └── Response: success or error with validation message

t=0:35  Customer selects promotion in UI (frontend state only)
         └── Frontend stores selected promotion_id
```

### Phase C: Checkout (t=0:40 to t=0:55)

```
t=0:40  Customer fills checkout form
         ├── Name, phone, email, address, governorate_id, notes
         ├── payment_method: "online" | "cod" | "pay_at_cashier"
         ├── gateway: "myfatoorah" (for online)
         ├── fulfillment_type: "delivery" | "pickup"
         └── pickup_location_id (if pickup)

t=0:45  Customer submits checkout
         ├── POST /api/v1/general/checkout (auth:sanctum)
         │   └── OrderCreateRequest validation
         │   └── OrderController::checkout()
         │
         │   Step 1: Get active cart
         │   ├── cartInventoryService->getActiveCartForUser($user)
         │   │   └── SELECT * FROM carts WHERE user_id=? AND status='active' WITH items
         │   └── If null: return 400 CART_NOT_FOUND
         │
         │   Step 2: Ensure cart reservation (re-sync stock)
         │   ├── cartInventoryService->ensureCartReservation($cart)
         │   │   ├── BEGIN TRANSACTION
         │   │   ├── Cart::lockForUpdate()
         │   │   ├── For each cart item:
         │   │   │   ├── CartItem::lockForUpdate()
         │   │   │   ├── Product/ProductVariant::lockForUpdate()
         │   │   │   ├── delta = quantity - reserved_quantity
         │   │   │   ├── if delta>0: reserveStock() — checks available stock
         │   │   │   ├── if delta<0: releaseStock()
         │   │   │   └── item->update(reserved_quantity=quantity) if delta!=0
         │   │   ├── Cart::update(reserved_at=now(), expires_at=now()+3days)
         │   │   └── COMMIT
         │   └── On failure: return 400 with error message
         │
         │   Step 3: Validate payment method
         │   ├── if COD + pickup: return 422
         │
         │   Step 4: Create order
         │   ├── orderService->addItemsInOrder($request)
         │   │   ├── BEGIN TRANSACTION
         │   │   ├── Cart::lockForUpdate() (re-lock)
         │   │   ├── refreshCartItemPrices(cart)
         │   │   │   ├── For each non-gift item:
         │   │   │   │   ├── ProductPricingService::calculateVariantCurrentPrice/product
         │   │   │   │   └── If price changed: item->update(price, total_price)
         │   │   │   └── cart->refresh(), re-load items
         │   │   ├── Coupon validation with lock:
         │   │   │   ├── Coupon::lockForUpdate() if coupon on cart
         │   │   │   └── If invalid: cart->update(coupon=null)
         │   │   ├── CheckoutTotals::calculateCheckoutTotals(cart, promotion, gift)
         │   │   │   ├── PromotionService::applySelectedPromotion()
         │   │   │   │   ├── Promotion::lockForUpdate() + validate
         │   │   │   │   ├── Remove old gift items, release stock
         │   │   │   │   ├── Applicator applies discount to cart items
         │   │   │   │   ├── Applicator adds gift items (reserve stock for gift)
         │   │   │   │   └── Returns subtotal, discount, finalTotal
         │   │   │   └── CouponCalculator::calculate() — applies coupon to total
         │   │   ├── Minimum order check
         │   │   ├── resolveShippingPrice(governorate_id)
         │   │   ├── resolveFreeShippingByThreshold()
         │   │   ├── orderCreationService::createOrder(data, cart, totals)
         │   │   │   ├── Build order data array from cart + totals
         │   │   │   ├── Snapshot pickup location data
         │   │   │   └── Order::create(orderData) — new row
         │   │   ├── orderCreationService::createOrderItems(order, cart)
         │   │   │   ├── For each cart item:
         │   │   │   │   ├── Compute flash sale price, discount price
         │   │   │   │   └── OrderProduct::create() — one per item
         │   │   │   └── On failure: rollback, return false
         │   │   ├── orderCreationService::finalizeOrder(order, totals)
         │   │   │   └── OrderCreated::dispatch(order) — EVENT FIRED
         │   │   └── COMMIT
         │   │
         │   └── On success: proceed to payment
         │
         │   Step 5: Payment method routing
         │   ├── ONLINE: paymentCheckoutHandler->handleOnlinePayment()
         │   │   ├── PaymentGatewayFactory::make('myfatoorah')
         │   │   ├── gateway->createInvoice(order, amount, callback, error)
         │   │   ├── Transaction::create() — status=pending
         │   │   │   Fields: order_id, user_id, invoice_id, payment_method=gateway,
         │   │   │          status=pending, amount, currency, gateway_transaction_id
         │   │   └── Return { url: redirectUrl } to frontend
         │   │
         │   ├── COD: paymentCheckoutHandler->handleCodPayment()
         │   │   ├── Transaction::create() — payment_method=cod, status=pending
         │   │   └── Return { order_id } to frontend
         │   │
         │   └── CASHIER: paymentCheckoutHandler->handleCashierQrPayment()
         │       ├── Transaction::create() — payment_method=pay_at_cashier, status=pending
         │       ├── CashierQrService::generateBase64DataUri(transaction)
         │       └── Return { order_id, transaction_uuid, qr_code }

t=0:50  Frontend receives response
         ├── Online: Redirect customer to payment gateway URL
         ├── COD: Show order confirmation with order number
         └── Cashier: Show QR code for in-store scanning
```

### Phase D: Payment Gateway (t=0:55 to t=2:00)

```
t=0:55  Customer redirected to MyFatoorah payment page
         ├── Customer enters card details
         └── Payment processed by MyFatoorah

t=1:00  (COD/Cashier skip this phase)

t=1:30  MyFatoorah redirects to callback URL
         ├── GET /api/v1/general/checkout/callback?paymentId=xxx
         │   └── OrderController::checkoutCallback()
         │
         │   Step 1: Extract paymentId
         │   ├── $paymentId = request()->query('paymentId')
         │   └── If null: return 400 MISSING_PAYMENT_ID
         │
         │   Step 2: Find transaction
         │   ├── Transaction::where('gateway_transaction_id', $paymentId)
         │   │   ->orWhere('invoice_id', $paymentId)->first()
         │   └── gatewayName = transaction->payment_method ?? 'myfatoorah'
         │
         │   Step 3: Verify with gateway
         │   ├── PaymentGatewayFactory::make(gatewayName)
         │   ├── result = gateway->verifyPayment(paymentId)
         │   └── verifiedInvoiceId = result->gatewayTransactionId
         │
         │   Step 4: Handle failure
         │   ├── if !result->success:
         │   │   ├── transaction->update(status=failed, ...)
         │   │   ├── event(PaymentFailed(order))
         │   │   └── redirect /payment/failed?status=failed&message=...
         │   └── (BUG-4: This does NOT cover error callback properly)
         │
         │   Step 5: Check mismatches
         │   ├── Amount mismatch: abs(result->amount - order->total) > 0.01
         │   ├── Currency mismatch: result->currency !== config('default_currency')
         │   └── If mismatch:
         │       ├── transaction->update(error_message)
         │       ├── event(PaymentFailed(order))
         │       └── redirect /payment/failed
         │
         │   Step 6: Finalize order (THE CRITICAL TRANSACTION)
         │   ├── DB::transaction():
         │   │   ├── Transaction::lockForUpdate() — re-find with lock
         │   │   ├── Order::lockForUpdate() — lock order row
         │   │   ├── if order->status !== 'pending': return (idempotent)
         │   │   │
         │   │   ├── Transaction::update(status=paid, paid_at=now())
         │   │   ├── Order::update(payment_status=payment-success, paid_at=now())
         │   │   │
         │   │   ├── INVENTORY FINALIZATION:
         │   │   │   ├── cart = getActiveCartForUser(user)
         │   │   │   ├── if cart:
         │   │   │   │   ├── Cart::lockForUpdate()
         │   │   │   │   ├── CartItem::lockForUpdate() WHERE shipping_method=SCHEDULED
         │   │   │   │   │   └── finalizeStock(): decrement stock, decrement reserved, increment sold
         │   │   │   │   ├── CartItem::lockForUpdate() WHERE NOT SCHEDULED
         │   │   │   │   │   └── releaseStock(): decrement reserved only
         │   │   │   │   ├── Delete all cart items
         │   │   │   │   └── Cart::update(status=checked_out, prices cleared)
         │   │   │   └── else:
         │   │   │       └── deductStockForOrder(order) — direct deduction
         │   │   │
         │   │   ├── PROMOTION FINALIZATION:
         │   │   │   ├── finalizePromotionUsageAfterPayment(order)
         │   │   │   └── Promotion::increment('usage'), order->promotion_consumed=true
         │   │   │
         │   │   ├── ORDER STATUS CHANGE:
         │   │   │   ├── changeOrderStatus(invoice_id, 'completed')
         │   │   │   ├── Validates: pending → completed
         │   │   │   ├── Order::update(status=completed, fulfillment_status=processing)
         │   │   │   ├── recordCouponUsage(order):
         │   │   │   │   ├── if coupon_consumed: return
         │   │   │   │   ├── if assigned coupon:
         │   │   │   │   │   ├── CouponAssignment::lockForUpdate()
         │   │   │   │   │   ├── CouponAssignmentUsage::lockForUpdate() — prevent double
         │   │   │   │   │   ├── coupon->increment(used)
         │   │   │   │   │   ├── assignment->increment(used)
         │   │   │   │   │   ├── CouponAssignmentUsage::create()
         │   │   │   │   │   └── DB::afterCommit: AssignedCouponConsumed event
         │   │   │   │   └── if public coupon:
         │   │   │   │       ├── CouponUsage::firstOrCreate(coupon_id, user_id)
         │   │   │   │       └── coupon->increment(used) if wasRecentlyCreated
         │   │   │   ├── order->update(coupon_consumed=true)
         │   │   │   ├── ORDERSTATUSCHANGED event fired
         │   │   │   └── COMMIT
         │   │   │
         │   │   ├── processed = true
         │   │   └── COMMIT
         │   │
         │   ├── if processed:
         │   │   └── event(PaymentSucceeded(order->fresh()))
         │   │
         │   └── Frontend redirect:
         │       ├── if mobile: return JSON success
         │       └── if web: redirect /payment/success?order_id=xxx
```

### Phase E: Invoice Generation (t=2:00 to t=2:30)

```
t=2:00  PaymentSucceeded event dispatches to queue
         ├── GenerateInvoiceListener (queue:high, tries:5, backoff:10/30/60/120/300s)
         ├── SendPaymentSucceededNotification (queue:medium)
         └── (Note: PaymentSucceeded is synchronous in callback's Post-transaction block)

t=2:01  GenerateInvoiceListener::handle(PaymentSucceeded $event)
         └── InvoiceService::generateFromOrder($order)
             ├── DB::transaction():
             │   ├── Invoice::where('order_id', $order->id)->lockForUpdate()->first()
             │   │   └── If exists: return (IDEMPOTENT — prevents duplicate invoices)
             │   │
             │   ├── InvoiceSnapshotService::buildFullSnapshot($order)
             │   │   └── Returns complete array:
             │   │       ├── snapshot_version: "2.1.0"
             │   │       ├── snapshot_schema: 3
             │   │       ├── order: {id, order_number, status, payment_status, fulfillment_status}
             │   │       ├── customer: {id, name, email, phone}
             │   │       ├── billing_address: {street, city, state, governorate, zip, country}
             │   │       ├── shipping_address: same as billing
             │   │       ├── fulfillment: {type, method, price, eta}
             │   │       ├── pickup_location: {id, name, address} or null
             │   │       ├── items: [{product, variant, qty, unit_price, discount, flash_sale, total, is_gift}]
             │   │       ├── pricing_breakdown: {subtotal, discounts, shipping, total, coupon, promotion}
             │   │       ├── payment: {method, gateway, transaction_id, paid_at}
             │   │       ├── taxes: [] (empty — not implemented)
             │   │       └── metadata: {system_version, locale, generated_at}
             │   │
             │   ├── InvoiceSnapshotValidator::validate($snapshot)
             │   │   ├── StructureValidator — required keys present
             │   │   ├── FinancialInvariantValidator — subtotal-discounts+shipping=total
             │   │   ├── CurrencyValidator — consistent currency
             │   │   ├── MoneyValidator — no negative values
             │   │   ├── MetadataValidator — metadata present
             │   │   └── SnapshotVersionValidator — version matches
             │   │
             │   ├── SnapshotIntegrityService::computeHash($snapshot)
             │   │   └── Recursively ksort, json_encode, SHA-256
             │   │
             │   ├── InvoiceNumberService::generateNext('INV')
             │   │   └── DB::transaction():
             │   │       ├── InvoiceSequence::lockForUpdate(series='INV', year=2026)
             │   │       ├── If not exist: create with last_sequence=0
             │   │       ├── increment('last_sequence')
             │   │       └── Return: INV-2026-000001
             │   │
             │   ├── Invoice::create({
             │   │   order_id, transaction_id, user_id,
             │   │   invoice_number: "INV-2026-000001",
             │   │   subtotal, shipping_price, coupons, promotions, total, amount_paid,
             │   │   currency, payment_method, payment_gateway,
             │   │   status: 'generated',
             │   │   data: {full snapshot},
             │   │   snapshot_hash: sha256-of-snapshot,
             │   │   verification_hash: sha256(snapshot_hash . app_key),
             │   │   generated_at: now(), generated_by: 'system'
             │   │ })
             │   │
             │   ├── InvoiceTimeline::recordGenerated(invoice)
             │   │   └── Timeline entry: invoice_id, event='generated', metadata
             │   │
             │   └── DB::afterCommit():
             │       ├── InvoiceCreated::dispatch(invoice)
             │       └── GenerateInvoicePdfJob::dispatch(invoice) — queue:low
             │
             ├── Database writes:
             │   ├── invoices: new row
             │   ├── invoice_timeline: new row
             │   └── invoice_sequences: last_sequence incremented
             └── On failure: job retries up to 5 times (10s, 30s, 60s, 120s, 300s)

t=2:05  InvoiceCreated → LogInvoiceCreated (synchronous — just logs to laravel.log)

t=2:06  GenerateInvoicePdfJob::handle() — queue:low, tries:3
         └── PLACEHOLDER (BUG-8):
             ├── Log::info('PDF generation placeholder')
             ├── Invoice::update(status='ready', pdf_generated_at=now())
             └── No actual PDF generated

t=2:07  SendPaymentSucceededNotification (queue:medium)
         └── LogActivityJob::dispatch() — records payment_success activity

t=2:30  Invoice available for customer
         ├── GET /api/v1/general/invoices/my-invoices (auth) — returns invoice list
         └── GET /api/v1/general/invoices/uuid/{uuid} (auth) — returns invoice detail
```

### Phase F: Post-Payment Notifications (t=2:00 to t=2:10)

```
t=2:00  OrderCreated event was dispatched during checkout
         └── SendNewOrderNotification (queue:medium)
             ├── Notification::send(admins, NewOrderNotification)
             └── LogActivityJob::dispatch() — records order_created

t=2:02  OrderStatusChanged event fired inside changeOrderStatus
         └── SendOrderStatusChangedNotification (queue:medium)
             └── LogActivityJob::dispatch() — records order_status_changed
```

### Phase G: Customer Order Management (t=2:30 to t=1day)

```
t=2:30  Customer views their orders
         ├── GET /api/v1/general/orders (auth)
         │   └── OrderController::index()
         │       └── OrderService::paginateForUser()
         │           ├── Order::forUser($userId)->with(orderItems, transactions, etc)
         │           └── Each product enriched with pricing
         └── Returns OrderCollection with order summary

t=2:35  Customer views order detail (frontend route, not API)

t=2:40  Customer downloads invoice
         ├── GET /api/v1/general/invoices/uuid/{uuid}/download (auth)
         │   └── InvoiceController::download()
         │       ├── Check authorization (owner or permission:view_invoice)
         │       ├── Check pdf_path exists → 404 'PDF not yet generated'
         │       └── Return { url: storage/invoices/xxxx.pdf }
         └── Problem: PDF doesn't actually exist (BUG-8)

t=2:45  Customer verifies invoice
         ├── GET /api/v1/general/invoices/verify/{uuid} (public, throttle:60/min)
         │   └── InvoiceController::verify()
         │       ├── InvoiceService::verifyInvoice(uuid)
         │       │   ├── Compute expected = hash('sha256', snapshot_hash . app_key)
         │       │   ├── Compare with stored verification_hash via hash_equals()
         │       │   └── Return { authentic: bool, tampered: bool }
         │       ├── Invoice::increment('verify_count')
         │       ├── Invoice::update(verified_at, last_verified_at)
         │       └── Timeline::recordVerified(invoice)
         └── Response: { authentic: true/false, invoice, order, qr_content }
```

### Phase H: Fulfillment & Shipping (t=1hour to t=3days)

```
t=1h  Admin starts processing order
         ├── Admin views order in dashboard
         ├── Admin updates order status: pending → processing
         │   └── OrderService::changeOrderStatus(invoiceId, 'processing')
         │       ├── Validates transition
         │       ├── Order::update(status=processing, fulfillment_status=processing)
         │       ├── OrderStatusChanged event fired
         │       └── SendOrderStatusChangedNotification (queued)
         │
         ├── Admin creates shipment
         │   └── POST /api/v1/general/shipments (auth)
         │       └── ShipmentService::create(data)
         │           ├── Shipment::create(status=pending, order_id, ...)
         │           └── NOTE: No events fired for shipment creation

t=2h  Admin updates shipment status
         ├── PUT /api/v1/general/shipments/{id}/status (auth)
         │   └── ShipmentService::updateStatus(id, 'label_created')
         │       ├── Shipment::lockForUpdate()
         │       ├── Validates transition via ShipmentStatus enum
         │       └── Shipment::update(status=label_created)
         └── NOTE: No events, no notifications, no customer update

t=4h  Courier picks up
         ├── PUT /shipments/{id}/status → status=picked_up
         └── Shipment::update(shipped_at=now())

t=1d  In transit
         ├── status=in_transit
         ├── status=delayed (if needed, can go back to in_transit or out_for_delivery)
         └── No customer notification

t=2d  Out for delivery
         └── status=out_for_delivery

t=3d  Delivered
         ├── status=delivered (terminal)
         ├── Shipment::update(delivered_at=now())
         ├── Can transition order: completed → delivered
         │   └── OrderService::changeOrderStatus(invoiceId, 'delivered')
         │       ├── Order::update(status=delivered, fulfillment_status=delivered)
         │       └── OrderStatusChanged event
         └── Customer notified? Only if OrderStatusChanged listener sends notification
```

### Phase I: Post-Delivery (t=3d to t=30d)

```
t=3d  Refund requested (if applicable)
         ├── Admin approves refund
         │   └── RefundApproved event fires
         │       ├── RestoreInventoryOnRefund (queue:medium)
         │       │   ├── DB::transaction: lock order, check inventory_restored_at
         │       │   ├── For each item: restore stock_quantity, decrement sold_quantity
         │       │   └── Order::update(inventory_restored_at=now())
         │       │
         │       ├── GenerateCreditNoteOnRefund (queue:medium)
         │       │   ├── Find active invoice for order
         │       │   ├── CreditNoteService::generateForRefund()
         │       │   │   ├── InvoiceNumberService::generateNext('CN') — CN series
         │       │   │   └── CreditNote::create() — new row
         │       │   ├── Invoice::update(status=corrected)
         │       │   └── Timeline::recordCorrected()
         │       │
         │       └── RatingRemoved — removes product rating
         │
         └── Payment gateway refund (not yet integrated in app code)

t=30d  Invoice archived (if cron exists)
         └── (Not implemented — status can be manually set to archived)
```

---

## 15.2 Concurrency Protection Summary

| Point | Protection | Risk if Missing |
|-------|-----------|----------------|
| Add to cart | `lockForUpdate` on cart + product/variant | Two customers reserving same last item |
| Checkout | `lockForUpdate` on cart | Double order from same cart |
| Payment callback | `lockForUpdate` on transaction + order | Double payment processing |
| Coupon usage | `lockForUpdate` on assignment + usage | Over-consuming coupon quota |
| Invoice generation | `lockForUpdate` on order | Duplicate invoices |
| Invoice numbering | `lockForUpdate` on sequence | Duplicate invoice numbers |
| Inventory restore | `lockForUpdate` + `inventory_restored_at` | Double inventory restoration |
| Promotion usage | `promotion_consumed` flag | Double counting promotion usage |
| Coupon consumption | `coupon_consumed` flag | Double counting coupon |

## 15.3 Failure Points & Recovery Summary

| Step | Failure | Detection | Recovery |
|------|---------|-----------|----------|
| Cart reservation | Stock insufficient | Returned to customer | Reduce quantity |
| Checkout | DB error | 500 response | Retry |
| Payment gateway | Gateway down | Error creating invoice | Try different gateway |
| Payment callback | Amount mismatch | Logged, blocked | Manual reconciliation |
| Invoice generation | Queue failure | `last_generation_error` field | Admin regenerate |
| PDF generation | Placeholder | No PDF exists | Implement actual PDF |
| Inventory restore | Queue failure | `inventory_restored_at` still null | Re-dispatch job |
| Credit note | Queue failure | No credit note created | Manually generate |
| Order status change | Invalid transition | RuntimeException | Check allowed transitions |

## 15.4 Database Tables Touched (Complete Lifecycle)

| Table | Phase | Operations |
|-------|-------|-----------|
| `carts` | A, C, D | SELECT, UPDATE (status, coupon, totals) |
| `cart_items` | A, C, D | SELECT, INSERT, UPDATE, DELETE |
| `products` | A, C, D | SELECT, UPDATE (stock, reserved, sold) |
| `product_variants` | A, C, D | SELECT, UPDATE (same) |
| `coupons` | B, D | SELECT, UPDATE (used) |
| `coupon_usages` | D | SELECT, INSERT (firstOrCreate) |
| `coupon_assignments` | D | SELECT, UPDATE (used) |
| `coupon_assignment_usages` | D | INSERT |
| `promotions` | B, D | SELECT, UPDATE (usage) |
| `orders` | C, D, H | INSERT, UPDATE (status, payment, fulfillment) |
| `order_products` | C | INSERT |
| `transactions` | C, D | INSERT, UPDATE (status, paid_at) |
| `invoices` | E | INSERT, UPDATE (status, timestamps) |
| `invoice_timeline` | E | INSERT |
| `invoice_sequences` | E | UPDATE (last_sequence) |
| `credit_notes` | I | INSERT |
| `shipments` | H | INSERT, UPDATE (status, timestamps) |
| `activity_log` | Various | INSERT (via spatie/activitylog) |
