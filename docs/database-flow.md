# Database Flow: Every Table Touched

> **Source code verified** — All 40+ migrations, all models, all services

---

## 1. Table Inventory

### Core Transactional Tables

| Table | Purpose | Insert | Update | Delete | Soft Delete |
|-------|---------|--------|--------|--------|-------------|
| `carts` | Active shopping carts | Checkout | Coupon, price | Expire | ❌ |
| `cart_items` | Cart line items | Add item | Qty, price | Remove item | ❌ |
| `orders` | Orders | Checkout | Status, payment | — | ❌ (has soft deletes) |
| `order_products` | Order line items | Checkout | — | — | ❌ |
| `transactions` | Payment transactions | Checkout, callback | Status, error | — | ❌ |

### Financial Tables

| Table | Purpose | Insert | Update | Delete |
|-------|---------|--------|--------|--------|
| `invoices` | Invoice records | Payment success | Status, PDF tracking | ❌ |
| `invoice_sequences` | Invoice number generation | First invoice per year | `last_sequence++` | ❌ |
| `invoice_timeline` | Invoice audit trail | Each event | ❌ (append-only) | ❌ |
| `credit_notes` | Credit note documents | Refund/cancellation | ❌ | ❌ |
| `debit_notes` | Debit note documents | Adjustment | ❌ | ❌ |
| `shipments` | Shipment tracking | Order fulfillment | Status, tracking | ❌ |

### Coupon/Promotion Tables

| Table | Purpose | Insert | Update | Delete |
|-------|---------|--------|--------|--------|
| `coupons` | Coupon definitions | Admin create | Admin update | Admin delete |
| `coupon_usages` | Public coupon consumption | Payment success | — | ❌ |
| `coupon_assignments` | Per-user coupon quotas | Admin assign | `used++`, expiry | Admin (if unused) |
| `coupon_assignment_usages` | Assignment audit trail | Payment success | — | ❌ |
| `promotions` | Promotion definitions | Admin create | Admin update | Admin delete |
| `promotion_product` | Promotion-product linkage | Admin create | Admin sync | Admin sync |
| `promotion_gift_products` | Gift items per promotion | Admin create | Admin sync | Admin sync |

### Inventory Tables

| Table | Purpose | Key Columns |
|-------|---------|-------------|
| `products` | Products | `stock_quantity`, `reserved_quantity`, `sold_quantity`, `in_stock` |
| `product_variants` | Variants | `stock_quantity`, `reserved_quantity`, `sold_quantity`, `in_stock` |

### Supporting Tables

| Table | Purpose | Notes |
|-------|---------|-------|
| `users` | Customers and admins | Relates to orders, invoices, carts |
| `governorates` | Shipping regions | Relates to orders |
| `shipping_prices` | Price per governorate | Used for shipping calculation |
| `pickup_locations` | Store pickup addresses | Snapshot copied to order |
| `settings` | Global settings | `minimum_order_amount` checked at checkout |
| `activity_log` | Audit trail | All order/payment events logged |
| `notifications` | Notification records | Admin notifications |
| `jobs` | Queue | Invoice PDF generation |
| `failed_jobs` | Failed queue jobs | PDF failure tracking |

---

## 2. Transactional Flow Per Operation

### 2.1 Add Item to Cart

**Tables touched**: `carts`, `cart_items`, `products`/`product_variants`

**Locking**: 
- `products`: SELECT ... FOR UPDATE (row lock on product row)
- `product_variants`: SELECT ... FOR UPDATE (if variant specified)

**Sequence**:
```
BEGIN TX
  SELECT * FROM products WHERE id = X FOR UPDATE
  IF stock_quantity - reserved_quantity < quantity → THROW
  UPDATE products SET reserved_quantity = reserved_quantity + quantity
  INSERT INTO cart_items (...) OR UPDATE existing
  UPDATE carts SET total_price = ..., updated_at = NOW()
  REVALIDATE promotion → may clear discount fields
COMMIT
```

### 2.2 Checkout (Order Creation)

**Tables touched**: `carts`, `cart_items`, `orders`, `order_products`, `transactions`, `coupons`, `settings`, `governorates`, `shipping_prices`

**Locking**:
- `carts`: SELECT ... FOR UPDATE
- `coupons`: SELECT ... FOR UPDATE (row lock on coupon row)
- Order row: No lock (new insert)

