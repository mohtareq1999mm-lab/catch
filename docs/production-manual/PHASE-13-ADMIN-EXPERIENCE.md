# Phase 13: Admin Experience — Production Operations Manual

> **Purpose:** Document every administrative action, dashboard endpoint, management feature, and system tool available to administrators.

---

## 13.1 Order Management

### Order Status Transitions

Source: `app/Services/General/OrderService.php:474-489`

```
         ┌─────────┐
         │ pending │
         └────┬────┘
              │
      ┌───────┼───────────┐
      ▼       ▼           ▼
 ┌─────────┐ ┌────────┐ ┌──────────┐
 │processing│ │completed││cancelled │
 └────┬────┘ └───┬────┘ └──────────┘
      │          │
      ▼          ▼
 ┌─────────┐ ┌──────────┐
 │completed│ │ delivered│
 └─────────┘ └──────────┘
```

Allowed transitions (from code):
- pending → pending, processing, completed, cancelled
- processing → processing, completed, cancelled
- completed → completed, delivered
- delivered → delivered
- cancelled → cancelled

### Fulfillment Status Transitions

Source: `app/Services/General/OrderService.php:482-489`

```
pending → processing → ready_for_pickup / out_for_delivery → delivered
                                                  ↘ cancelled
```

### Change Order Status

Service method: `OrderService::changeOrderStatus($invoiceId, $status, $orderId)`

Behavior:
1. Locks order with `lockForUpdate()` inside DB transaction
2. Validates transition via `canTransitionOrderStatus()`
3. Auto-sets payment_status, completed_at, cancelled_at when status changes
4. Updates fulfillment_status based on status map
5. Records coupon usage when status → completed
6. Updates transaction status (paid on completed, failed on cancelled)
7. Decrements promotion usage on cancellation
8. Dispatches `OrderStatusChanged` and `OrderCancelled` events

Source: `app/Services/General/OrderService.php:501-602`

### Cancel Order

Service method: `OrderService::changeOrderStatus()` with status=`cancelled`

Transactional flow:
1. Lock order row
2. Check `canTransitionOrderStatus('pending'→'cancelled')` or `('processing'→'cancelled')`
3. Set order status = `cancelled`, `cancelled_at` = now()
4. Set transaction status = `failed`
5. Decrement promotion usage
6. Dispatch `OrderStatusChanged` + `OrderCancelled` events
7. Commit transaction

---

## 13.2 Payment Management

### Mark COD/Cashier as Paid

| Endpoint | Method | Permission |
|---|---|---|
| `POST /checkout/cod/{orderId}/mark-paid` | POST | `update-order-status` |
| `POST /checkout/cashier/{orderId}/mark-paid` | POST | `update-order-status` |

### Flow (both COD and Cashier)

Source: `OrderService::markCodAsPaid()` at line 604, `markCashierPaid()` at line 648

```
1. Find pending transaction (lockForUpdate)
2. Validate transaction exists
3. Update transaction: status='paid', paid_at=now()
4. Update order: status='completed', payment_status='success', completed_at=now()
5. Record coupon usage (one-time, non-reversible)
6. Finalize promotion usage (increment usage counter)
7. Finalize inventory (deduct stock via cart or direct deduction)
8. Dispatch PaymentSucceeded event
```

If no pending transaction found → throws RuntimeException with i18n message.

---

## 13.3 Invoice Management

### Endpoints

| Endpoint | Method | Permission | Controller Method |
|---|---|---|---|
| `GET /invoices` | GET | VIEW_INVOICES | `index` |
| `GET /invoices/{id}` | GET | VIEW_INVOICE | `show` |
| `GET /invoices/uuid/{uuid}` | GET | VIEW_INVOICE | `showByUuid` |
| `POST /invoices/{id}/regenerate` | POST | REGENERATE_INVOICE | `regenerate` |
| `POST /invoices/{id}/correct` | POST | CORRECT_INVOICE | `correct` |
| `POST /invoices/{id}/cancel` | POST | CANCEL_INVOICE | `cancel` |
| `POST /invoices/{id}/debit-note` | POST | — | `issueDebitNote` |
| `GET /invoices/my-invoices` | GET | Sanctum | `myInvoices` |
| `GET /invoices/verify/{uuid}` | GET | public | `verify` |
| `GET /invoices/{uuid}/download` | GET | Sanctum | `download` |

