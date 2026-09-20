# REQUIREMENTS TRACEABILITY MATRIX
## Approved Business Requirements vs Current Implementation

**Date:** 2026-09-20  
**Repository:** D:\work\meem

---

## REQUIREMENT A: CUSTOMER ORDER TRACKING

### A.1: View Own Orders

**Requirement:** Customers must be able to view their own orders.

| Aspect | Status | Implementation | Gap | Acceptance Criteria |
|--------|--------|----------------|-----|-------------------|
| List orders | ✅ IMPLEMENTED | `GET /api/v1/general/my-orders`<br>Controller: `OrderTrackingController::listUserOrders()`<br>File: `app/Http/Controllers/Api/General/OrderTrackingController.php` | None | Customer can see paginated list of their orders |
| View single order | ✅ IMPLEMENTED | `GET /api/v1/general/orders/{orderId}`<br>Controller: `OrderController::show()`<br>Authorization: User must own order | None | Customer can view detailed order information |
| Authorization | ✅ IMPLEMENTED | Policy check in controller<br>Scoped queries by user_id | None | Customers cannot access other customers' orders |

---

### A.2: View Current Order Status

**Requirement:** Customers must be able to view the current order status.

| Aspect | Status | Implementation | Gap | Acceptance Criteria |
|--------|--------|----------------|-----|-------------------|
| Order status display | ✅ IMPLEMENTED | `order.status` field<br>Values: pending, processing, completed, cancelled, delivered | ⚠️ Status semantics unclear (see BUG-H001) | Status shown in order response |
| Payment status display | ⚠️ PARTIAL | `order.payment_status` field<br>Accessor with fallback logic | ⚠️ Inconsistently populated (BUG-H004) | Payment status shown |
| Fulfillment status | ❌ NOT USED | Column exists, rarely populated | ❌ Not integrated | Should show fulfillment progress |

**Affected Files:**
- `packages/marvel/src/Database/Models/Order.php` (status fields)
- API resources returning order data

**Proposed Solution:**
1. Clarify order status semantics (pending → processing → delivered)
2. Backfill payment_status for all orders
3. Decide: Use fulfillment_status or remove it

---

### A.3: View Delivery/Shipment Status

**Requirement:** Customers must be able to view the delivery/shipment status.

| Aspect | Status | Implementation | Gap | Acceptance Criteria |
|--------|--------|----------------|-----|-------------------|
| Shipment record | ⚠️ PARTIAL | Shipment model exists<br>Relationship: `Order` hasOne `Shipment` | ❌ Not auto-created (BUG-H002) | Every order has shipment record |
| Shipment status | ⚠️ PARTIAL | `shipment.status` field<br>State machine exists in model | ❌ No tracking history (BUG-C001) | Current status shown |
| Tracking number | ⚠️ PARTIAL | `shipment.tracking_number` field | ❌ Manual assignment only | Tracking number displayed if available |
| Delivery timeline | ❌ MISSING | No implementation | ❌ Complete gap | Estimated delivery date shown |

**Affected Files:**
- `app/Models/Shipment.php`
- Shipment needs to be included in order API responses

**Proposed Solution:**
1. Auto-create shipment on order payment confirmation
2. Include shipment data in order API responses
3. Add estimated_delivery_at to shipment display
4. Create shipment tracking events table

---

### A.4: View Order Progress Timeline

**Requirement:** Customers must see order progress as a chronological timeline.

| Aspect | Status | Implementation | Gap | Acceptance Criteria |
|--------|--------|----------------|-----|-------------------|
| Order status history | ✅ IMPLEMENTED | `order_status_history` table<br>Immutable audit log<br>Model: `app/Models/OrderStatusHistory.php` | None | All order status changes recorded |
| Shipment history | ❌ MISSING | No shipment tracking events table | ❌ Critical gap (BUG-C001) | Shipment status changes recorded |
| Timeline API endpoint | ❌ MISSING | No dedicated timeline endpoint | ❌ Missing | Endpoint returns chronological events |
| Timeline data structure | ❌ MISSING | No unified timeline format | ❌ Missing | Combined order + shipment events |

**Affected Files:**
- `app/Models/OrderStatusHistory.php` (exists, good)
- Need: `app/Models/ShipmentTrackingEvent.php` (missing)
- Need: Timeline aggregation service

**Proposed Solution:**
1. Create `shipment_tracking_events` table
2. Create timeline aggregation service that merges:
   - Order status history
   - Shipment tracking events
   - Payment events
   - Notification events (optional)
3. Add `GET /api/v1/general/orders/{id}/timeline` endpoint

---

### A.5: View Previous Tracking Events

**Requirement:** View previous tracking events (covered by A.4 timeline).

**Status:** ❌ MISSING (same as A.4)

---

### A.6: See Delivery Information

**Requirement:** See relevant delivery information including delivery method and tracking number.

| Aspect | Status | Implementation | Gap | Acceptance Criteria |
|--------|--------|----------------|-----|-------------------|
| Delivery address | ✅ IMPLEMENTED | `order.address` (JSON field) | None | Customer sees delivery address |
| Delivery method | ✅ IMPLEMENTED | `order.fulfillment_type` (delivery/pickup)<br>`order.shipping_method` (SCHEDULED/FAST) | None | Delivery method displayed |
| Tracking number | ⚠️ PARTIAL | `shipment.tracking_number` | ⚠️ Not consistently assigned | Tracking number shown when available |
| Courier info | ⚠️ PARTIAL | `shipment.courier` field | ⚠️ Rarely populated | Courier name shown |
| Estimated delivery | ⚠️ PARTIAL | `order.expected_delivery_at`<br>`shipment.estimated_delivery_at` | ⚠️ Not consistently calculated | ETA shown |
| Shipping cost | ✅ IMPLEMENTED | `order.shipping_price` | None | Shipping fee displayed |

**Affected Files:**
- Order and Shipment models
- API resources

**Proposed Solution:**
1. Ensure shipment.tracking_number populated when available
2. Admin workflow to assign courier and tracking number
3. Calculate and persist estimated_delivery_at consistently

---

### A.7: Receive Updates for Important Events

