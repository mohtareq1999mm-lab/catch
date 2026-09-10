# Phase 12: Customer Experience — Production Operations Manual

> **Purpose:** Trace every touchpoint a customer encounters from browsing to order completion, invoice verification, and support escalation.

---

## 12.1 Browse Products, Categories & Brands

### Public API Endpoints (No Authentication Required)

| Endpoint | Method | Controller |
|---|---|---|
| `/api/v1/general/products` | GET | `ProductController::index` |
| `/api/v1/general/products/{slug}` | GET | `ProductController::getProductBySlug` |
| `/api/v1/general/categories` | GET | `CategoryController::index` |
| `/api/v1/general/categories/{slug}` | GET | `CategoryController::getCategoryBySlug` |
| `/api/v1/general/brands` | GET | `BrandController::index` |
| `/api/v1/general/brands/{slug}` | GET | `BrandController::getBrandBySlug` |
| `/api/v1/general/brands-products` | GET | `BrandController::getBrandsProductsByQtySet` |
| `/api/v1/general/banners` | GET | `BannerController::index` |
| `/api/v1/general/sliders` | GET | `SliderController::index` |
| `/api/v1/general/flash-sales` | GET | `FlashSaleController::index` |
| `/api/v1/general/search` | GET | `SearchController::index` |
| `/api/v1/general/tags` | GET | `TagController::index` |

### Source Code References

- **Routes:** `routes/api.php:36-110` — All public endpoints under `prefix('v1/general')`
- **Product Filtering:** `app/Services/General/ProductFilter.php`
- **Search:** `app/Services/General/SearchService.php`
- **Category Hierarchy:** `app/Services/General/CategoryHierarchyService.php`
- **Brand Filtering:** `app/Services/General/BrandService.php`

### What the Customer Sees

- Product listings with pagination, sorting, filtering by category/brand/price/tag
- Product detail with name, price, images, attributes, flash sale pricing
- Category tree with child categories
- Brand list with product counts
- Banners and sliders on homepage
- Flash sale banners with countdown timers
- Search results with full-text search

### Database Tables Read

- `products`, `product_variants`, `categories`, `category_product`
- `brands`, `banners`, `sliders`, `flash_sales`
- `tags`, `taggables`
- `media` (for product images)

---

## 12.2 Add to Cart

### Endpoint

| Endpoint | Method | Auth | Controller |
|---|---|---|---|
| (frontend-managed, calls internal reservation) | — | Sanctum | — |

### Flow

```
Customer clicks "Add to Cart"
  → Frontend calls CartInventoryService::reserveItem()
  → DB transaction: lock cart row + inventory row
  → Check available stock = stock_quantity - reserved_quantity
  → If insufficient stock → throw QUANTITY_EXCEEDS_STOCK
  → Reserve stock: increment reserved_quantity on product/variant
  → Create or update cart_item row
  → Touch cart reservation (sets expires_at = now + 3 days)
  → Return updated cart item with price
  → Frontend shows confirmation toast + cart badge updates
```

### Source Code References

- **Service:** `app/Services/General/CartInventoryService.php:22-78` (`reserveItem`)
- **Reservation lock:** `CartInventoryService::lockInventoryRow()` at line 437 — `lockForUpdate()` on product or variant
- **Stock check:** `CartInventoryService::reserveStock()` at line 455 — checks `getAvailableStock()` = `stock_quantity - reserved_quantity`
- **Cart TTL:** `CartInventoryService::CART_TTL_DAYS = 3` at line 20 — cart expires after 3 days of inactivity, releasing all reserved stock

### Database Tables Modified

- `carts` — `reserved_at`, `expires_at`, `total_price` updated
- `cart_items` — created or updated with `reserved_quantity`, `price`, `total_price`
- `products` — `reserved_quantity`, `in_stock` updated
- `product_variants` — `reserved_quantity`, `in_stock` updated

### What the Customer Sees

- Item appears in cart with quantity, unit price, total price
- Cart badge counter increments
- Toast notification: "Item added to cart"

### Concurrency Protection

- `Cart::lockForUpdate()` at line 25 — prevents concurrent cart modifications
- `Product::lockForUpdate()` or `ProductVariant::lockForUpdate()` at line 437 — prevents overselling
- Full `DB::transaction()` wraps the entire reserve operation

### Failure Recovery

- **Insufficient stock:** Exception thrown, transaction rolled back, customer sees "Quantity exceeds available stock"
- **Concurrent request:** Second request waits for lock, then re-evaluates stock
- **Server crash mid-operation:** Transaction rolled back, no stock leaked

---

## 12.3 View Cart

### Endpoint

| Endpoint | Method | Auth |
|---|---|---|
| (frontend-managed, reads active cart) | — | Sanctum |

### Flow

```
Customer opens cart page
  → Frontend fetches cart via CartInventoryService::getActiveCartForUser()
  → Loads cart with items, products, variants, flash sales, attributes
  → Calculates subtotal from items
  → Frontend displays items, quantities, prices, totals
```

### Source Code References