Source: `app/Http/Controllers/Api/InvoiceController.php`

### List/Search Invoices

Source: `InvoiceController::index()` at line 37-69

Supported filters:
- `search`: searches `invoice_number` and `order.order_number`
- `status`: filter by invoice status
- `order_id`: filter by order ID
- `user_id`: filter by user ID
- `invoice_series`: filter by series code
- `currency`: filter by currency
- `from`/`to`: date range filter
- `sort_by`: `created_at`, `total`, `status`, `invoice_number`
- `sort_direction`: `asc`, `desc`
- `limit`: per page (max 100)

Eager loads: `order`, `user`

### Invoice Detail

Source: `InvoiceController::show()` at line 71-81

Eager loads: `order.orderItems`, `transaction`, `user`
Returns: `InvoiceResource` with full invoice data

### Regenerate PDF

Source: `InvoiceController::regenerate()` at line 196-218

Allowed statuses: `failed`, `ready`, `generated`

Behavior:
1. Validate invoice status is in allowed list
2. Set status = `pdf_generating`
3. Increment `generation_attempts`
4. Clear `last_generation_error`
5. Record timeline (`pdf_regenerated`)
6. Dispatch `GenerateInvoicePdfJob`

If status not allowed → return 422 error.

### Correct Invoice

Source: `InvoiceController::correct()` at line 220-239
Service: `InvoiceService::correctInvoice()` at line 114-176

Allowed original statuses: `generated`, `ready`, `verified`, `downloaded`, `printed`

Process:
1. Lock original invoice
2. Generate new invoice number (next sequence)
3. Clone snapshot with overrides applied
4. Set `correction_reason` in snapshot audit
5. Compute new snapshot hash + verification hash
6. Create correction invoice with `is_correction=true`
7. Mark original as status=`corrected`
8. Record timeline for both invoices
9. Return correction invoice

Overrides passed via `CorrectInvoiceRequest` (validates structure).
Reason is required (string, max 500 chars).

### Cancel Invoice

Source: `InvoiceController::cancel()` at line 241-261
Service: `InvoiceService::cancelInvoice()` at line 178-198

Allowed statuses: `generated`, `ready`, `failed`, `corrected`

Process:
1. Lock invoice
2. Set status = `cancelled`, `cancelled_at` = now(), `cancellation_reason`
3. Record timeline
4. Return cancelled invoice

Reason is required (validated in controller).

### Issue Debit Note

Source: `InvoiceController::issueDebitNote()` at line 263-279
Service: `DebitNoteService::generate()` at line 15-41

Allowed invoice statuses: `generated`, `ready`, `verified`, `downloaded`, `printed`

Process:
1. Validate invoice status
2. Generate debit note number (prefix `DN`)
3. Create `DebitNote` record with amount, reason, line items from snapshot
4. Return debit note

Debit note is NOT linked to invoice status changes. Does NOT modify invoice status.

### Invoice Status Machine

Source: `app/Enums/InvoiceStatus.php`

```
pending → generating → generated → pdf_generating → ready
                                    ↘ verified → downloaded → printed → archived
                                    ↘ corrected → cancelled → archived
                                    ↘ cancelled
                        pdf_generating ↘ failed → pdf_generating (retry)
                                                ↘ cancelled
```

Allowed transitions (from enum):
- `PENDING`: GENERATING, CANCELLED
- `GENERATING`: GENERATED, FAILED
- `GENERATED`: PDF_GENERATING, VERIFIED, DOWNLOADED, PRINTED, CORRECTED, CANCELLED
- `PDF_GENERATING`: READY, FAILED
- `READY`: DOWNLOADED, PRINTED, VERIFIED, FAILED, CORRECTED, CANCELLED, ARCHIVED
- `FAILED`: PDF_GENERATING, CANCELLED
- `VERIFIED`: DOWNLOADED, PRINTED, ARCHIVED
- `DOWNLOADED`: PRINTED, VERIFIED, ARCHIVED
- `PRINTED`: DOWNLOADED, VERIFIED, ARCHIVED
- `CORRECTED`: CANCELLED, ARCHIVED
- `CANCELLED`: ARCHIVED
- `ARCHIVED`: (terminal)