**Requirement:** Receive updates when important order or delivery events occur.

| Aspect | Status | Implementation | Gap | Acceptance Criteria |
|--------|--------|----------------|-----|-------------------|
| Order created | ✅ IMPLEMENTED | Listener: `SendUserOrderCreatedNotification`<br>Event: `OrderCreated` | ⚠️ No deduplication (BUG-C003) | Customer notified on order creation |
| Payment success | ✅ IMPLEMENTED | Event: `PaymentSucceeded`<br>Listeners exist | ⚠️ No deduplication | Customer notified on payment success |
| Payment failed | ✅ IMPLEMENTED | Event: `PaymentFailed` | ⚠️ No deduplication | Customer notified on payment failure |
| Order status changed | ✅ IMPLEMENTED | Listener: `SendOrderStatusChangedNotification` | ⚠️ No deduplication | Customer notified on status changes |
| Order delivered | ✅ IMPLEMENTED | Listener: `SendUserOrderDeliveredNotification` | ⚠️ No deduplication | Customer notified on delivery |
| Order cancelled | ✅ IMPLEMENTED | Listener: `SendUserOrderCancelledNotification` | ⚠️ No deduplication | Customer notified on cancellation |
| Shipment dispatched | ❌ MISSING | No event/listener | ❌ Missing | Customer notified when shipped |
| Out for delivery | ❌ MISSING | No event/listener | ❌ Missing | Customer notified when out for delivery |
| Delivery failed | ❌ MISSING | No event/listener | ❌ Missing | Customer notified on delivery failure |

**Affected Files:**
- `app/Events/*` (events exist for order lifecycle)
- `app/Listeners/*` (listeners exist for order lifecycle)
- Need: Shipment-specific events and listeners

**Proposed Solution:**
1. Add notification deduplication (BUG-C003)
2. Create shipment lifecycle events:
   - `ShipmentDispatched`
   - `ShipmentOutForDelivery`
   - `ShipmentDelivered`
   - `ShipmentDelayedOrFailed`
3. Create corresponding listeners
4. Integrate with shipment status changes

---

### A.8: Authorization & Security

**Requirement:** Customer must never access another customer's private order or tracking information.

| Aspect | Status | Implementation | Gap | Acceptance Criteria |
|--------|--------|----------------|-----|-------------------|
| Order ownership check | ✅ IMPLEMENTED | Scoped queries: `Order::forUser($userId)`<br>Policy checks in controllers | None | Only own orders accessible |
| Public tracking security | ✅ IMPLEMENTED | Requires order number + email/phone verification<br>Route: `POST /api/v1/general/track-order` | None | Public tracking properly verified |
| Authenticated tracking | ✅ IMPLEMENTED | Sanctum auth + ownership check | None | Auth users can only see own orders |
| Admin access | ✅ IMPLEMENTED | Permission-based: `permission:update-order-status` | None | Admins can access all orders with permissions |

**Affected Files:**
- `app/Http/Controllers/Api/General/OrderTrackingController.php`
- Authorization middleware

**Status:** ✅ **WELL IMPLEMENTED**

---

## REQUIREMENT B: NOTIFICATIONS

### B.1: Notification Persistence

**Requirement:** Notifications must be persisted and visible in customer's notification history/inbox.

| Aspect | Status | Implementation | Gap | Acceptance Criteria |
|--------|--------|----------------|-----|-------------------|
| Laravel notifications table | ✅ IMPLEMENTED | Standard `notifications` table<br>UUID primary key, polymorphic | None | All notifications stored |
| Order notifications tracking | ✅ IMPLEMENTED | `order_notifications` table<br>Tracks delivery status per channel | None | Order notifications tracked separately |
| Notification history endpoint | ✅ IMPLEMENTED | `GET /api/v1/user/notifications/history`<br>Controller: `NotificationPreferencesController` | None | Customer can view notification history |
| Read/unread status | ✅ IMPLEMENTED | `read_at` timestamp in notifications table | None | Notifications marked as read |
| Pagination | ⚠️ NEEDS VERIFICATION | Endpoint exists | ⚠️ Need to verify | History properly paginated |

**Affected Files:**
- `database/migrations/2026_07_05_080106_create_notifications_table.php`
- `database/migrations/2026_09_12_000002_create_order_notifications_table.php`
- `app/Http/Controllers/Api/User/NotificationPreferencesController.php`

**Status:** ✅ **WELL IMPLEMENTED** (minor verification needed)

---

### B.2: Multi-Channel Delivery

**Requirement:** Notifications delivered through supported channels including Pusher/broadcasting.

| Aspect | Status | Implementation | Gap | Acceptance Criteria |
|--------|--------|----------------|-----|-------------------|
| Email | ✅ IMPLEMENTED | Via Laravel Notification mail channel | None | Email notifications sent |
| Database | ✅ IMPLEMENTED | Via Laravel Notification database channel | None | Notifications persisted |
| Pusher/Broadcasting | ✅ IMPLEMENTED | `OrderStatusChanged` implements `ShouldBroadcast`<br>Private channels: `user.{id}.orders`, `order.{id}` | None | Real-time updates via WebSocket |
| SMS | ⚠️ UNCERTAIN | Infrastructure exists (Twilio config) | ⚠️ Need verification | SMS sent for critical events |
| Push (FCM) | ⚠️ UNCERTAIN | Firebase config exists<br>Device token endpoint exists | ⚠️ Need verification | Push notifications sent |

**Affected Files:**
- `app/Events/OrderStatusChanged.php` (broadcasts correctly)
- `app/Notifications/*` (notification classes)
- `routes/channels.php` (channel authorization)

**Proposed Solution:**
Verify SMS and Push notification integration is active and tested.

---

### B.3: Notification Event Coverage

**Requirement:** Identify which events deserve notifications and which are internal-only.

