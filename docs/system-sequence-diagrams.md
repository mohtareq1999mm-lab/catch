# System Sequence Diagrams

> Version: 1.0 | Classification: INTERNAL | Last Updated: 2026-07-27

---

## 1. Checkout Flow — Online Payment (MyFatoorah)

```mermaid
sequenceDiagram
    participant U as User
    participant C as OrderController
    participant OS as OrderService
    participant OCS as OrderCreationService
    participant CIS as CartInventoryService
    participant PS as PromotionService
    participant CS as CouponService
    participant PCH as PaymentCheckoutHandler
    participant MG as MyFatoorahGateway
    participant DB as Database
    participant Q as Queue

    U->>C: POST /checkout (OrderCreateRequest)
    C->>OS: addItemsInOrder(cart, request)
    OS->>CIS: refreshCartItemPrices()
    CIS->>DB: re-read prices from ProductPricingService
    DB-->>CIS: current prices
    CIS-->>OS: prices refreshed
    OS->>DB: lockForUpdate() on cart items
    OS->>CS: validate coupon
    CS->>DB: lockForUpdate() on coupon
    CS-->>OS: coupon valid
    OS->>OS: getCheckoutTotalsFromCart()  (reads persisted values)
    OS->>OS: resolveShippingPrice(governorate_id)
    OS->>OS: resolveFreeShippingByThreshold()
    OS->>OS: resolveFreeShippingByCoupon()
    OS->>OCS: createOrder(totalPrice)
    OCS->>DB: INSERT orders (status=pending)
    OCS->>OCS: createOrderItems(cart items → order_products)
    OCS->>CIS: finalizeItemsByShippingMethod()
    CIS->>DB: finalizeStock() on each item
    OCS->>PS: incrementUsage(promotionId)
    PS->>DB: lockForUpdate() on promotion → increment
    OCS-->>OS: order created
    OS->>U: event(new OrderCreated($order))
    U-->>U: SendNewOrderNotification (queued medium)
    OS-->>C: order + redirect URL
    C->>PCH: handleOnlinePayment(order)
    PCH->>MG: createInvoice(order, amount, callbackUrl)
    MG->>MG: POST /v2/SendPayment
    MG-->>PCH: GatewayResult(redirectUrl, invoiceId)
    PCH->>DB: INSERT transaction (status=pending)
    PCH-->>C: redirect URL
    C-->>U: API Response { redirect_url }

    U->>MG: User redirected to MyFatoorah
    MG->>MG: User completes payment
    MG-->>U: Redirect to callback URL

    U->>C: GET /checkout/callback?paymentId=xxx
    C->>MG: verifyPayment(paymentId)
    MG->>MG: POST /v2/GetPaymentStatus
    MG-->>C: GatewayResult(amount, currency, status)
    C->>C: amount mismatch check (tolerance 0.01)
    C->>C: currency mismatch check
    alt Mismatch
        C->>DB: transaction.status = 'failed'
        C->>U: event(PaymentFailed) → redirect to failure
    else Verified
        C->>DB: BEGIN TRANSACTION lockForUpdate()
        C->>DB: transaction.status = 'paid', paid_at = now()
        C->>CIS: finalizeItemsByShippingMethod()
        CIS->>DB: stock -= qty, reserved -= qty, sold += qty
        C->>OS: changeOrderStatus(invoiceId, 'completed')
        OS->>DB: order.status = 'completed'
        OS->>CS: recordCouponUsage()
        OS->>PS: finalizePromotionUsageAfterPayment()
        OS->>U: event(PaymentSucceeded)
        C->>DB: COMMIT
        C-->>U: Redirect to success page
        U->>Q: GenerateInvoiceListener (queued high)
        Q->>Q: InvoiceService::generateFromOrder()
        U->>Q: SendPaymentSucceededNotification (queued medium)
    end
```

---

## 2. Checkout Flow — COD / Cashier