### Invoice Timeline Events

Source: `app/Services/Invoice/InvoiceTimelineService.php`

Events recorded: `generated`, `verified`, `downloaded`, `printed`, `pdf_regenerated`, `corrected`, `cancelled`, `archived`

Each event records:
- `invoice_id`, event name
- `old_status`, `new_status`
- `actor_type`, `actor_id` (morph)
- `metadata` (JSON)
- `ip_address`
- `created_at`

---

## 13.4 Shipment Management

### Endpoints

| Endpoint | Method | Controller |
|---|---|---|
| `GET /shipments` | GET | `index` |
| `GET /shipments/{id}` | GET | `show` |
| `GET /shipments/uuid/{uuid}` | GET | `showByUuid` |
| `POST /shipments` | POST | `store` |
| `PUT /shipments/{id}/status` | PUT | `updateStatus` |
| `PUT /shipments/{id}` | PUT | `update` |

Source: `app/Http/Controllers/Api/ShipmentController.php`

### Create Shipment

Source: `ShipmentService::create()` at line 34-40

Request: `CreateShipmentRequest`
Data: `order_id`, `tracking_number`, `courier`, `shipping_method`, `shipping_cost`, `currency`, `origin_address`, `destination_address`, `items`, `total_weight`, `weight_unit`, `notes`, `metadata`

Behavior:
1. Set status = `pending`
2. Create shipment record in transaction
3. Return shipment with relations

### Update Shipment Status

Source: `ShipmentService::updateStatus()` at line 42-68

1. Lock shipment with `lockForUpdate()`
2. Validate transition via `Shipment::canTransitionTo()`
3. Update status
4. Auto-set timestamps:
   - `shipped_at` when status → `shipped`/`picked_up` (if not already set)
   - `delivered_at` when status → `delivered`
5. Update notes if provided
6. Return fresh shipment

### Shipment Status Transitions

Source: `app/Models/Shipment.php:69-84`

```
pending → label_created → picked_up → in_transit → out_for_delivery → delivered
              ↘ cancelled     ↘ cancelled  ↘ delayed              ↘ failed_delivery
                                       in_transit ← delayed               ↘ returned
                                                                           out_for_delivery ← failed_delivery
```

### List/Filter Shipments

Source: `ShipmentService::list()` at line 10-22

Filters: `order_id`, `status`, `courier`, `tracking_number`, `from`, `to`
Pagination: `per_page` (max 100)
Default order: `created_at` desc

---

## 13.5 Refund Management

### Flow

```
Customer requests refund (via support)
  → Admin reviews request
  → Admin approves or rejects
  → If approved:
      1. RestoreInventoryOnRefund listener fires
         - Restores product/variant stock quantities
         - Sets inventory_restored_at guard (prevents double restore)
      2. GenerateCreditNoteOnRefund listener fires
         - Creates credit note linked to original invoice
      3. Inventory is frozen after restoration
```

Source listeners:
- `app/Listeners/RestoreInventoryOnRefund.php`
- `app/Listeners/GenerateCreditNoteOnRefund.php`
- `app/Listeners/RestoreProductInventory.php`

### Inventory Restoration Guard

After inventory is restored (via refund), the `inventory_restored_at` timestamp is set. This guard prevents the same inventory from being restored twice. If a refund is processed again, the guard check fails and the restore is skipped.

---

## 13.6 User Management

### User Types

Source: `app/Enums/UserType.php`

- `ADMIN` → admin users with backend access
- `USER` → regular customers

### Roles & Permissions

Role-based access control via permissions middleware:

```php
Route::middleware(['permission:update-order-status'])
Route::middleware(['permission:view-invoices'])
Route::middleware(['permission:regenerate-invoice'])
Route::middleware(['permission:correct-invoice'])
Route::middleware(['permission:cancel-invoice'])
```