- **Service:** `app/Services/General/CartInventoryService.php:370-380` (`getActiveCartForUser`)
- Eager loads: `items.product.flash_sales`, `items.productVariant.attributeProducts.attributeValue.attribute`

### What the Customer Sees

- List of cart items with product name, image, variant attributes
- Quantity selector with +/- buttons
- Unit price, line total
- Subtotal (sum of all item total_prices)
- Empty cart message if no items
- Coupon input field (if not yet applied)
- Promotion suggestions (eligible promotions)
- "Proceed to Checkout" button

### Database Tables Read

- `carts` (where `user_id` = current user, `status` = 'active')
- `cart_items` (eager loaded)
- `products`, `product_variants` (eager loaded)
- `flash_sales` (eager loaded for pricing)
- `attribute_products`, `attribute_values`, `attributes` (for variant display)

---

## 12.4 Apply Coupon

### Endpoint

| Endpoint | Method | Auth | Controller |
|---|---|---|---|
| `/api/v1/general/coupons/apply` | POST | Sanctum | `CouponController::applyCoupon` |

### Flow

```
Customer enters coupon code
  → POST /coupons/apply { code: "SUMMER20" }
  → CouponService validates:
      1. Coupon exists and is active
      2. Not expired (valid_from <= now <= valid_to)
      3. Usage quota not exhausted (used < max_uses)
      4. Minimum cart amount met
      5. User eligibility (assigned coupons)
  → If invalid → return error with reason
  → If valid → store coupon code on cart.coupon
  → Cart total recalculated with discount
  → Return updated cart with discount applied
```

### Source Code References

- **Controller:** `app/Http/Controllers/Api/General/CouponController.php`
- **Service:** `app/Services/General/CouponService.php` — orchestrates validation and application
- **Validator:** `app/Services/Coupon/CouponValidator.php` — individual validation rules
- **Calculator:** `app/Services/Coupon/CouponCalculator.php` — discount amount calculation
- **Assignment Checker:** `app/Services/Coupon/CouponAssignmentValidator.php`
- **Orchestrator:** `app/Services/Coupon/CouponOrchestrator.php` — validates by code against user + items
- **Validation (at checkout):** `OrderService::addItemsInOrder()` at line 169-181 — re-validates coupon, clears if invalid

### Discount Types (Enum: `DiscountType`)

| Type | Behavior |
|---|---|
| `percentage` | Deducts `coupon.amount`% off the total |
| `fixed` | Deducts fixed `coupon.amount` from total |
| `free_shipping` | Sets shipping cost to 0 |

### Coupon Policies

| Policy | Rule |
|---|---|
| **Consumable** | Coupon quota is consumed on payment success, NEVER reversed on cancellation |
| **One per user** | `CouponUsage` uses `firstOrCreate` with unique `(coupon_id, user_id)` |
| **Assigned coupons** | Tracked via `coupon_assignments` with per-user `max_uses` |
| **Not stackable** | Only one coupon per cart (stored on `cart.coupon`) |

### What the Customer Sees

- **Success:** Discount amount shown in cart summary, total updated, green confirmation
- **Error:** Red error message with reason (expired, exhausted, minimum not met, invalid code)

### Database Tables Modified

- `carts.coupon` — set to coupon code
- `carts.total_price` — recalculated (at checkout)

---

## 12.5 See Eligible Promotions

### Endpoint

| Endpoint | Method | Auth | Controller |
|---|---|---|---|
| `/api/v1/general/checkout/promotions` | GET | Sanctum | `OrderController::eligiblePromotions` |

### Flow

```
Customer views promotions page
  → Frontend calls GET /checkout/promotions
  → OrderService::eligiblePromotionsForUser()
  → Loads active cart with items
  → PromotionService::eligiblePromotionsPayload()
  → Evaluates all active promotions against cart items
  → Returns eligible promotions with discount details
```

### Source Code References

- **Controller:** `app/Http/Controllers/Api/General/OrderController.php:56-65`
- **Service:** `app/Services/General/OrderService.php:261-269`
- **Promotion Engine:** `app/Services/General/PromotionEngine/`
  - `PromotionEligibilityResolver.php` — determines if cart qualifies
  - `PromotionEvaluation.php` — calculates discount/gift outcomes
  - `PromotionApplicator.php` — applies best strategy

### Promotion Strategy Types

| Strategy | File | Behavior |
|---|---|---|
| `PercentagePromotionStrategy` | `Strategies/PercentagePromotionStrategy.php` | % off entire order |
| `FixedPromotionStrategy` | `Strategies/FixedPromotionStrategy.php` | Fixed amount off |
| `GiftPromotionStrategy` | `Strategies/GiftPromotionStrategy.php` | Free gift item |

### What the Customer Sees

- List of promotions for which the cart is eligible
- Each promotion shows: name, description, discount amount/type
- Gift promotions show the free gift product
- "Apply" button next to each eligible promotion
- Promotions that are NOT eligible are hidden (not shown with "not applicable" — only eligible ones)

### Database Tables Read

- `promotions` — active promotions
- `cart_items` — for eligibility evaluation
- `products` — for gift promotion product availability