| Event | Customer Notification | Admin Notification | Status | Notes |
|-------|---------------------|-------------------|---------|-------|
| Order Created | ✅ YES | ✅ YES | ✅ Implemented | Both notified |
| Payment Pending | ❌ NO | ❌ NO | ✅ Correct | No notification needed |
| Payment Success | ✅ YES | ⚠️ SHOULD | ⚠️ Partial | Admin not notified |
| Payment Failed | ✅ YES | ⚠️ SHOULD | ⚠️ Partial | Admin should be notified |
| Order Status Changed | ✅ YES | ❌ NO | ⚠️ Partial | Admin may want notifications for specific statuses |
| Order Cancelled | ✅ YES | ⚠️ SHOULD | ⚠️ Partial | Admin should be notified |
| Order Delivered | ✅ YES | ❌ NO | ✅ Implemented | Customer notification sufficient |
| Shipment Dispatched | ❌ MISSING | ❌ MISSING | ❌ Gap | Both should be notified |
| Out for Delivery | ❌ MISSING | ❌ MISSING | ❌ Gap | Customer should be notified |
| Delivery Failed | ❌ MISSING | ❌ MISSING | ❌ Gap | Both should be notified |
| Inventory Reserved | ❌ NO | ❌ NO | ✅ Correct | Internal only |
| Inventory Committed | ❌ NO | ❌ NO | ✅ Correct | Internal only |

**Proposed Solution:**
1. Add shipment lifecycle notifications
2. Add admin notifications for payment failures and cancellations
3. Document notification matrix clearly

---

### B.4: Duplicate Prevention

**Requirement:** Prevent duplicate notifications from retries, callbacks, or duplicate events.

| Aspect | Status | Implementation | Gap | Acceptance Criteria |
|--------|--------|----------------|-----|-------------------|
| Idempotency check | ❌ MISSING | No deduplication logic | ❌ Critical gap (BUG-C003) | Notifications sent once per event |
| Job retry handling | ❌ MISSING | Queue retries may duplicate | ❌ Critical gap | Retries don't create duplicates |
| Event replay protection | ❌ MISSING | No protection | ❌ Critical gap | Event replay doesn't duplicate notifications |

**Affected Files:**
- All listener classes in `app/Listeners/*`

**Proposed Solution:**
Implement as described in BUG-C003:
1. Check `order_notifications` table before sending
2. Create record with status 'pending' before sending
3. Update to 'sent'/'failed' after attempt
4. Skip if already exists with status != 'failed'

---

### B.5: Failure Handling

**Requirement:** Notification failures must not corrupt order/payment/inventory state.

| Aspect | Status | Implementation | Gap | Acceptance Criteria |
|--------|--------|----------------|-----|-------------------|
| Transactional events | ✅ IMPLEMENTED | Events use `ShouldDispatchAfterCommit` | None | Events fire after DB commit |
| Listener independence | ✅ IMPLEMENTED | Listener failures don't rollback order state | None | Order state protected |
| Failure tracking | ⚠️ PARTIAL | `order_notifications` table tracks failures | ⚠️ No recovery workflow | Failed notifications logged |
| Retry mechanism | ⚠️ PARTIAL | Queue retry policy exists | ⚠️ No DLQ or manual retry | Failed notifications can be retried |

**Affected Files:**
- `app/Events/*` (ShouldDispatchAfterCommit implemented)
- Queue configuration

**Proposed Solution:**
1. Add dead letter queue monitoring
2. Add admin UI to view and manually retry failed notifications

---

### B.6: Authorization & Channel Security

**Requirement:** Verify authentication, authorization, user-channel isolation, tenant/store isolation.

| Aspect | Status | Implementation | Gap | Acceptance Criteria |
|--------|--------|----------------|-----|-------------------|
| Private channel auth | ✅ IMPLEMENTED | `routes/channels.php`<br>Channel: `user.{userId}.orders`<br>Authorization: Only user can access own channel | None | Users cannot listen to others' channels |
| Order channel auth | ✅ IMPLEMENTED | Channel: `order.{orderId}`<br>Authorization: User must own order | None | Order channels properly secured |
| Multi-tenancy | ⚠️ NOT APPLICABLE | Single-tenant system (no stores/vendors in customer flow) | None | N/A for customer notifications |

**Affected Files:**
- `routes/channels.php`
- Pusher configuration

**Status:** ✅ **PROPERLY SECURED**

---

### B.7: Read/Unread & Pagination

**Requirement:** Determine how notification read/unread status and pagination work.

| Aspect | Status | Implementation | Gap | Acceptance Criteria |
|--------|--------|----------------|-----|-------------------|
| Mark as read | ✅ IMPLEMENTED | Standard Laravel notification `markAsRead()` | None | Notifications can be marked read |
| Read timestamp | ✅ IMPLEMENTED | `read_at` column in notifications table | None | Read time recorded |
| Pagination | ✅ IMPLEMENTED | History endpoint supports pagination | None | Paginated notification list |
| Unread count | ⚠️ NEEDS VERIFICATION | Standard query: `whereNull('read_at')->count()` | ⚠️ Verify endpoint exists | Unread count available |

**Proposed Solution:**
Add `GET /api/v1/user/notifications/unread-count` if missing.

---

### B.8: Retention & Cleanup

**Requirement:** Review whether notification records are deleted, retained, or cleaned up.

| Aspect | Status | Implementation | Gap | Acceptance Criteria |
|--------|--------|----------------|-----|-------------------|
| Retention policy | ❌ UNDEFINED | No cleanup mechanism found | ❌ Missing | Policy defined and implemented |
| Soft deletes | ❌ NOT IMPLEMENTED | Hard deletes (if any) | ❌ Missing | Notifications preserved or soft-deleted |
| Archive strategy | ❌ UNDEFINED | No archival | ❌ Missing | Old notifications archived |

**Proposed Solution:**
1. Define retention policy (e.g., keep 1 year)
2. Implement scheduled cleanup job
3. Consider archival for compliance

---

## REQUIREMENT C: ADMIN ORDER MANAGEMENT & FILTERS

### C.1: Search and Filter Orders

**Requirement:** Administrators can search, filter, and manage orders.