Permissions are checked at the controller level using `$this->middleware('permission:...')` or at route level.

---

## 13.7 Product Management

### CRUD Operations

- **Create Product**: name, description, price, SKU, images, categories, tags
- **Read Product**: details with pricing, stock, reviews
- **Update Product**: modify any product attribute
- **Delete Product**: soft-delete

### Related Entities

- **Categories**: hierarchical (parent/child via `category_product` pivot)
- **Brands**: each product belongs to a brand
- **Tags**: many-to-many via `taggables` polymorphic pivot
- **Product Variants**: size/color/attribute combinations with own price/stock
- **Product Reviews**: customer ratings and comments
- **Media**: product images

### Pricing Engine Strategies

Source: `app/Services/General/ProductEngine/Strategies/`

- `BestProduct`
- `NewArrivals`
- `AllProduct`
- `AllProductHasDiscount`
- `ProductForBrand`
- `ProductForParentCategory`
- `ProductHasFlashSale`
- `ProductHasFlashSaleEndToday`
- `ProductHasFlashSaleEndThisWeek`
- `ProductDiscountEndingTodayOrLowStock`

Strategy resolver: `app/Services/General/ProductEngine/ProductStrategyResolver.php`

---

## 13.8 Coupon Management

### Discount Types

| Type | Behavior |
|---|---|
| `percentage` | Deducts `coupon.amount`% off |
| `fixed` | Deducts fixed `coupon.amount` |
| `free_shipping` | Sets shipping cost to 0 |

Source: `Marvel\Enums\DiscountType`

### Coupon Consumption Policy

```
Coupon quota is consumed on payment success.
It is NEVER automatically returned on cancellation or refund.
This prevents abuse where a user could re-use the same quota
by repeatedly cancelling and re-ordering.
```

Source: `OrderService::recordCouponUsage()` at line 733, with inline documentation at lines 712-732

### Assigned Coupons

- Coupons can be assigned to specific users
- Each assignment has per-user `max_uses`
- Usage tracked in `coupon_assignment_usages` (audit trail)
- Assignment row is locked (`lockForUpdate`) before incrementing
- Prevents concurrent over-consumption

Source: `CouponAssignment`, `CouponAssignmentUsage` models

---

## 13.9 Promotion Management

### Promotion Strategies

Source: `app/Services/General/PromotionEngine/Strategies/`

1. **PercentagePromotionStrategy** — % discount to eligible items
2. **FixedPromotionStrategy** — fixed amount discount
3. **GiftPromotionStrategy** — free gift product to cart

### Promotion Evaluation

```
Source: PromotionEligibilityResolver → PromotionEvaluation → PromotionApplicator

1. Get all active promotions
2. Filter by eligibility (cart contents, user, date)
3. Evaluate each eligible promotion (calculate discount/gift)
4. Return best result or all results for frontend selection
5. At checkout, applySelectedPromotion finalizes the chosen one
```

### Promotion Consumption

Source: `OrderService::finalizePromotionUsageAfterPayment()` at line 45-59

- Only increments usage counter AFTER payment succeeds
- Uses guard: if `promotion_consumed` already true, skip
- Sets `promotion_consumed = true` on order
- On cancellation: decrements usage via `decrementUsage()`

---

## 13.10 CMS (Content Management)

| Feature | Endpoints | Controller |
|---|---|---|
| Content Pages | `GET /content-pages`, `GET /content-pages/{slug}` | `ContentPageController` |
| Sliders | `GET /sliders`, `GET /sliders/{slug}` | `SliderController` |
| Banners | `GET /banners`, `GET /banners/{slug}` | `BannerController` |
| FAQs | `GET /faqs` | `FAQController` |

---

## 13.11 Dashboard Analytics

### All Dashboard Endpoints

Source: `app/Services/Dashboard/DashboardService.php` (822 lines)