---

## 12.6 Select Promotion

### Flow

```
Customer selects a promotion
  → Frontend updates cart_items:
       - Sets promotion_id on applicable items
       - Marks gift items with is_gift = true
       - Recalculates item total_price after discount
  → Promotion selection is NOT persisted to a separate table
  → It is stored directly on cart_items as state
  → At checkout, the promotion_id on cart_items is read
```

### Source Code Reference

- Promotion is stored as `cart_item.promotion_id` and `cart_item.is_gift`
- At checkout, `OrderService::calculateCheckoutTotals()` re-evaluates the selected promotion

### Concurrency

- Promotion is re-validated at checkout (inside `addItemsInOrder()`)
- If promotion is no longer valid (exhausted, expired) → silently removed
- Promotion usage is NOT recorded until payment succeeds (`finalizePromotionUsageAfterPayment()`)

---

## 12.7 Proceed to Checkout

### Endpoint

| Endpoint | Method | Auth | Controller |
|---|---|---|---|
| `/api/v1/general/checkout` | POST | Sanctum | `OrderController::checkout` |

### Flow

```
Customer fills checkout form (name, phone, address, governorate, shipping method, payment method)
  → POST /checkout with all data
  → 1. Validate request (OrderCreateRequest)
  → 2. Get active cart (CartInventoryService::getActiveCartForUser)
  → 3. ensureCartReservation → re-syncs all stock reservations
  → 4. Validate payment method:
       - COD not available for pickup fulfillment
  → 5. OrderService::addItemsInOrder (see below)
  → 6. Payment handler based on method:
       - Online: redirect to gateway
       - COD: order confirmation
       - Pay at Cashier: QR code
  → 7. Return response to frontend
```

### Source Code References

- **Controller:** `app/Http/Controllers/Api/General/OrderController.php:67-124`
- **Request:** `Marvel\Http\Requests\OrderCreateRequest`
- **Reservation:** `CartInventoryService::ensureCartReservation()` at `CartInventoryService.php:354-368`

### OrderService::addItemsInOrder — Detailed Breakdown

```
Source: app/Services/General/OrderService.php:48-243
1. Lock cart with lockForUpdate() (line 153-158)
2. Refresh cart item prices (line 165)
3. Lock + re-validate coupon (line 168-181)
   - Coupon::where('code')→lockForUpdate()
   - CouponOrchestrator::validate()
   - If invalid → clear coupon from cart
4. Determine selected promotion from cart items (line 184-190)
5. Calculate checkout totals (line 192-197)
6. Check minimum order amount (line 199-205)
7. Resolve shipping price from governorate (line 212-217)
8. Create order via OrderCreationService::createOrder() (line 219-221)
9. Create order items via OrderCreationService::createOrderItems() (line 226-229)
10. Dispatch OrderCreated event (line 230)
11. DB::commit()
12. Return order with relations loaded
```

### OrderCreationService::createOrder

```
Source: app/Services/Checkout/OrderCreationService.php:27-82
- Creates order with: user info, shipping info, payment info, pricing breakdown
- Snapshots pickup location data at time of order
- Sets status = 'pending', payment_status = 'payment-pending'
```

### OrderCreationService::createOrderItems

```
Source: app/Services/Checkout/OrderCreationService.php:126-182
- Creates order_product row per cart item
- Snapshots product_name, price, quantity, SKU at time of order
- Records flash sale price, discount price, promotion discount
- Marks gift items with is_gift = true
```

### What the Customer Sees — Before Submit

- Order summary: items, quantities, prices
- Subtotal, promotion discount, coupon discount, shipping fee
- Grand total
- Shipping address form (name, phone, address, governorate)
- Payment method selector (online/COD/pay at cashier)
- Pickup location selector (if fulfillment_type = pickup)

### What the Customer Sees — After Submit

- **Online payment:** Loading spinner, then redirect to MyFatoorah payment page
- **COD:** Order confirmation with order number, "Thank you for your order"
- **Pay at Cashier:** QR code displayed on screen + order number

### Database Tables Created/Modified

- `orders` — INSERT with status='pending'
- `order_products` (order_items) — INSERT for each cart item
- `transactions` — INSERT with status='pending' (in PaymentCheckoutHandler)
- `carts` — status unchanged until payment callback
- `cart_items` — reservation still active

---

## 12.8 Payment — Online (MyFatoorah)

### Endpoint (Internal)

| Endpoint | Method | Controller |
|---|---|---|
| (redirect from checkout) | — | `PaymentCheckoutHandler::handleOnlinePayment` |

### Flow

```
1. PaymentCheckoutHandler::handleOnlinePayment()
2. PaymentGatewayFactory::make('myfatoorah') → MyFatoorahGateway
3. Gateway creates invoice via MyFatoorah API
4. Transaction created with status='pending'
5. Return redirect URL to frontend
6. Frontend redirects customer to MyFatoorah
7. Customer enters card details on MyFatoorah page
8. MyFatoorah calls callback URL
```

### Source Code References