| Filter Type | Status | Implementation | Gap | Acceptance Criteria |
|-------------|--------|----------------|-----|-------------------|
| Order status | ⚠️ PARTIAL | Basic filtering exists | ⚠️ Needs enhancement | Filter by any order status |
| Payment status | ❌ MISSING | No dedicated filter | ❌ Missing (BUG-M002) | Filter by payment status |
| Payment method | ❌ MISSING | No dedicated filter | ❌ Missing | Filter by COD/online/cashier |
| Shipment status | ❌ MISSING | No shipment filter | ❌ Missing | Filter by shipment status |
| Delivery method | ❌ MISSING | No fulfillment filter | ❌ Missing | Filter by delivery/pickup |
| Store/tenant | ⚠️ NOT APPLICABLE | Single-tenant for customers | N/A | N/A in customer context |
| Creation date range | ❌ MISSING | No date filter | ❌ Missing (BUG-M002) | Filter by created_at range |
| Paid date range | ❌ MISSING | No date filter | ❌ Missing | Filter by paid_at range |
| Delivered date range | ❌ MISSING | No date filter | ❌ Missing | Filter by delivered_at range |
| Shipped date range | ❌ MISSING | No shipment date filter | ❌ Missing | Filter by shipped_at range |
| Customer search | ⚠️ PARTIAL | Basic search exists | ⚠️ Needs enhancement | Search by name/email/phone |
| Order number search | ✅ IMPLEMENTED | Tracking number search works | None | Search by order number |
| Governorate filter | ❌ MISSING | No region filter | ❌ Missing | Filter by delivery region |

**Affected Files:**
- `app/Http/Controllers/Api/Admin/AdminOrderTrackingController.php`
- Need enhancement in `listOrders()` method

**Proposed Solution:**
See BUG-M002 for detailed filter implementation plan.

---

### C.2: Sorting and Pagination

**Requirement:** Admin order list supports sorting and pagination.

| Aspect | Status | Implementation | Gap | Acceptance Criteria |
|--------|--------|----------------|-----|-------------------|
| Pagination | ✅ IMPLEMENTED | Cursor pagination support exists | None | Orders paginated properly |
| Sort by created_at | ✅ IMPLEMENTED | Default sort order | None | Can sort by creation date |
| Sort by total | ❌ MISSING | No sort option | ⚠️ Nice to have | Can sort by order total |
| Sort by status | ❌ MISSING | No sort option | ⚠️ Nice to have | Can sort by status |

**Proposed Solution:**
Add sort parameter support to admin list endpoint.

---

### C.3: Export Functionality

**Requirement:** Export filtered order results.

| Aspect | Status | Implementation | Gap | Acceptance Criteria |
|--------|--------|----------------|-----|-------------------|
| Export endpoint | ⚠️ PARTIAL | Analytics export exists<br>`POST /api/v1/admin/analytics/export/orders` | ⚠️ Verify filtering support | Can export filtered orders |
| Export format | ⚠️ NEEDS VERIFICATION | Likely CSV/Excel | ⚠️ Verify | Exports in usable format |
| Filter preservation | ⚠️ NEEDS VERIFICATION | Unknown | ⚠️ Verify | Export respects filters |

**Affected Files:**
- `app/Http/Controllers/Api/Admin/AnalyticsExportController.php`

**Proposed Solution:**
Verify and enhance export functionality to respect all filters.

---

### C.4: Date Filter Semantics

**Requirement:** Define clear date-filter semantics, timezone handling, inclusive/exclusive boundaries.

| Aspect | Status | Implementation | Gap | Acceptance Criteria |
|--------|--------|----------------|-----|-------------------|
| Date format | ❌ UNDEFINED | No documentation | ❌ Missing | ISO 8601 format defined |
| Timezone handling | ❌ UNDEFINED | Application TZ used (likely) | ❌ Missing | UTC or user TZ clearly defined |
| Inclusive/exclusive | ❌ UNDEFINED | No specification | ❌ Missing | Date range boundaries documented |
| Validation | ❌ MISSING | No date validation | ❌ Missing | Invalid dates rejected |

**Proposed Solution:**
1. Accept ISO 8601 dates (YYYY-MM-DD)
2. Convert to UTC for queries
3. Use inclusive start, exclusive end (standard practice)
4. Add validation rules

---

### C.5: Tenant/Store Isolation

**Requirement:** Ensure filters don't leak data across stores or tenants.

| Aspect | Status | Implementation | Gap | Acceptance Criteria |
|--------|--------|----------------|-----|-------------------|
| Single-tenant verification | ✅ VERIFIED | No multi-tenancy in customer orders | N/A | N/A for this context |
| Permission enforcement | ✅ IMPLEMENTED | Admin permissions required | None | Non-admins cannot access admin endpoints |

**Status:** ✅ **NOT APPLICABLE** (single-tenant system for customer orders)

---

## REQUIREMENT D: DELIVERY FEES BASED ON REGION

### D.1: Configurable by Admin

**Requirement:** Delivery fees must be configurable by authorized administrator.

| Aspect | Status | Implementation | Gap | Acceptance Criteria |
|--------|--------|----------------|-----|-------------------|
| ShippingPrice model | ✅ IMPLEMENTED | Model exists with CRUD | None | Model supports fee configuration |
| Admin API endpoints | ⚠️ NEEDS VERIFICATION | Marvel package has ShippingPriceController | ⚠️ Verify routes registered | Admin can create/update shipping prices |
| Governorate association | ✅ IMPLEMENTED | FK to governorates table | None | Fees linked to governorates |
| Price configuration | ✅ IMPLEMENTED | `price` field (decimal) | None | Admin can set price |
| Estimated days | ✅ IMPLEMENTED | `estimated_days` field | None | Admin can set ETA |
| Free shipping threshold | ✅ IMPLEMENTED | `free_shipping_over` field | None | Admin can set free shipping minimum |
| Status toggle | ✅ IMPLEMENTED | `status` boolean field | None | Admin can enable/disable |

**Affected Files:**
- `packages/marvel/src/Database/Models/ShippingPrice.php`
- `packages/marvel/src/Http/Controllers/ShippingPriceController.php`
- Need to verify routes are registered

**Proposed Solution:**
Verify admin routes for shipping price management are accessible.

---

### D.2: Store/Market/Geographic Association