| Analytics | Method | Cache Key | Cache TTL |
|---|---|---|---|
| Overview | `getOverview` | `dashboard_overview` | 300s |
| Revenue Overview | `getRevenueOverview` | `dashboard_revenue` | 300s |
| Order Status Overview | `getOrderStatusOverview` | `dashboard_order_stats` | 300s |
| Recent Orders | `getRecentOrders` | `dashboard_recent_orders_{n}` | 300s |
| Top Selling Products | `getTopSellingProducts` | `dashboard_top_products_{n}` | 300s |
| Category Stats | `getCategoryStats` | `dashboard_category_stats` | 300s |
| Low Stock Products | `getLowStockProducts` | `dashboard_low_stock_{n}` | 300s |
| Sales Analytics | `getSalesAnalytics` | `dashboard_sales_analytics` | 300s |
| Customer Analytics | `getCustomerAnalytics` | `dashboard_customer_analytics` | 300s |
| Product Analytics | `getProductAnalytics` | `dashboard_product_analytics` | 300s |
| Order Analytics | `getOrderAnalytics` | `dashboard_order_analytics` | 300s |
| Category Analytics | `getCategoryAnalytics` | `dashboard_category_analytics` | 300s |
| Coupon Analytics | `getCouponAnalytics` | `dashboard_coupon_analytics` | 300s |
| Cart Analytics | `getCartAnalytics` | `dashboard_cart_analytics` | 300s |
| Finance Analytics | `getFinanceAnalytics` | `dashboard_finance_analytics` | 300s |
| Reconciliation | `getReconciliationSummary` | (no cache) | — |

### Dashboard Metrics Detail

**Overview**: total_revenue, todays_revenue, total_refunds, total_orders, total_products, total_customers, new_customers (30d)
**Revenue**: monthly breakdown Jan-Dec, AOV, revenue by payment method, revenue by fulfillment type
**Customers**: new vs returning, monthly growth (12mo), top by orders/revenue, CLV, active (7/30/90d)
**Products**: best/worst/never sold, out of stock, inventory value
**Orders**: daily/weekly/monthly timeline, success/cancellation/refund rates
**Categories**: product distribution, revenue by category, growth vs previous month
**Coupons**: total usage, top 10, revenue per coupon, total discount
**Carts**: abandonment rate, most added products, avg cart value, checkout dropoff rate
**Finance**: gross/net revenue, refund amount, total discount, shipping revenue

---

## 13.12 Settings Management

| Setting | Source Model |
|---|---|
| System Settings (min order amount, store info) | `Settings` |
| Shipping Prices (per-governorate) | `ShippingPrice` |
| Governorates | `Governorate` |
| Pickup Locations | `PickupLocation` |

### Endpoints

```
GET /settings           — public
GET /governorates       — public
GET /pickup-locations   — public
GET /pickup-locations/{id} — public
```

---

## 13.13 Activity Log

### Package

**spatie/laravel-activitylog**

### Logged Events

| Event | Log Name | Queued |
|---|---|---|
| `order_created` | orders | `LogActivityJob` (medium) |
| `payment_succeeded` | orders | `LogActivityJob` (medium) |
| `payment_failed` | orders | `LogActivityJob` (medium) |
| `order_status_changed` | orders | `LogActivityJob` (medium) |
| `user_roles_updated` | users | `LogUserRolesUpdated` (sync) |

### LogActivityJob

Source: `app/Jobs/LogActivityJob.php`, queue: medium

Accepts: `subjectType` (class), `subjectId`, `causerId`, `event`, `logName`, `description`, `properties`
Resolves subject from database inside `handle()`
Creates `activity()` entry via spatie/laravel-activitylog

---

## 13.14 Real-Time Notifications (Pusher)

### AdminLoggedIn Event

Source: `app/Events/AdminLoggedIn.php`, implements `ShouldBroadcast`

- **Channel**: `private-admin.notifications`
- **Event name**: `admin.logged.in`
- **Data**: `id`, `name`, `email`, `type`, `login_time` (ISO 8601)

### Channel Authorization

Configured in `routes/channels.php` — only admin users can subscribe.

### Test Endpoint

`GET /test-pusher` (web route) — dispatches `AdminLoggedIn` + direct Pusher trigger

---

## 13.15 Queue Monitoring (Laravel Telescope)

### Queue Configuration