- **Handler:** `app/Services/Payment/PaymentCheckoutHandler.php:25-75`
- **Gateway:** `app/Services/Gateway/MyFatoorahGateway.php`
- **Contract:** `app/Services/Payment/Contracts/PaymentGatewayContract.php`
- **Factory:** `app/Services/Payment/PaymentGatewayFactory.php`
- **Callback URL:** `route('api.checkout.callback')`

### What the Customer Sees

- Redirected to MyFatoorah-hosted payment page
- Enters card details on MyFatoorah's secure page
- After payment, redirected back to store

### Transaction Record

```sql
INSERT INTO transactions (
  order_id, user_id, invoice_id, payment_method,
  status, amount, currency, gateway_transaction_id, gateway_response
) VALUES (
  :orderId, :userId, :gatewayTransactionId, 'myfatoorah',
  'pending', :amount, 'EGP', :gatewayTransactionId, :rawResponse
);
```

---

## 12.9 Payment — COD (Cash on Delivery)

### Endpoint (Internal)

| Method | Controller |
|---|---|
| — | `PaymentCheckoutHandler::handleCodPayment` |

### Flow

```
1. PaymentCheckoutHandler::handleCodPayment()
2. Transaction created with payment_method='cod', status='pending'
3. Order remains status='pending' until admin marks paid
4. Return order confirmation with order_id
```

### Source Code References

- **Handler:** `app/Services/Payment/PaymentCheckoutHandler.php:77-95`

### What the Customer Sees

- Order confirmation page with order number
- Message: "Your order has been placed. Pay upon delivery."
- Order number displayed prominently

### Marking COD as Paid (Admin)

```php
// app/Services/General/OrderService.php:604-646
// POST /checkout/cod/{orderId}/mark-paid
// Permission required: update-order-status
// Sets transaction status = 'paid'
// Sets order status = 'completed'
// Records coupon usage
// Finalizes promotion usage
// Finalizes inventory (deducts stock)
// Dispatches PaymentSucceeded event
```

---

## 12.10 Payment — Pay at Cashier

### Endpoint (Internal)

| Method | Controller |
|---|---|
| — | `PaymentCheckoutHandler::handleCashierQrPayment` |

### Flow

```
1. PaymentCheckoutHandler::handleCashierQrPayment()
2. Transaction created with payment_method='pay_at_cashier', status='pending'
3. CashierQrService generates QR code (SVG)
4. QR encodes: { "transaction": "<transaction_uuid>" }
5. Return QR code as base64 data URI + order_id + transaction_uuid
```

### Source Code References

- **Handler:** `app/Services/Payment/PaymentCheckoutHandler.php:97-119`
- **QR Service:** `app/Services/Gateway/CashierQrService.php`
  - `generateSvg()` — returns raw SVG string
  - `generateBase64DataUri()` — returns `data:image/svg+xml;base64,...`
- **Regenerate QR:** `OrderController::getTransactionQr()` at line 152 — returns SVG response

### What the Customer Sees

- QR code displayed on screen
- Order number
- Transaction UUID
- Instructions: "Show this QR code at the cashier to pay"

### Customer-Facing QR Regeneration

```
GET /checkout/transaction-qr/{uuid}
→ Sanctum auth required
→ Owner check: transaction.order.user_id === current user
→ Returns fresh SVG QR code
```

### Marking Cashier as Paid (Admin)

```
POST /checkout/cashier/{orderId}/mark-paid
→ Permission required: update-order-status
→ Same flow as COD mark-paid
```

---

## 12.11 Payment Callback — Success

### Endpoint

| Endpoint | Method | Controller |
|---|---|---|
| `/api/v1/general/checkout/callback` | GET/POST | `OrderController::checkoutCallback` |

### Flow

```
MyFatoorah redirects to callback URL with paymentId
  → OrderController::checkoutCallback()
  → 1. Extract paymentId from query/body
  → 2. Find Transaction by gateway_transaction_id
  → 3. Gateway::verifyPayment(paymentId)
  → 4. AMOUNT CHECK: |gateway.amount - order.total_price| > 0.01 → MISMATCH
  → 5. CURRENCY CHECK: gateway.currency !== config('payment.default_currency') → MISMATCH
  → 6. If mismatch → PaymentFailed event → redirect to /payment/failed
  → 7. DB TRANSACTION:
       - Lock Transaction (lockForUpdate)
       - Lock Order (lockForUpdate)
       - If order.status !== 'pending' → return (already processed)
       - Update Transaction: status='paid', paid_at=now()
       - Update Order: payment_status='success', paid_at=now()
       - CartInventoryService::finalizeItemsByShippingMethod() — deduct stock
       - OrderService::finalizePromotionUsageAfterPayment() — increment promotion usage
       - OrderService::changeOrderStatus('completed')
       - DB::commit()
  → 8. Dispatch PaymentSucceeded event
  → 9. Redirect to /payment/success?order_id=X&payment_id=Y
```

### Concurrency Protection