```mermaid
sequenceDiagram
    participant U as User
    participant C as OrderController
    participant OS as OrderService
    participant PCH as PaymentCheckoutHandler
    participant DB as Database

    U->>C: POST /checkout (payment_method=cod)
    C->>OS: addItemsInOrder(cart, request)
    OS->>OS: full checkout processing
    OS-->>C: order created
    C->>PCH: handleCodPayment(order)
    PCH->>DB: INSERT transaction (status=pending, payment_method=cod)
    PCH-->>C: success response
    C-->>U: { success: true, message: "order placed" }

    Note over U,DB: Later - Admin marks as paid
    Admin->>C: POST /checkout/cod/{orderId}/mark-paid
    C->>OS: markCodAsPaid(orderId)
    OS->>DB: lockForUpdate() on transaction
    OS->>DB: transaction.status = 'paid', paid_at = now()
    OS->>DB: order.status = 'completed'
    OS->>CS: recordCouponUsage()
    OS->>PS: finalizePromotionUsageAfterPayment()
    OS->>CIS: finalizeItemsByShippingMethod()
    OS->>U: event(PaymentSucceeded)
    C-->>Admin: { success: true }
```

---

## 3. Cart — Add Item & Inventory Reservation

```mermaid
sequenceDiagram
    participant U as User
    participant C as CartController
    participant CIS as CartInventoryService
    participant PPS as ProductPricingService
    participant DB as Database

    U->>C: POST /cart { product_id, quantity }
    C->>CIS: reserveItem(user, product_id, quantity, ...)
    CIS->>DB: BEGIN TRANSACTION
    
    CIS->>PPS: calculateProductCurrentPrice(product)
    PPS->>PPS: check flash_sale → sale discount → base price
    PPS-->>CIS: final_price
    
    CIS->>DB: lockForUpdate() on cart where user_id
    CIS->>DB: lockForUpdate() on product/variant row
    
    CIS->>CIS: available = stock - reserved >= quantity?
    alt Insufficient Stock
        CIS->>DB: ROLLBACK
        CIS-->>C: 409 Insufficient Stock
    else Stock Available
        CIS->>DB: INSERT/UPDATE cart_item (price, total_price, quantity)
        CIS->>DB: UPDATE products/variants SET reserved_quantity += quantity
        CIS->>DB: UPDATE products/variants SET in_stock = (available > 0)
        CIS->>DB: COMMIT
        CIS-->>C: cart item created
        C-->>U: { success: true, data: cartItem }
    end
```

---

## 4. Coupon Application Flow

```mermaid
sequenceDiagram
    participant U as User
    participant CC as CouponController
    participant CS as CouponService
    participant CO as CouponOrchestrator
    participant CV as CouponValidator
    participant CAV as CouponAssignmentValidator
    participant CCALC as CouponCalculator
    participant DB as Database

    U->>CC: POST /coupons/apply { code, cart_id }
    CC->>CS: applyCoupon(request)
    CS->>CO: validateCoupon(code, cart, user)
    CO->>CV: validate(coupon, request)
    CV->>DB: check coupon exists
    CV->>CV: check status = 'active'
    CV->>CV: check dates (start/end)
    CV->>CV: check global limiter (used < limiter)
    CV->>CV: check per-user usage (coupon_user)
    CV->>CV: check product scope (if applicable)
    CV-->>CO: valid
    CO->>CAV: validate(coupon, user)
    CAV->>DB: check coupon_assignments (if assigned)
    CAV->>CAV: check assignment.used < max_uses
    CAV-->>CO: valid
    CO-->>CS: coupon validated
    
    CS->>CCALC: calculate(coupon, subtotal)
    CCALC->>CCALC: percentage → subtotal * discount/100, capped by max_discount_amount
    CCALC->>CCALC: fixed_rate → min(discount, subtotal)
    CCALC->>CCALC: free_shipping → no subtotal discount, zeroes shipping
    CCALC-->>CS: { discount, discount_type }
    
    CS->>DB: cart.update(['coupon' => code])
    CS-->>CC: CheckoutTotals with coupon applied
    CC-->>U: { success: true, data: { subtotal, discount, finalTotal } }
```

---

## 5. Promotion Application Flow