| Queue | Workers | Used By |
|---|---|---|
| `high` | 1 | `GenerateInvoiceListener` |
| `medium` | 2 | `LogActivityJob`, notification listeners |
| `low` | 1 | `GenerateInvoicePdfJob`, `PaymentReconciliationJob` |

### Telescope

Laravel Telescope is installed and available at `/telescope` for monitoring queues, jobs, exceptions, requests, logs, and more.

---

## 13.16 Payment Reconciliation

### PaymentReconciliationJob

Source: `app/Jobs/PaymentReconciliationJob.php`, queue: `low`

Purpose: Detect mismatches between local transactions and gateway records.
Results stored in `payment_reconciliation_results` table.

### Dashboard Summary

Source: `DashboardService::getReconciliationSummary()`

- `total_checked`: transactions with gateway_transaction_id and status != failed
- `total_mismatches`: count of PaymentReconciliationResult records
- `pending_mismatches`: unresolved mismatches
- `resolved_mismatches`: resolved mismatches
- `last_run`: timestamp of most recent reconciliation result

---

## 13.17 Common Admin Workflows

### Process a COD Order

```
1. Order placed (status=pending)
2. Customer receives order, pays cash
3. Admin navigates to order detail
4. Admin clicks "Mark as Paid"
5. POST /checkout/cod/{orderId}/mark-paid (permission: update-order-status)
6. Transaction: pending → paid
7. Order: pending → completed
8. Invoice auto-generated via PaymentSucceeded event
9. PDF generation dispatched to low queue
```

### Generate / Correct Invoice

```
Auto-generation: fires on PaymentSucceeded
If failed:
  1. Admin checks invoice status (failed/generated/ready)
  2. Admin regenerates (POST /invoices/{id}/regenerate)
  3. Status → pdf_generating, GenerateInvoicePdfJob dispatched
If correction needed:
  1. Admin uses correct endpoint with overrides
  2. New correction invoice created, original marked corrected
If void needed:
  1. Admin cancels invoice with reason
```

### Create Shipment

```
1. Order completed
2. Admin creates shipment (POST /shipments)
3. Status = pending
4. Admin updates as package moves: label_created → picked_up → in_transit → out_for_delivery → delivered
5. Customer sees tracking in order detail
```

### Manage Refund

```
1. Customer requests refund via support
2. Admin reviews (must be completed/delivered, payment successful)
3. Admin approves refund
4. Inventory restored (guarded by inventory_restored_at)
5. Credit note generated
6. Customer sees refund in order timeline
```

---

## 13.18 File Index

| Concern | File(s) |
|---|---|
| Order management | `OrderService`, `OrderController` |
| Payment management | `OrderService` (markCodAsPaid, markCashierPaid) |
| Invoice management | `InvoiceController`, `InvoiceService` |
| Invoice status machine | `InvoiceStatus` enum |
| Invoice timeline | `InvoiceTimeline`, `InvoiceTimelineService` |
| Invoice snapshot | `InvoiceSnapshotService`, `InvoiceSnapshotValidator` |
| Invoice integrity | `SnapshotIntegrityService` |
| Invoice number | `InvoiceNumberService`, `InvoiceSequence` |
| Debit notes | `DebitNoteService` |
| Shipment management | `ShipmentController`, `ShipmentService`, `Shipment` model |
| Refund management | `RestoreInventoryOnRefund`, `GenerateCreditNoteOnRefund` |
| User management | `User` model, `UserType` enum |
| Product management | `ProductController`, `ProductService`, `ProductEngine/*` |
| Coupon management | `CouponController`, `CouponService`, `CouponOrchestrator` |
| Promotion management | `PromotionController`, `PromotionService`, `PromotionEngine/*` |
| CMS | `ContentPageController`, `SliderController`, `BannerController`, `FAQController` |
| Dashboard | `DashboardController`, `DashboardService` |
| Settings | `SettingController`, `SettingService` |
| Activity log | `LogActivityJob`, spatie/laravel-activitylog |
| Real-time | `AdminLoggedIn` event, `channels.php` |
| Queue monitoring | Laravel Telescope |
| Reconciliation | `PaymentReconciliationJob`, `PaymentReconciliationResult` |