- **Idempotency guard:** `if ($lockedOrder->status !== 'pending') { return; }` at line 311 — prevents double-processing
- **Row locks:** Transaction and Order both locked with `lockForUpdate()` inside the DB transaction
- **Database isolation:** `REPEATABLE READ` / `SERIALIZABLE` ensures no phantom reads

### What the Customer Sees

- **Web:** Redirected to `{frontend_url}/{locale}/payment/success?status=success&message=...&payment_id=X&order_id=Y`
- **Mobile:** JSON response with `{ status: 'success', message: '...', payment_id, order_id }`

### Source Code References

- **Controller:** `app/Http/Controllers/Api/General/OrderController.php:171-374`
- **Amount check:** line 248-256
- **Currency check:** line 258-265
- **DB transaction:** line 288-348
- **PaymentSucceeded dispatch:** line 352-356
- **Redirect:** line 367-372

---

## 12.12 Payment Callback — Failure

### Endpoint

| Endpoint | Method | Controller |
|---|---|---|
| `/api/v1/general/checkout/error-callback` | GET/POST | `OrderController::checkoutErrorCallback` |

### Flow

```
MyFatoorah redirects to error callback
  → OrderController::checkoutErrorCallback()
  → 1. Extract paymentId
  → 2. Find Transaction
  → 3. Gateway::verifyPayment(paymentId)
  → 4. DB TRANSACTION: lock transaction, update status='failed'
  → 5. Dispatch PaymentFailed event
  → 6. Redirect to /payment/failed?status=failed&error=...
```

### What the Customer Sees

- **Web:** Redirected to `{frontend_url}/{locale}/payment/failed?status=failed&error=...&payment_id=X`
- **Mobile:** JSON response with `{ status: 'failed', error: '...' }`

### Source Code References

- **Controller:** `app/Http/Controllers/Api/General/OrderController.php:376-459`

---

## 12.13 View Orders

### Endpoint

| Endpoint | Method | Auth | Controller |
|---|---|---|---|
| `/api/v1/general/orders` | GET | Sanctum | `OrderController::index` |

### Flow

```
Customer navigates to "My Orders"
  → GET /orders
  → OrderService::paginateForUser()
  → Filter by current user
  → Optional status filter: ?status=completed
  → Paginated results with eager loaded relations
  → Return OrderCollection resource
```

### Source Code References

- **Controller:** `app/Http/Controllers/Api/General/OrderController.php:44-54`
- **Service:** `app/Services/General/OrderService.php:56-77`

### Eager Loaded Relations

```php
// OrderService::orderListRelations()
[
  'orderItems.product' => fn($q) => $q->withAvg('reviews', 'rating'),
  'orderItems.product.media',
  'orderItems.productVariant.attributeProducts.attributeValue',
  'transactions',
  'pickupLocation',
]
```

### What the Customer Sees

- Order list: order number, date, status, total price, item count
- Each order links to detail page
- Empty state if no orders
- Status badges with colors

### Database Tables Read

- `orders` (filtered by user_id)
- `order_products` (order_items)
- `products`, `product_variants`
- `transactions`
- `pickup_locations`

---

## 12.14 View Order Detail

### Endpoint

| Endpoint | Method | Auth |
|---|---|---|
| (frontend reads from order list or individual fetch) | — | Sanctum |

### What the Customer Sees

- Order number and date
- Status timeline (pending → processing → completed/delivered)
- Items list: product name, quantity, unit price, total, variant attributes
- Price breakdown: subtotal, promotion discount, coupon discount, shipping fee, grand total
- Payment method and gateway
- Transaction status (paid/pending/failed)
- Shipping address: name, phone, address, governorate
- Pickup location (if applicable): name, address, phone
- Expected delivery date (for fast shipping)
- Invoice link (if invoice generated)
- Tracking information (if shipment created)

### Database Tables Read

- `orders`
- `order_products`
- `transactions`
- `invoices`
- `shipments`
- `governorates`

---

## 12.15 Receive & View Invoice

### Endpoint

| Endpoint | Method | Auth | Controller |
|---|---|---|---|
| `/api/v1/general/invoices/my-invoices` | GET | Sanctum | `InvoiceController::myInvoices` |

### Flow

```
Invoice is generated automatically after PaymentSucceeded event
  → GenerateInvoiceListener (queue:high) handles the event
  → InvoiceService::generateFromOrder() creates the invoice
  → Invoice appears in "My Invoices" section
  → Customer can view invoice details
```

### Source Code References

- **Listener:** `app/Listeners/GenerateInvoiceListener.php`
- **Service:** `app/Services/Invoice/InvoiceService.php:22-92`
- **Controller:** `app/Http/Controllers/Api/InvoiceController.php:97-113`

### Invoice Generation Detail (`generateFromOrder`)

```
1. Check for existing invoice (lockForUpdate) — idempotent
2. Build full snapshot from order data (InvoiceSnapshotService)
3. Validate snapshot structure
4. Compute snapshot hash (SHA-256 of sorted JSON)
5. Compute verification hash (SHA-256 of snapshot_hash + app_key)
6. Generate next invoice number (InvoiceNumberService)
7. Create Invoice record with status='generated'
8. Record timeline entry (generated)
9. Dispatch InvoiceCreated event (synchronous listener logs it)
10. Dispatch GenerateInvoicePdfJob (queue:low)
```