```mermaid
sequenceDiagram
    participant C as OrderController
    participant OS as OrderService
    participant PES as PromotionEligibilityResolver
    participant PS as PromotionService
    participant PA as PromotionApplicator
    participant DB as Database

    C->>OS: calcInvoicePrice(cart, request)
    OS->>PS: applySelectedPromotion(cart, promotion_id)
    PS->>PES: resolve(cart, promotion)
    PES->>PES: filter cart items by product scope
    PES-->>PS: eligible items + metadata
    
    loop Each eligible item
        PS->>PS: PromotionStrategy(item, promotion)
        alt Percentage
            PS->>PS: lineCents * (value / 100), capped by max_discount_amount
        else Fixed
            PS->>PS: toCents(value) per item
        else Gift
            PS->>PS: gift product lookup, price = 0
        end
        PS-->>PS: item discount in integer cents
    end
    
    PS->>PA: applyOutcome(cart, outcome)
    PA->>DB: lockForUpdate() on promotion
    PA->>PA: largest-remainder allocation
    PA->>DB: UPDATE cart_items SET discount_amount, total_price, promotion_id, is_gift
    PA->>DB: COMMIT
    PA-->>PS: promotion applied
    PS-->>OS: CheckoutTotals DTO
    OS-->>C: { subtotal, promotionDiscount, finalTotal }
```

---

## 6. Payment Gateway Callback & Reconciliation

```mermaid
sequenceDiagram
    participant U as User
    participant C as OrderController
    participant MG as MyFatoorahGateway
    participant DB as Database
    participant Q as Queue
    participant PRJ as PaymentReconciliationJob

    U->>C: GET /checkout/callback?paymentId=xxx
    C->>MG: verifyPayment(paymentId)
    MG->>MG: POST /v2/GetPaymentStatus
    MG-->>C: { amount, currency, status, gatewayTxId }
    
    C->>C: abs(gatewayAmount - orderTotal) > 0.01?
    
    alt Amount Mismatch
        C->>DB: ROLLBACK
        C->>DB: transaction.status = 'failed'
        C->>U: event(PaymentFailed)
        C-->>U: Redirect to failure page
    else Amount OK
        C->>DB: BEGIN TRANSACTION lockForUpdate()
        C->>DB: transaction.status = 'paid', paid_at = now()
        C->>DB: order.status = 'completed'
        C->>DB: finalized stock
        C->>DB: COMMIT
        
        C->>U: event(PaymentSucceeded)
        U->>Q: GenerateInvoiceListener (high)
        U->>Q: SendPaymentSucceededNotification (medium)
        C-->>U: Redirect to success page
    end
    
    Note over PRJ,DB: Scheduled: php artisan payments:reconcile
    PRJ->>PRJ: Query all non-failed transactions with gateway_tx_id
    loop Each transaction
        PRJ->>MG: verifyPayment(gatewayTxId)
        MG-->>PRJ: { amount, currency, status }
        PRJ->>PRJ: Compare amount (tol 0.01), currency, status
        alt Mismatch found
            PRJ->>DB: INSERT payment_reconciliation_result
        end
    end
```

---

## 7. Cart Expiration & Order Cancellation

```mermaid
sequenceDiagram
    participant CMD as CancelUnpaidOrders Command
    participant CMD2 as ExpireAbandonedCarts Command
    participant OS as OrderService
    participant CIS as CartInventoryService
    participant DB as Database
    participant Q as Queue

    Note over CMD,DB: Every minute: orders:cancel-unpaid
    
    CMD->>DB: SELECT orders WHERE status=pending AND created_at < now - 72h
    loop Each expired order
        CMD->>DB: lockForUpdate() on order
        CMD->>OS: changeOrderStatus(invoiceId, 'cancelled')
        OS->>DB: order.status = 'cancelled'
        OS->>DB: transaction.status = 'failed'
        OS->>PS: decrementUsage(promotionId) (if not already cancelled)
        OS->>Q: event(OrderCancelled)
        Q->>Q: RestoreProductInventory (queued medium)
        Q->>Q: SendOrderCancelledNotification (queued medium)
    end
    
    Note over CMD2,DB: Every 5 minutes: cart:expire
    
    CMD2->>CIS: expireCarts()
    CIS->>DB: SELECT carts WHERE status=active AND updated_at < threshold
    loop Each expired cart (chunkById 100)
        CIS->>DB: lockForUpdate() on cart + items
        CIS->>DB: releaseStock() on each item
        CIS->>DB: reserved_quantity -= qty, in_stock = recalculated
        CIS->>DB: cart.status = 'expired'
        CIS->>DB: DELETE cart items
        CIS->>DB: cart.total_price = 0
    end

    Note over CMD2,DB: RestoreProductInventory handler
    Q->>DB: lockForUpdate() on order
    Q->>DB: WHERE inventory_restored_at IS NULL
    Q->>DB: product.stock_quantity += qty
    Q->>DB: product.sold_quantity -= qty
    Q->>DB: SET inventory_restored_at = now()
```