**Requirement:** Fees associated with appropriate store/market and geographic area.

| Aspect | Status | Implementation | Gap | Acceptance Criteria |
|--------|--------|----------------|-----|-------------------|
| Geographic model | ✅ IMPLEMENTED | Governorates table (Egyptian regions) | None | Geographic areas defined |
| One price per governorate | ✅ IMPLEMENTED | ShippingPrice hasOne relationship | None | Each governorate has one price |
| Country support | ⚠️ PARTIAL | Countries table exists<br>Governorates belong to country | ⚠️ Multi-country unclear | System supports multiple countries |
| Multi-zone within governorate | ❌ NOT SUPPORTED | One price per governorate only | ⚠️ Limitation | No sub-governorate zones |

**Affected Files:**
- `packages/marvel/src/Database/Models/Governorate.php`
- `packages/marvel/src/Database/Models/Country.php`
- `packages/marvel/src/Database/Models/ShippingPrice.php`

**Status:** ✅ **ADEQUATE** (single price per governorate sufficient for MVP)

---

### D.3: Region/Zone/Address Mapping

**Requirement:** Customer's address maps to delivery pricing area.

| Aspect | Status | Implementation | Gap | Acceptance Criteria |
|--------|--------|----------------|-----|-------------------|
| Address structure | ✅ IMPLEMENTED | Order has `governorate_id` + `address` JSON | None | Address includes governorate |
| Governorate selection | ✅ IMPLEMENTED | User selects governorate during checkout | None | Customer selects delivery area |
| Price lookup | ⚠️ PARTIAL | `OrderService::resolveShippingPrice()` exists | ⚠️ Integration unclear (BUG-M001) | Price retrieved by governorate_id |
| Validation | ⚠️ NEEDS VERIFICATION | Unknown if governorate validated | ⚠️ Verify | Invalid governorate rejected |

**Affected Files:**
- `app/Services/General/OrderService.php:406-430`
- Checkout flow

**Proposed Solution:**
Complete integration audit per BUG-M001.

---

### D.4: Unmapped Address Fallback

**Requirement:** Define behavior when address doesn't match configured delivery area.

| Aspect | Status | Implementation | Gap | Acceptance Criteria |
|--------|--------|----------------|-----|-------------------|
| Fallback strategy | ❌ UNDEFINED | No documented fallback | ❌ Missing (Business Decision) | Clear fallback defined |
| Error handling | ⚠️ NEEDS VERIFICATION | Unknown | ⚠️ Needs verification | Unmapped governorate handled gracefully |
| Default price | ❌ NOT IMPLEMENTED | No default price | ❌ Missing | System has default price option |

**Proposed Solution:**
**Business Decision Required:**
- Option A: Block checkout, require admin to configure price first
- Option B: Use system-wide default price
- Option C: Use nearest governorate price
- **Recommendation:** Option A (fail-fast, ensures data quality)

---

### D.5: Delivery Methods Support

**Requirement:** Support applicable delivery methods (store delivery, external carrier).

| Aspect | Status | Implementation | Gap | Acceptance Criteria |
|--------|--------|----------------|-----|-------------------|
| Delivery method types | ✅ IMPLEMENTED | `fulfillment_type`: delivery/pickup<br>`shipping_method`: SCHEDULED/FAST | None | Multiple methods supported |
| Method-specific pricing | ⚠️ PARTIAL | Fast shipping has separate fee | ⚠️ No carrier-specific pricing | Pricing varies by method |
| Pickup locations | ✅ IMPLEMENTED | Pickup location model and selection | None | Pickup supported |

**Affected Files:**
- Order model fields
- Checkout flow

**Status:** ✅ **ADEQUATE** for current requirements

---

### D.6: Pricing Correctness

#### D.6.1: When Calculated

**Requirement:** Define when delivery fee is calculated.

| Aspect | Status | Implementation | Gap | Acceptance Criteria |
|--------|--------|----------------|-----|-------------------|
| Cart stage | ⚠️ PARTIAL | CheckoutRepository calculates shipping | ⚠️ Uses legacy Shipping class | Fee calculated during cart review |
| Order creation | ✅ IMPLEMENTED | Shipping price persisted to order | None | Fee calculated and stored |
| Real-time preview | ❌ MISSING | No live shipping calculator endpoint | ⚠️ Nice to have | Customer sees fee before checkout |

**Proposed Solution:**
1. Migrate checkout flow to use ShippingPrice model (not legacy Shipping class)
2. Add `/api/v1/general/shipping-calculator` endpoint for live preview

---

#### D.6.2: When Persisted

**Requirement:** Define when delivery fee is persisted.

| Aspect | Status | Implementation | Gap | Acceptance Criteria |
|--------|--------|----------------|-----|-------------------|
| Order snapshot | ✅ IMPLEMENTED | `order.shipping_price` field | None | Fee saved to order at creation |
| Currency snapshot | ✅ IMPLEMENTED | Multi-currency support with conversion | None | Fee in correct currency |

**Status:** ✅ **WELL IMPLEMENTED**

---

#### D.6.3: Included in Total

**Requirement:** Delivery fee included in order total.

| Aspect | Status | Implementation | Gap | Acceptance Criteria |
|--------|--------|----------------|-----|-------------------|
| Total calculation | ✅ IMPLEMENTED | `order.total_price` includes shipping | None | Total = items + shipping + tax |
| Breakdown visible | ✅ IMPLEMENTED | Shipping price separate field | None | Customer sees breakdown |

**Status:** ✅ **IMPLEMENTED**

---

#### D.6.4: Address Change Recalculation

**Requirement:** Whether fee recalculated if address changes.

| Aspect | Status | Implementation | Gap | Acceptance Criteria |
|--------|--------|----------------|-----|-------------------|
| Order modification | ⚠️ UNDEFINED | Order update flow unclear | ⚠️ Need to verify | Behavior defined and implemented |
| Address change after payment | ❌ LIKELY NOT ALLOWED | Payment commits order | ⚠️ Verify | Address change blocked after payment |