### Invoice Number Generation (`InvoiceNumberService`)

- Uses `invoice_sequences` table with `series`, `sequence_year`, `last_sequence`
- Auto-increments sequence within series+year
- Format: `{series}-{year}-{sequence:06d}`

### What the Customer Sees

- Invoice number, date, series
- Seller/buyer information
- Itemized list of products with quantities, prices, totals
- Discounts breakdown (coupon, promotion)
- Shipping cost
- Grand total
- Payment method and status
- Verification QR code (containing verification URL)
- Download PDF button

### Database Table Created

```sql
INSERT INTO invoices (
  uuid, order_id, transaction_id, user_id,
  invoice_number, invoice_series, sequence_number, sequence_year,
  subtotal, shipping_price, coupon_discount, promotion_discount,
  total_discount, total, amount_paid, currency,
  payment_method, payment_gateway, status,
  data, snapshot_hash, verification_hash,
  generated_at, generated_by
) VALUES (...);
```

---

## 12.16 Download Invoice PDF

### Endpoint

| Endpoint | Method | Auth | Throttle | Controller |
|---|---|---|---|---|
| `/api/v1/general/invoices/{uuid}/download` | GET | Sanctum | 30/1min | `InvoiceController::download` |

### Flow

```
Customer clicks "Download PDF"
  → GET /invoices/{uuid}/download
  → 1. Find invoice by UUID
  → 2. Authorization check: owner or admin with VIEW_INVOICE permission
  → 3. Check pdf_path exists
       - If not → return 404 "PDF not yet generated"
  → 4. Update downloaded_at timestamp
  → 5. Record timeline event (downloaded)
  → 6. Return storage URL: url('storage/invoices/' . $invoice->pdf_path)
```

### Source Code References

- **Controller:** `app/Http/Controllers/Api/InvoiceController.php:163-194`
- **Timeline:** `app/Services/Invoice/InvoiceTimelineService.php:45-48`

### PDF Generation Status

| Status | Meaning | Can Download? |
|---|---|---|
| `generated` | Invoice created, PDF generation pending | No |
| `pdf_generating` | PDF job dispatched, processing | No |
| `ready` | PDF generated successfully | Yes |
| `failed` | PDF generation failed | No (regenerate required) |

### Note on PDF Generation

Current implementation in `GenerateInvoicePdfJob` is a PLACEHOLDER:

```php
// app/Jobs/GenerateInvoicePdfJob.php
Log::info('PDF generation placeholder for invoice ' . $this->invoice->invoice_number);
$this->invoice->update(['status' => 'ready', 'pdf_generated_at' => now()]);
```

No actual PDF file is generated. The job logs and sets status to `ready`. Real PDF generation (e.g., Laravel Snappy, DomPDF, or external service) needs to be implemented.

### What the Customer Sees

- PDF file downloaded (after real PDF engine is implemented)
- Or: "PDF not yet generated" message with current status

---

## 12.17 Verify Invoice

### Endpoint

| Endpoint | Method | Auth | Throttle | Controller |
|---|---|---|---|---|
| `/api/v1/general/invoices/verify/{uuid}` | GET | No | 60/1min | `InvoiceController::verify` |

### PUBLIC Endpoint — No Authentication Required

### Flow

```
Anyone with the UUID can verify the invoice
  → GET /invoices/verify/{uuid} (throttled: 60 requests/min)
  → InvoiceService::verifyInvoice(uuid)
  → 1. Find invoice by UUID with order + user
  → 2. Compute expected verification hash:
       expectedHash = SHA256( snapshot_hash + app_key )
  → 3. Compare with stored verification_hash using hash_equals()
  → 4. If match → authentic
  → 5. If mismatch → tampered
  → 6. Update verify_count, last_verified_at
  → 7. Record timeline event (verified)
  → Return verification result
```

### Source Code References

- **Controller:** `app/Http/Controllers/Api/InvoiceController.php:115-161`
- **Service:** `app/Services/Invoice/InvoiceService.php:94-112`
- **Integrity:** `app/Services/Invoice/SnapshotIntegrityService.php`

### Verification Algorithm

```
snapshot_hash = SHA256( sorted_json(snapshot) )
verification_hash = SHA256( snapshot_hash . app_key )
verify: hash_equals( verification_hash, stored_verification_hash )
```

### What the Customer Sees

- **Authentic:** Invoice details displayed with green "✓ Verified" badge
- Order number, status, payment status
- Invoice number, amount, date
- QR code content includes the verification URL

- **Tampered:** "Invoice verification failed" with red "✗ Tampered" badge
- `{ authentic: false, tampered: true }`

- **Not Found:** 404 "Invoice not found"

### QR Code on Invoice

- Contains URL: `{app_url}/api/v1/general/invoices/verify/{uuid}`
- Anyone can scan and verify authenticity
- No login required

### Response Structure (Authentic)