---

## 8. Invoice Generation & PDF Lifecycle

```mermaid
sequenceDiagram
    participant E as PaymentSucceeded Event
    participant GL as GenerateInvoiceListener
    participant IS as InvoiceService
    participant ISS as InvoiceSnapshotService
    participant ING as InvoiceNumberService
    participant GJ as GenerateInvoicePdfJob
    participant DB as Database
    participant C as InvoiceController

    E->>GL: PaymentSucceeded(order)
    GL->>IS: generateFromOrder(order)
    IS->>ISS: buildSnapshot(order)
    ISS->>ISS: collect pricing_breakdown
    ISS->>ISS: collect items_snapshot
    ISS->>ISS: collect payment_snapshot
    ISS->>ISS: validate financial invariant
    ISS-->>IS: snapshot data
    
    IS->>ING: generateNext()
    ING->>DB: lockForUpdate() on invoice_sequences
    ING->>DB: SELECT current_number WHERE year = YYYY
    ING->>DB: UPDATE set current_number += 1
    ING-->>IS: next_number (e.g., INV-2026-00001)
    
    IS->>DB: INSERT invoices (status='generated', snapshot, number)
    IS->>GJ: dispatch(invoice) on 'low' queue
    GJ->>DB: invoice.status = 'pdf_generating'
    GJ->>GJ: generate PDF (placeholder)
    alt Success
        GJ->>DB: invoice.status = 'ready', pdf_generated_at = now()
    else Failure
        GJ->>DB: invoice.status = 'failed'
        GJ->>DB: invoice.last_generation_error = exception message
        GJ->>DB: invoice.generation_attempts += 1
    end
    
    Note over C,DB: Regeneration (admin)
    C->>IS: regenerate(invoice)
    IS->>IS: check status in ['failed', 'ready']
    IS->>GJ: dispatch(invoice)
    GJ->>DB: invoice.status = 'pdf_generating'
```

---

## 9. Refund Approval Flow

```mermaid
sequenceDiagram
    participant Admin as Super Admin
    participant RC as RefundController
    participant RR as RefundRepository
    participant MG as MyFatoorahGateway
    participant OS as OrderService
    participant DB as Database
    participant Q as Queue

    Admin->>RC: PUT /refunds/{id} { status: 'approved' }
    RC->>RR: updateRefund(refund, request)
    RR->>RR: check not already approved
    RR->>RR: check permission (SUPER_ADMIN)
    
    RR->>MG: refund(order, amount)
    MG->>MG: POST /v2/MakeRefund
    alt Gateway refund fails
        MG-->>RC: 400 Gateway Error
        RC-->>Admin: refund failed
    else UnsupportedGateway (COD/cashier)
        MG-->>RC: UnsupportedGatewayException → skip gracefully
    else Gateway refund succeeds
        MG-->>RR: RefundId, RefundStatus
    end
    
    RR->>DB: BEGIN TRANSACTION lockForUpdate()
    RR->>DB: refund.status = 'approved'
    RR->>DB: order.status = 'refunded'
    RR->>DB: order.payment_status = 'refunded'
    
    RR->>DB: balance.decrement('total_earnings')
    RR->>DB: balance.decrement('current_balance')
    RR->>DB: wallet.increment('total_points')
    RR->>DB: wallet.increment('available_points')
    RR->>DB: COMMIT
    
    RR->>Q: event(RefundApproved)
    Q->>Q: RestoreInventoryOnRefund (queued medium)
    Q->>Q: locked product rows, restored stock_quantity
    Q->>Q: sold_quantity decremented
    Q->>Q: RatingRemoved (sync) → delete associated review
    
    RC-->>Admin: { success: true, refund: { status: 'approved' } }
```

---

## 10. Full Order Lifecycle State Machine