**Sequence** (`OrderService@addItemsInOrder`):
```
BEGIN TX
  SELECT * FROM carts WHERE user_id = X AND status = 'active' FOR UPDATE
  LOAD cart.items + products + flash_sales + variants
  
  FOREACH cart_item:
    Recalculate price via ProductPricingService
    IF price changed → UPDATE cart_items SET price = ..., total_price = ...
  
  IF cart.coupon:
    SELECT * FROM coupons WHERE code = X FOR UPDATE
    CouponOrchestrator::validate()
    IF invalid → UPDATE carts SET coupon = NULL
  
  calculateCheckoutTotals() → applies promotion + coupon discounts
  
  CHECK settings.minimum_order_amount ≤ subtotal
  
  resolveShippingPrice() → governorates + shipping_prices
  
  INSERT INTO orders (user_id, status='pending', price, total_price, 
                      shipping_price, coupon_discount, promotion_discount, 
                      coupon, promotion_id, payment_method, ...)
  
  FOREACH cart_item:
    INSERT INTO order_products (order_id, product_id, quantity, prices, ...)
  
  OrderCreated event dispatched
  
COMMIT
```

### 2.3 Payment Callback (Online)

**Tables touched**: `transactions`, `orders`, `coupons`, `coupon_assignments`, `coupon_assignment_usages`, `products`, `product_variants`, `cart_items`, `carts`, `promotions`, `invoices`, `invoice_sequences`, `invoice_timeline`

**Locking chain** (`OrderController@checkoutCallback`):
```
TRANSACTION 1 (payment processing):
  SELECT * FROM transactions WHERE gateway_transaction_id = X FOR UPDATE
  SELECT * FROM orders WHERE id = Y FOR UPDATE
  IF order.status != 'pending' → RETURN (idempotency guard)
  UPDATE transactions SET status = 'paid', paid_at = NOW()
  UPDATE orders SET payment_status = 'SUCCESS', paid_at = NOW()
  
  CartInventoryService::finalizeItemsByShippingMethod():
    FOREACH cart_item:
      UPDATE products SET reserved_quantity -= qty, stock_quantity -= qty, sold_quantity += qty
      (or variant equivalent)
      DELETE cart_item (or release)
  
  OrderService::finalizePromotionUsageAfterPayment():
    UPDATE promotions SET usage = usage + 1 WHERE id = X
  
  OrderService::changeOrderStatus(invoiceId, 'completed'):
    UPDATE orders SET status = 'completed', completed_at = NOW(), 
                      payment_status = 'SUCCESS', fulfillment_status = 'PROCESSING'
    
    recordCouponUsage():
      IF assigned coupon:
        SELECT * FROM coupon_assignments WHERE coupon_id=X AND user_id=Y FOR UPDATE
        SELECT * FROM coupon_assignment_usages WHERE assignment_id=Z AND order_id=W FOR UPDATE
        UPDATE coupons SET used = used + 1
        UPDATE coupon_assignments SET used = used + 1
        INSERT INTO coupon_assignment_usages (...)
      IF public coupon:
        INSERT INTO coupon_usages (...) (firstOrCreate with unique constraint)
        UPDATE coupons SET used = used + 1 (only if wasRecentlyCreated)
      
      UPDATE orders SET coupon_consumed = 1
    
    event(new PaymentSucceeded → QUEUED)
    
COMMIT

QUEUED: GenerateInvoiceListener (high queue, 5 retries):
  TRANSACTION 2 (invoice generation):
    SELECT * FROM invoices WHERE order_id = X FOR UPDATE (idempotency)
    IF exists → RETURN existing
    
    SELECT * FROM invoice_sequences WHERE series='INV' AND year=2026 FOR UPDATE
    UPDATE invoice_sequences SET last_sequence = last_sequence + 1
    
    INSERT INTO invoices (order_id, user_id, invoice_number, status='generated', 
                          data=snapshot, snapshot_hash, verification_hash, ...)
    INSERT INTO invoice_timeline (invoice_id, event='generated', ...)
    
    AFTER COMMIT: InvoiceCreated event → LogInvoiceCreated (logs)
    AFTER COMMIT: GenerateInvoicePdfJob (low queue, 3 retries)
  COMMIT
```

### 2.4 COD/Cashier Mark Paid

**Tables touched**: `transactions`, `orders`, `coupons`, `coupon_assignments`, `coupon_assignment_usages`, `promotions`, `products`, `product_variants`, `carts`, `cart_items`

**Locking** (`OrderService@markCodAsPaid` / `markCashierPaid`):
```
BEGIN TX
  SELECT * FROM transactions WHERE order_id=X AND payment_method='cod' AND status='pending' FOR UPDATE
  UPDATE transactions SET status='paid', paid_at=NOW()
  
  SELECT * FROM orders WHERE id=Y FOR UPDATE
  UPDATE orders SET status='completed', payment_status='SUCCESS', 
                    completed_at=NOW(), fulfillment_status='PROCESSING'
  
  recordCouponUsage()
  finalizePromotionUsageAfterPayment()
  finalizeInventoryAfterPayment()
  
  event(new PaymentSucceeded → synchronous here)
COMMIT
```

### 2.5 Cancel Unpaid Orders (Cron)

**Tables touched**: `orders`, `transactions`, `carts`, `cart_items`, `products`, `product_variants`