```json
{
  "success": true,
  "message": "Data fetched successfully",
  "data": {
    "authentic": true,
    "invoice": { ... InvoiceResource ... },
    "order": {
      "id": 1,
      "order_number": "ORD-12345",
      "status": "completed",
      "payment_status": "payment-success",
      "fulfillment_status": "fulfilled"
    },
    "qr_content": "https://.../invoices/verify/{uuid}"
  }
}
```

---

## 12.18 Track Shipment

### What the Customer Sees

- In order detail page, shipment tracking section
- Courier name
- Tracking number
- Current status (pending → label_created → picked_up → in_transit → out_for_delivery → delivered)
- Status timeline with dates
- Estimated delivery date

### Shipment Status Transitions

```
pending → label_created → picked_up → in_transit → out_for_delivery → delivered
                                    ↘ delayed ↗
                                                    ↘ failed_delivery → returned
```

### Source Code References

- **Model:** `app/Models/Shipment.php:69-84`
- **Service:** `app/Services/Shipment/ShipmentService.php`

---

## 12.19 Request Refund

### Flow

```
Customer contacts support
  → Support initiates refund process (admin action)
  → RefundManager approves/rejects
  → If approved:
       - RestoreInventoryOnRefund listener restores stock
       - GenerateCreditNoteOnRefund creates credit note
       - Inventory is frozen after restoration (inventory_restored_at guard)
```

### Source Code References

- **Listeners:**
  - `app/Listeners/RestoreInventoryOnRefund.php`
  - `app/Listeners/GenerateCreditNoteOnRefund.php`
  - `app/Listeners/RestoreProductInventory.php`

### What the Customer Sees

- Refund status updates in order detail
- Credit note (if applicable)
- Refund amount

---

## 12.20 Notifications

### Notification Events and Delivery

| Event | Dispatched At | Destination | Method |
|---|---|---|---|
| `OrderCreated` | After order creation (line 43 of OrderCreationService) | Admins (type=admin, is_active=true) | Notification + Activity Log |
| `PaymentSucceeded` | After payment callback success (line 53 of OrderController) | Activity Log only | `LogActivityJob` (queue:medium) |
| `PaymentFailed` | After payment callback failure (line 274 of OrderController) | Activity Log only | `LogActivityJob` (queue:medium) |
| `OrderStatusChanged` | After order status transition (line 594 of OrderService) | Activity Log only | `LogActivityJob` (queue:medium) |
| `OrderCancelled` | After order cancellation (line 597 of OrderService) | Activity Log only | `SendOrderCancelledNotification` |

### Notification Listeners

| Listener | File | Queue | Handles |
|---|---|---|---|
| `SendNewOrderNotification` | `app/Listeners/SendNewOrderNotification.php` | medium | `OrderCreated` |
| `SendPaymentSucceededNotification` | `app/Listeners/SendPaymentSucceededNotification.php` | medium | `PaymentSucceeded` |
| `SendPaymentFailedNotification` | `app/Listeners/SendPaymentFailedNotification.php` | medium | `PaymentFailed` |
| `SendOrderStatusChangedNotification` | `app/Listeners/SendOrderStatusChangedNotification.php` | medium | `OrderStatusChanged` |
| `SendOrderCancelledNotification` | `app/Listeners/SendOrderCancelledNotification.php` | — | `OrderCancelled` |
| `GenerateInvoiceListener` | `app/Listeners/GenerateInvoiceListener.php` | high | `PaymentSucceeded` |
| `LogInvoiceCreated` | `app/Listeners/LogInvoiceCreated.php` | sync | `InvoiceCreated` |

### Activity Log Structure

All activity logs use `spatie/laravel-activitylog` via `LogActivityJob`:

```php
activity($logName)
  ->performedOn($subject)
  ->withProperties($properties)
  ->event($event)
  ->causedBy($causer)
  ->log($description);
```

### Important: No Customer-Facing Notifications for Invoice

Invoice generation and PDF generation do NOT trigger customer-facing notifications (email/SMS). The invoice is available in "My Invoices" section, and the customer must check manually.

---

## 12.21 Complete Customer Journey Diagram