```mermaid
stateDiagram-v2
    [*] --> Pending: Order Created
    
    Pending --> Processing: Payment Initiated
    Pending --> Cancelled: Auto-cancel / Admin cancel
    Pending --> Completed: Payment Callback
    
    Processing --> Completed: Payment Confirmed
    Processing --> Cancelled: Payment Failed
    
    Completed --> Delivered: Admin marks delivered
    Completed --> Cancelled: Admin cancels (refund)
    
    Cancelled --> [*]
    Delivered --> [*]
    
    note right of Pending
        Transaction: pending
        Inventory: reserved
        Coupon: not yet recorded
        Promotion: not yet incremented
    end note
    
    note right of Completed
        Transaction: paid
        Inventory: finalized (sold)
        Coupon: usage recorded
        Promotion: usage incremented
        Invoice: generated → ready
        Event: PaymentSucceeded
    end note
    
    note right of Cancelled
        Transaction: failed
        Inventory: restored
        Promotion: usage decremented
        Event: OrderCancelled
    end note
```

---

## 11. Inventory Quantity State Machine

```mermaid
stateDiagram-v2
    state "Available Stock" as S
    state "Reserved in Cart" as R
    state "Sold (Finalized)" as F
    
    [*] --> S: Product Created / Stock Added
    
    S --> R: add to cart (reserveStock)
    R --> S: cart expired / item removed (releaseStock)
    
    R --> F: payment confirmed (finalizeStock)
    
    F --> S: order cancelled (RestoreProductInventory)
    F --> S: refund approved (RestoreInventoryOnRefund)
    
    note right of S
        stock_quantity = physical count
        in_stock = (stock - reserved) > 0
    end note
    
    note right of R
        reserved_quantity += qty
    end note
    
    note right of F
        stock_quantity -= qty
        reserved_quantity -= qty
        sold_quantity += qty
    end note
```

---

## 12. Transaction Lifecycle

```mermaid
sequenceDiagram
    participant C as Checkout
    participant PCH as PaymentCheckoutHandler
    participant CB as Callback
    participant EC as Error Callback
    participant MC as Mark COD/Cashier
    participant DB as Database

    C->>PCH: handleOnlinePayment / handleCodPayment / handleCashierQrPayment
    PCH->>DB: INSERT transaction (status='pending', payment_method)
    
    alt Online Payment
        CB->>DB: lockForUpdate() on transaction
        CB->>DB: UPDATE transaction SET status='paid', paid_at=now()
        CB->>DB: UPDATE order SET status='completed'
        CB-->>DB: COMMIT
    else Online Failed
        EC->>DB: lockForUpdate() on transaction
        EC->>DB: UPDATE transaction SET status='failed', error_message=...
        EC-->>DB: COMMIT
    else COD/Cashier Admin Mark
        MC->>DB: lockForUpdate() on transaction
        MC->>DB: UPDATE transaction SET status='paid', paid_at=now()
        MC->>DB: UPDATE order SET status='completed'
        MC-->>DB: COMMIT
    end
```

---

## 13. Fast Shipping Checkout vs Normal Checkout

```mermaid
sequenceDiagram
    participant U as User
    participant FC as FastShippingController
    participant C as OrderController
    participant FSS as FastShippingService
    participant OS as OrderService
    participant DB as Database

    Note over U,DB: Normal Checkout
    U->>C: POST /checkout (shipping_method=SCHEDULED)
    C->>OS: addItemsInOrder(cart)
    OS->>DB: process all items
    OS->>OS: resolveShippingPrice(governorate_id)
    OS-->>U: order created

    Note over U,DB: Fast Shipping Checkout
    U->>FC: POST /fast-shipping/checkout
    FC->>FSS: processFastShippingCheckout(cart)
    FSS->>DB: filter cart_items WHERE shipping_method='FAST'
    FSS->>DB: lockForUpdate() on filtered items
    FSS->>FSS: get fastShippingFee from repository
    FSS->>FSS: NOTE: does NOT check FREE_SHIPPING coupons (BUG P-2)
    FSS->>OS: addItemsInOrder with fast fee
    OS->>OS: totalPrice = finalTotal + shipping + fastShippingFee
    OS-->>FC: order created
    FC-->>U: { order, fast_shipping_fee }
```