**Flow**:
```
BEGIN TX (per order):
  UPDATE orders SET status='cancelled', cancelled_at=NOW()
  UPDATE transactions SET status='failed'
  
  expireSingleCart(user_id):
    LOAD cart items
    FOREACH item:
      UPDATE products SET reserved_quantity -= qty
    DELETE cart_items
    DELETE cart
COMMIT
```

---

## 3. Unique Constraints

| Table | Constraint | Purpose |
|-------|-----------|---------|
| `invoices` | `uuid` UNIQUE | Public identifier |
| `invoices` | `order_id` UNIQUE | One invoice per order |
| `invoices` | `invoice_number` UNIQUE | Sequential numbering |
| `invoice_sequences` | PRIMARY KEY (`series`, `sequence_year`) | Per-series annual sequence |
| `credit_notes` | `credit_note_number` UNIQUE | Sequential numbering |
| `debit_notes` | `debit_note_number` UNIQUE | Sequential numbering |
| `shipments` | `uuid` UNIQUE | Public identifier |
| `coupons` | `code` UNIQUE | Coupon code |
| `coupon_usages` | UNIQUE (`coupon_id`, `user_id`) | One usage per user per public coupon |
| `coupon_assignments` | UNIQUE (`coupon_id`, `user_id`) | One assignment per user per coupon |
| `coupon_assignment_usages` | UNIQUE (`coupon_assignment_id`, `order_id`) | One usage per order |
| `promotions` | `code` UNIQUE | Promotion code |

---

## 4. Indexes

| Table | Index | Type | Purpose |
|-------|-------|------|---------|
| `invoices` | `user_id` | BTREE | Filter by user |
| `invoices` | `status` | BTREE | Filter by status |
| `invoice_timeline` | `invoice_id` | BTREE | Load timeline for invoice |
| `invoice_timeline` | `event` | BTREE | Filter by event type |
| `invoice_timeline` | `(actor_type, actor_id)` | BTREE | Find events by actor |
| `credit_notes` | `invoice_id` | BTREE | Load notes for invoice |
| `debit_notes` | `invoice_id` | BTREE | Load notes for invoice |
| `shipments` | `order_id` | BTREE | Load shipments for order |
| `shipments` | `status` | BTREE | Filter by status |
| `shipments` | `tracking_number` | BTREE | Lookup by tracking |
| `coupon_assignment_usages` | `coupon_assignment_id` | BTREE | Load usages for assignment |
| `coupon_assignment_usages` | `created_at` | BTREE | Time-based queries |
| `coupon_assignment_usages` | `(coupon_assignment_id, created_at)` | COMPOSITE | Combined time queries |
| `promotions` | `(status, start_at, end_at)` | COMPOSITE | Validity check |
| `promotions` | `(usage, limiter)` | COMPOSITE | Availability check |

---

## 5. Foreign Keys

| Child Table | Parent Table | On Delete |
|-------------|-------------|-----------|
| `invoices.order_id` | `orders.id` | RESTRICT |
| `invoices.transaction_id` | `transactions.id` | SET NULL |
| `invoices.user_id` | `users.id` | RESTRICT |
| `invoice_timeline.invoice_id` | `invoices.id` | CASCADE |
| `credit_notes.invoice_id` | `invoices.id` | CASCADE |
| `debit_notes.invoice_id` | `invoices.id` | CASCADE |
| `shipments.order_id` | `orders.id` | CASCADE |
| `coupon_usages.coupon_id` | `coupons.id` | CASCADE |
| `coupon_usages.user_id` | `users.id` | NULL ON DELETE |
| `coupon_assignments.coupon_id` | `coupons.id` | CASCADE |
| `coupon_assignment_usages.coupon_assignment_id` | `coupon_assignments.id` | CASCADE |
| `transactions.order_id` | `orders.id` | CASCADE |
| `order_products.order_id` | `orders.id` | CASCADE |

---

## 6. Locking Summary

| Operation | Locks | Transaction |
|-----------|-------|-------------|
| Add to cart | Product row (FOR UPDATE) | Implicit |
| Checkout | Cart row (FOR UPDATE), Coupon row (FOR UPDATE) | Explicit |
| Payment callback | Transaction row (FOR UPDATE), Order row (FOR UPDATE) | Explicit |
| Invoice generation | Invoice table (FOR UPDATE for idempotency), Invoice sequence (FOR UPDATE) | Explicit |
| Coupon usage | Assignment row (FOR UPDATE), Usage table (FOR UPDATE) | Explicit |
| COD mark paid | Transaction row (FOR UPDATE), Order row (FOR UPDATE) | Explicit |
| Inventory restore | Product/variant rows (FOR UPDATE), Order row (FOR UPDATE with null guard) | Explicit |
| Cancel unpaid | Per-order | Per-order transaction |