```
                    ┌─────────────────────────────────────┐
                    │         Customer Journey             │
                    └─────────────────────────────────────┘

  BROWSE ──→ ADD TO CART ──→ VIEW CART ──→ APPLY COUPON
    │                                              │
    │                                    ┌─────────┘
    │                                    ▼
    │                           SEE PROMOTIONS
    │                                    │
    │                                    ▼
    │                           SELECT PROMOTION
    │                                    │
    │                                    ▼
    │                            PROCEED TO CHECKOUT
    │                                    │
    │                     ┌──────────────┼──────────────┐
    │                     ▼              ▼              ▼
    │                 ONLINE PAY     COD          PAY AT CASHIER
    │                     │              │              │
    │                     ▼              │              │
    │              REDIRECT TO           │           QR CODE
    │              MYFATOORAH            │          DISPLAYED
    │                     │              │              │
    │                     ▼              │              │
    │           ┌─── PAYMENT ───┐        │              │
    │           │              │         │              │
    │           ▼              ▼         ▼              ▼
    │      PAYMENT        PAYMENT     ORDER          SCAN QR
    │      SUCCESS        FAILED     CONFIRMED     AT CASHIER
    │           │              │         │              │
    │           ▼              ▼         │              │
    │     INVOICE          ERROR        │              │
    │     GENERATED        PAGE         │              ▼
    │           │                      │        ADMIN MARKS
    │           ▼                      │        AS PAID
    │    VIEW/DOWNLOAD                 │              │
    │    INVOICE PDF                   │              ▼
    │           │                      │        INVOICE
    │           ▼                      │        GENERATED
    │    VERIFY INVOICE                │
    │    (PUBLIC, NO AUTH)             │
    │                                  │
    │                     ┌────────────┘
    │                     ▼
    │              TRACK SHIPMENT
    │                     │
    │                     ▼
    │              RECEIVE ORDER
    │                     │
    │                     ▼
    │              REQUEST REFUND
    │              (VIA SUPPORT)
    │
    └── NOTIFICATIONS (all logged to activity log):
        • OrderCreated → admin notification + log
        • PaymentSucceeded → log only
        • PaymentFailed → log only
        • OrderStatusChanged → log only
        • InvoiceCreated → sync log only
```

---

## 12.22 Error Recovery by Scenario

| Scenario | What Happens | Customer Sees |
|---|---|---|
| Stock insufficient at add-to-cart | Exception thrown, transaction rolled back | "Quantity exceeds available stock" |
| Stock insufficient at checkout | `ensureCartReservation()` throws | Error message, items removed from cart |
| Coupon expired between apply and checkout | Re-validated, cleared from cart | Discount removed, total updated |
| Promotion exhausted between selection and checkout | Re-validated, promotion removed from items | Promotion discount removed |
| Payment gateway timeout | Exception in `handleOnlinePayment()` | "Payment gateway unavailable, please try again" |
| Payment amount mismatch | Callback detects, blocks order, fires PaymentFailed | Redirected to /payment/failed with error |
| Payment currency mismatch | Same as amount mismatch | Redirected to /payment/failed |
| Duplicate callback (idempotency) | `if (order.status !== 'pending') return` | Redirected to /payment/success (already processed) |
| Callback before transaction created | Transaction lookup returns null, no order found | Redirected to /payment/success with generic message |
| Invoice generation fails | `GenerateInvoiceListener` throws, retries 5 times (backoff: 10,30,60,120,300s) | Invoice may not appear, admin must check `last_generation_error` |
| PDF generation fails | `GenerateInvoicePdfJob` retries 3 times (backoff: 30,120,300s), status set to `failed` | "PDF not yet generated" in download page |
| Cart expires (3 days) | `expireCarts()` releases all reserved stock | Cart is empty, items removed |

---

## 12.23 Key Configuration Values

| Config | Location | Default | Purpose |
|---|---|---|---|
| `CART_TTL_DAYS` | `CartInventoryService.php:20` | 3 | Days before cart reservation expires |
| `DEFAULT_PER_PAGE` | `OrderService.php:37` | 15 | Default orders per page |
| `MAX_PER_PAGE` | `OrderService.php:39` | 100 | Maximum items per page |
| `default_currency` | `config/payment.php` | 'EGP' | Default payment currency |
| `default_gateway` | `config/payment.php` | 'myfatoorah' | Default payment gateway |
| `minimum_order_amount` | `Settings` table | 0 | Minimum order subtotal |

---

## 12.24 Related Files Index

| Concern | File(s) |
|---|---|
| Browse products | `ProductController`, `ProductService`, `ProductFilter` |
| Browse categories | `CategoryController`, `CategoryService` |
| Browse brands | `BrandController`, `BrandService` |
| Cart operations | `CartInventoryService` |
| Apply coupon | `CouponController`, `CouponService`, `CouponOrchestrator` |
| Promotions | `PromotionController`, `PromotionService`, `PromotionEngine/*` |
| Checkout | `OrderController::checkout()`, `OrderService`, `OrderCreationService` |
| Payment online | `PaymentCheckoutHandler`, `MyFatoorahGateway`, `PaymentGatewayFactory` |
| Payment COD | `PaymentCheckoutHandler::handleCodPayment()` |
| Payment cashier | `PaymentCheckoutHandler::handleCashierQrPayment()`, `CashierQrService` |
| Payment callback | `OrderController::checkoutCallback()` |
| Payment error callback | `OrderController::checkoutErrorCallback()` |
| Invoices | `InvoiceController`, `InvoiceService`, `InvoiceSnapshotService` |
| Invoice verification | `InvoiceService::verifyInvoice()`, `SnapshotIntegrityService` |
| Invoice PDF | `GenerateInvoicePdfJob` (placeholder) |
| Shipments | `ShipmentController`, `ShipmentService` |
| Notifications | All listeners in `app/Listeners/`, `LogActivityJob` |
| Activity log | `spatie/laravel-activitylog` package |
| Events | All events in `app/Events/` |