**Status:** ⚠️ **NEEDS VERIFICATION** (likely not a concern - orders don't allow address changes after creation)

---

#### D.6.5: Coupons/Promotions/Taxes/Discounts

**Requirement:** Whether fee affected by coupons, promotions, taxes, discounts.

| Aspect | Status | Implementation | Gap | Acceptance Criteria |
|--------|--------|----------------|-----|-------------------|
| Free shipping coupons | ✅ IMPLEMENTED | Coupon `discount_type` includes `free_shipping` | None | Coupon can waive shipping |
| Free shipping threshold | ✅ IMPLEMENTED | `shipping_prices.free_shipping_over` | None | Orders over amount get free shipping |
| Promotions | ⚠️ NEEDS VERIFICATION | Promotion system exists | ⚠️ Verify interaction | Promotions can affect shipping |
| Tax on shipping | ⚠️ NEEDS VERIFICATION | Tax calculation exists | ⚠️ Verify if shipping taxed | Tax handling documented |

**Status:** ✅ **MOSTLY IMPLEMENTED** (verify tax on shipping)

---

#### D.6.6: Historical Snapshot

**Requirement:** Historical orders preserve original delivery fee.

| Aspect | Status | Implementation | Gap | Acceptance Criteria |
|--------|--------|----------------|-----|-------------------|
| Fee persistence | ✅ IMPLEMENTED | `order.shipping_price` never changes | None | Fee preserved on order record |
| Audit trail | ✅ IMPLEMENTED | Order status history exists | None | Fee changes logged (if allowed) |

**Status:** ✅ **IMPLEMENTED**

---

#### D.6.7: Post-Confirmation Changes

**Requirement:** Whether delivery fee can change after order confirmation.

| Aspect | Status | Implementation | Gap | Acceptance Criteria |
|--------|--------|----------------|-----|-------------------|
| Change policy | ⚠️ UNDEFINED | Unclear if admin can modify | ⚠️ Business decision needed | Policy defined |
| Implementation | ❌ LIKELY NO | No admin fee override found | ⚠️ Verify | Admin override exists (if needed) |

**Proposed Solution:**
**Business Decision Required:** Should admin be able to modify shipping fee post-order?
- **Recommendation:** NO - preserve integrity, issue refund if needed

---

#### D.6.8: Currency & Rounding

**Requirement:** How currency conversion and rounding handled.

| Aspect | Status | Implementation | Gap | Acceptance Criteria |
|--------|--------|----------------|-----|-------------------|
| Multi-currency | ✅ IMPLEMENTED | Currency snapshot system exists | None | Shipping price converted correctly |
| Rounding | ✅ IMPLEMENTED | Decimal(10,3) fields for precision | None | Proper rounding applied |
| Currency snapshot | ✅ IMPLEMENTED | Order stores currency_rate, currency_rate_date | None | Historical rate preserved |

**Affected Files:**
- `app/Services/Checkout/OrderCreationService.php` (currency handling)
- Order model (currency fields)

**Status:** ✅ **WELL IMPLEMENTED**

---

#### D.6.9: Multi-Store/Market Configuration

**Requirement:** Different stores/markets can configure independent delivery fees.

| Aspect | Status | Implementation | Gap | Acceptance Criteria |
|--------|--------|----------------|-----|-------------------|
| Single-tenant system | ✅ VERIFIED | No multi-store for customer orders | N/A | N/A in this context |

**Status:** N/A (single-tenant for customer-facing orders)

---

## REQUIREMENT E: SHIPPING AND DELIVERY METHODS

### E.1: Store-Managed Delivery Support

**Requirement:** Platform supports store-managed delivery.

| Aspect | Status | Implementation | Gap | Acceptance Criteria |
|--------|--------|----------------|-----|-------------------|
| Delivery fulfillment type | ✅ IMPLEMENTED | `fulfillment_type = 'delivery'` | None | Orders can be delivery type |
| Shipment tracking | ⚠️ PARTIAL | Shipment model exists | ⚠️ Manual process (BUG-H002) | Store can track own deliveries |
| Manual status updates | ⚠️ PARTIAL | ShipmentService exists | ⚠️ Admin workflow unclear | Staff can update shipment status |

**Status:** ⚠️ **PARTIAL** - needs admin workflow

---

### E.2: External Carrier Delivery Support

**Requirement:** Platform supports external shipping carrier delivery.

| Aspect | Status | Implementation | Gap | Acceptance Criteria |
|--------|--------|----------------|-----|-------------------|
| Carrier field | ✅ IMPLEMENTED | `shipment.courier` field | None | Can specify carrier |
| Tracking number | ✅ IMPLEMENTED | `shipment.tracking_number` field | None | Can store tracking number |
| Carrier webhook support | ❌ NOT IMPLEMENTED | No webhook handlers | ⚠️ Future enhancement | Webhook framework exists |

**Status:** ⚠️ **BASIC SUPPORT** (manual carrier tracking only)

---

### E.3: Delivery Method Selection

**Requirement:** Delivery method depends on order/store/business rules.

| Aspect | Status | Implementation | Gap | Acceptance Criteria |
|--------|--------|----------------|-----|-------------------|
| Customer selection | ✅ IMPLEMENTED | Customer chooses delivery/pickup | None | Customer can select |
| Shipping speed | ✅ IMPLEMENTED | SCHEDULED vs FAST shipping | None | Express option available |
| Business rules | ⚠️ UNCLEAR | No documented rules | ⚠️ Verify | Rules enforced |

**Status:** ✅ **ADEQUATE**

---

### E.4: One Order → One Shipment

**Requirement:** Current cardinality is one order → one shipment.

| Aspect | Status | Implementation | Gap | Acceptance Criteria |
|--------|--------|----------------|-----|-------------------|
| Relationship | ✅ IMPLEMENTED | Order hasOne Shipment | None | Relationship defined |
| Database constraint | ❌ MISSING | No UNIQUE constraint | ❌ High priority (BUG-H003) | DB enforces constraint |
| Application logic | ✅ IMPLEMENTED | Code assumes one shipment | None | Application respects rule |

**Proposed Solution:**
Add `UNIQUE (order_id)` constraint to shipments table (BUG-H003).

---

### E.5: Manual Tracking Updates

**Requirement:** Start with manual tracking updates, design extension point for carrier integrations.

| Aspect | Status | Implementation | Gap | Acceptance Criteria |
|--------|--------|----------------|-----|-------------------|
| Manual update API | ⚠️ PARTIAL | ShipmentService::updateStatus() exists | ⚠️ Admin route unclear | Admin can update status |
| Tracking history | ❌ MISSING | No tracking events table | ❌ Critical (BUG-C001) | History recorded |
| Admin UI workflow | ❌ MISSING | No documented workflow | ❌ Missing | Clear admin process |

**Proposed Solution:**
1. Create tracking events table (BUG-C001)
2. Document admin workflow
3. Add admin API endpoint for manual status updates

---

### E.6: Future Carrier Integration

**Requirement:** Design extension point for carrier integrations.

| Aspect | Status | Implementation | Gap | Acceptance Criteria |
|--------|--------|----------------|-----|-------------------|
| Carrier adapter pattern | ❌ NOT IMPLEMENTED | No adapter architecture | ⚠️ Future work | Adapter pattern documented |
| Webhook framework | ❌ NOT IMPLEMENTED | No webhook handlers | ⚠️ Future work | Framework exists |
| Event ID tracking | ❌ NOT IMPLEMENTED | No carrier_event_id field | ⚠️ Future work | Idempotency support |
| Status mapping | ❌ NOT IMPLEMENTED | No carrier status mapping | ⚠️ Future work | Carrier statuses mapped |

**Proposed Solution:**
Design carrier adapter interface:
```php
interface CarrierAdapter {
    public function createShipment(Order $order): CarrierShipment;
    public function trackShipment(string $trackingNumber): CarrierTrackingInfo;
    public function handleWebhook(Request $request): CarrierWebhookEvent;
    public function mapStatus(string $carrierStatus): string;
}
```

**Status:** ⚠️ **NOT REQUIRED FOR MVP** (documented for future)

---

## REQUIREMENT F: CORRECT THE EXISTING ORDER LIFECYCLE

### F.1: Checkout and Order Creation

**Requirement:** Audit and correct checkout flow.

| Aspect | Status | Bugs Found | Severity | Files |
|--------|--------|-----------|----------|-------|
| Cart validation | ✅ VERIFIED | None | - | CheckoutController, CartInventoryService |
| Order creation | ✅ VERIFIED | None | - | OrderCreationService |
| Inventory reservation | ✅ VERIFIED | None | - | OrderReservationService |
| Price calculation | ⚠️ NEEDS WORK | BUG-M001 (shipping) | Medium | OrderService, CheckoutRepository |
| Tax calculation | ✅ VERIFIED | None | - | OrderService::withTaxes() |
| Transaction safety | ✅ VERIFIED | None | - | DB transactions used correctly |

**Status:** ✅ **MOSTLY CORRECT** (shipping price integration needs completion)

---

### F.2: Payment Initiation and Confirmation

**Requirement:** Audit payment flow.

| Aspect | Status | Bugs Found | Severity | Files |
|--------|--------|-----------|----------|-------|
| Online payment | ✅ VERIFIED | None | - | PaymentCheckoutHandler |
| Transaction creation | ✅ VERIFIED | None | - | Transaction model |
| Gateway integration | ✅ VERIFIED | None | - | PaymentGatewayFactory |
| Callback handling | ⚠️ NEEDS WORK | BUG-C002 (idempotency) | Critical | OrderController::checkoutCallback() |
| Amount validation | ✅ VERIFIED | None | - | Callback validates amount/currency |

**Status:** ⚠️ **MOSTLY CORRECT** (add webhook idempotency per BUG-C002)

---

### F.3: Payment Gateway Callbacks and Retries

**Requirement:** Handle callbacks correctly including retries.

| Aspect | Status | Bugs Found | Severity | Files |
|--------|--------|-----------|----------|-------|
| Lock-based protection | ✅ IMPLEMENTED | None | - | DB transaction + lockForUpdate |
| Status check | ✅ IMPLEMENTED | None | - | Checks order.status != 'pending' |
| Retry protection | ⚠️ WEAK | BUG-C002 | Critical | No unique event ID tracking |
| Amount mismatch handling | ✅ IMPLEMENTED | None | - | Blocks order on mismatch |
| Test gateway handling | ✅ IMPLEMENTED | None | - | Relaxes validation in test mode |

**Status:** ⚠️ **NEEDS STRENGTHENING** (BUG-C002)

---

### F.4: COD and Pay at Cashier Flows

**Requirement:** Audit non-online payment flows.

| Aspect | Status | Bugs Found | Severity | Files |
|--------|--------|-----------|----------|-------|
| COD order creation | ✅ VERIFIED | None | - | PaymentCheckoutHandler |
| COD confirmation | ✅ VERIFIED | None | - | OrderService::markCodAsPaid() |
| Cashier QR generation | ✅ VERIFIED | None | - | PaymentCheckoutHandler |
| Cashier confirmation | ✅ VERIFIED | None | - | OrderService::markCashierPaid() |
| Inventory handling | ✅ VERIFIED | None | - | Committed on confirmation |

**Status:** ✅ **CORRECT**

---

### F.5: Order Status Transitions

**Requirement:** Identify actual status sequence and validate transitions.

| Aspect | Status | Bugs Found | Severity | Files |
|--------|--------|-----------|----------|-------|
| State machine | ⚠️ AMBIGUOUS | BUG-H001 | High | No clear state machine documented |
| Validation | ⚠️ PARTIAL | Validation exists in OrderService | Medium | canTransitionOrderStatus() exists |
| Status semantics | ⚠️ UNCLEAR | BUG-H001 | High | completed vs delivered confusion |

**Status:** ⚠️ **NEEDS CLARIFICATION** (BUG-H001)

---

### F.6: Inventory Reservation, Commitment, Release

**Requirement:** Verify inventory state management.

| Aspect | Status | Bugs Found | Severity | Files |
|--------|--------|-----------|----------|-------|
| Reservation on order | ✅ VERIFIED | None | - | OrderReservationService::reserveForOrder() |
| Commitment on payment | ✅ VERIFIED | None | - | OrderReservationService::commit() |
| Release on cancel | ✅ VERIFIED | None | - | OrderReservationService::release() |
| Transaction safety | ✅ VERIFIED | None | - | Uses DB locks |
| Idempotency | ✅ VERIFIED | None | - | State checks prevent double-processing |

**Status:** ✅ **EXCELLENT** (well-designed and secure)

---

### F.7: Order Cancellation

**Requirement:** Verify cancellation flow.

| Aspect | Status | Bugs Found | Severity | Files |
|--------|--------|-----------|----------|-------|
| Cancel logic | ✅ VERIFIED | None | - | OrderStatusManagerWithPaymentTrait |
| Inventory release | ✅ VERIFIED | None | - | Released on cancel |
| Refund calculation | ✅ VERIFIED | None | - | Cancelled amount tracked |
| Event firing | ✅ VERIFIED | None | - | OrderCancelled event |

**Status:** ✅ **CORRECT**

---

### F.8: Failed and Expired Payments

**Requirement:** Handle payment failures and expiration.

| Aspect | Status | Bugs Found | Severity | Files |
|--------|--------|-----------|----------|-------|
| Payment failure event | ✅ VERIFIED | None | - | PaymentFailed event exists |
| Transaction status | ✅ VERIFIED | None | - | Marked as failed |
| Order status | ⚠️ UNCLEAR | Unclear if order auto-cancelled | Medium | Needs verification |
| Inventory timeout | ⚠️ PARTIAL | Timeout set but cleanup unclear | Medium | reservation_expires_at exists |

**Proposed Solution:**
1. Document payment failure behavior
2. Verify inventory release on payment expiration
3. Consider scheduled job to auto-cancel expired reservations

---

### F.9: Refunds and Returns

**Requirement:** Verify refund handling (where supported).

| Aspect | Status | Implementation | Gap | Notes |
|--------|--------|----------------|-----|-------|
| Refund status | ✅ EXISTS | `order.status = 'refunded'` | ⚠️ Flow unclear | Refund status defined but flow unclear |
| Return support | ❌ NOT IMPLEMENTED | No return model found | ⚠️ Out of scope? | Likely not implemented |

**Status:** ⚠️ **UNDEFINED** (likely out of scope for MVP)

---

### F.10: Fulfillment and Shipping

**Requirement:** Verify fulfillment initiation.

| Aspect | Status | Bugs Found | Severity | Files |
|--------|--------|-----------|----------|-------|
| Shipment creation | ❌ MANUAL | BUG-H002 | High | Not automated |
| Fulfillment trigger | ⚠️ UNCLEAR | No clear trigger point | High | Needs definition |
| Status update | ⚠️ PARTIAL | Can update manually | Medium | No workflow documented |

**Status:** ⚠️ **NEEDS IMPLEMENTATION** (BUG-H002)

---

### F.11: Delivery Completion

**Requirement:** Verify delivery confirmation flow.

| Aspect | Status | Implementation | Gap | Files |
|--------|--------|-----------|----------|-------|
| COD delivery | ✅ IMPLEMENTED | markCodAsPaid() | None | Works correctly |
| Online payment delivery | ⚠️ UNCLEAR | Order auto-completed on payment? | ⚠️ Verify | Delivery != payment |
| Delivery confirmation | ❌ MISSING | No separate delivery confirmation | ⚠️ Business decision | Should delivery be confirmed separately? |

**Proposed Solution:**
**Business Decision:** Should online orders require explicit delivery confirmation?
- Current: Order marked "completed" on payment success
- Recommendation: Use shipment.status = 'delivered' as delivery confirmation

---

### F.12: Notifications and Queued Jobs

**Requirement:** Verify notification and job processing.

| Aspect | Status | Bugs Found | Severity | Files |
|--------|--------|-----------|----------|-------|
| Event dispatch | ✅ VERIFIED | None | - | ShouldDispatchAfterCommit used |
| Queue processing | ✅ VERIFIED | None | - | Listeners properly queued |
| Deduplication | ❌ MISSING | BUG-C003 | Critical | No deduplication |
| Failure handling | ⚠️ PARTIAL | Queue retries exist | Medium | No DLQ or recovery UI |

**Status:** ⚠️ **MOSTLY CORRECT** (add deduplication per BUG-C003)

---

### F.13: Customer and Admin Order APIs

**Requirement:** Verify API correctness and authorization.

| Aspect | Status | Bugs Found | Severity | Files |
|--------|--------|-----------|----------|-------|
| Customer list orders | ✅ VERIFIED | None | - | Properly scoped to user |
| Customer view order | ✅ VERIFIED | None | - | Authorization enforced |
| Admin list orders | ✅ VERIFIED | None | - | Permission-based |
| Admin update order | ✅ VERIFIED | None | - | Permission-based |
| Public tracking | ✅ VERIFIED | None | - | Verified by email/phone |

**Status:** ✅ **CORRECT**

---

## SUMMARY: REQUIREMENTS GAP ANALYSIS

| Requirement Area | Implementation % | Critical Gaps | High Gaps | Medium Gaps |
|-----------------|------------------|---------------|-----------|-------------|
| A. Customer Order Tracking | 70% | BUG-C001 (tracking history) | BUG-H002 (shipment creation) | BUG-M003 (public tracking) |
| B. Notifications | 75% | BUG-C003 (deduplication) | - | BUG-M004 (failure retry) |
| C. Admin Management | 40% | - | - | BUG-M002 (filters) |
| D. Delivery Pricing | 80% | - | - | BUG-M001 (integration) |
| E. Shipping Methods | 60% | BUG-C001 (tracking history) | BUG-H002, BUG-H003 | - |
| F. Order Lifecycle Correctness | 85% | BUG-C002 (payment idempotency) | BUG-H001 (status clarity) | - |
| **OVERALL** | **68%** | **3** | **3** | **4** |

---

**NEXT DELIVERABLE:** Proposed target architecture and implementation phases.

**END OF REQUIREMENTS TRACEABILITY MATRIX**
