# BUG AND INCONSISTENCY REGISTER
## Order Lifecycle, Tracking, Shipping, Notifications & Delivery Pricing

**Date:** 2026-09-20  
**Repository:** D:\work\meem

---

## SEVERITY CLASSIFICATION

- **CRITICAL:** Data loss, security vulnerability, payment integrity, inventory corruption
- **HIGH:** Incorrect business logic, state inconsistency, missing essential feature
- **MEDIUM:** Suboptimal implementation, missing validation, usability issue
- **LOW:** Code quality, optimization opportunity, minor enhancement

---

## CRITICAL SEVERITY

### BUG-C001: No Shipment Tracking Event History

**Evidence:**
- File: `database/migrations/2026_07_28_000004_create_shipments_table.php`
- Shipments table exists with status field
- No corresponding tracking events/history table found
- File: `app/Models/Shipment.php` - Model has `canTransitionTo()` method but no tracking history relation

**Current Behavior:**
- Shipment status stored as single field (`status`)
- Status updates overwrite previous value
- No historical record of status changes
- Cannot reconstruct shipment timeline
- Cannot track who made changes or when

**Business Impact:**
- **CRITICAL:** No audit trail for shipment status changes
- Customer disputes cannot be resolved with evidence
- Compliance issues (delivery proof requirements)
- Cannot identify bottlenecks in delivery process
- Admin accountability lost

**Proposed Correction:**
1. Create `shipment_tracking_events` table with:
   - shipment_id (FK)
   - status (snapshot at time of event)
   - location, notes
   - created_by, created_by_type
   - carrier_event_id (for webhook idempotency)
   - carrier_raw_data (JSON)
   - event_timestamp
   - UNIQUE constraint on (shipment_id, carrier_event_id)

2. Update ShipmentService to record events on every status change

3. Add `trackingEvents()` relation to Shipment model

**Regression Risk:** Low (new feature, no existing dependencies)

---

### BUG-C002: Payment Callback Lacks Carrier-Style Idempotency Protection

**Evidence:**
- File: `app/Http/Controllers/Api/General/OrderController.php:169-478`
- Method: `checkoutCallback()`
- Uses transaction lock for payment verification ✅
- No unique event identifier tracking for retry protection

**Current Behavior:**
```php
// Lines 289-314: Lock-based idempotency
DB::transaction(function () use (...) {
    $lockedTransaction = Transaction::where('gateway_transaction_id', $paymentId)
        ->lockForUpdate()->first();
    
    if ($lockedOrder->status !== 'pending') {
        return; // Already processed
    }
    // Process payment...
});
```

**Issue:**
- Relies on order status check: `if ($lockedOrder->status !== 'pending')`
- Works for duplicate callbacks **after** order completion
- **VULNERABLE** to race condition if two callbacks arrive simultaneously
- Gateway retries with **different** paymentId could bypass check
- No carrier_event_id equivalent for webhook deduplication

**Business Impact:**
- **CRITICAL:** Potential double-processing of payments
- Inventory could be committed twice (mitigated by inventory_state check)
- Notifications could be duplicated
- Order status history could have duplicate entries

**Proposed Correction:**
1. Add `processed_webhook_ids` JSON column to transactions table
2. Before processing, check if `$paymentId` or gateway callback ID is in processed list
3. Atomic update: Add ID to list within same transaction
4. Alternative: Create `payment_webhook_events` table with UNIQUE constraint

**Regression Risk:** Medium (modifies critical payment path, requires extensive testing)

---

### BUG-C003: Notification Deduplication Missing

**Evidence:**
- File: `app/Listeners/SendUserOrderCreatedNotification.php`
- File: `app/Listeners/SendOrderStatusChangedNotification.php`
- Events use `ShouldQueue` without idempotency keys
- No deduplication check before sending notifications

**Current Behavior:**
```php
public function handle(OrderCreated $event): void
{
    $user = $event->order->user;
    if (!$user || $user->type !== UserType::USER->value) {
        return;
    }
    $user->notify(new UserOrderCreatedNotification($event->order));
    // No check if notification already sent
}
```

**Issue:**
- Queue job retries will resend notifications
- Event replay during debugging/recovery will duplicate
- Payment gateway retry storms trigger multiple OrderStatusChanged events
- No `order_notifications` check before sending

**Business Impact:**
- **CRITICAL:** Customer experience degradation (notification spam)
- SMS/push notification costs multiply
- Trust erosion ("system is broken, sending me duplicates")
- Support ticket volume increase

**Proposed Correction:**
1. Before `$user->notify()`, check `order_notifications` table:
   ```php
   $exists = \App\Models\OrderNotification::where([
       'order_id' => $event->order->id,
       'user_id' => $user->id,
       'event_type' => 'order_created',
       'channel' => 'email', // or push, sms
   ])->where('status', '!=', 'failed')->exists();
   
   if ($exists) return; // Already sent or sending
   ```

2. Create notification record **before** sending (status: pending)
3. Update to sent/failed after delivery attempt
4. Add unique constraint or use job idempotency keys

**Regression Risk:** Low (defensive addition, no breaking changes)

---

## HIGH SEVERITY

### BUG-H001: Order Status State Machine Ambiguity

**Evidence:**
- File: `packages/marvel/src/Database/Models/Order.php:18-22`
- Constants defined:
  ```php
  ORDER_STATUS_PENDING = 'pending'
  ORDER_STATUS_PROCESSING = 'processing'
  ORDER_STATUS_COMPLETED = 'completed'
  ORDER_STATUS_CANCELLED = 'cancelled'
  ORDER_STATUS_DELIVERED = 'delivered'
  ```
- File: `app/Services/General/OrderService.php:655-663`
- No clear state machine documented

**Current Behavior:**
- `completed` used when payment succeeds (PaymentTrait::webhookSuccessResponse)
- `delivered` used when COD marked as paid (OrderService::markCodAsPaid)
- `processing` recently added but not consistently used
- Unclear: Is `completed` = "paid" or "fulfilled and delivered"?

**Business Impact:**
- **HIGH:** Confusion in admin dashboard ("completed" orders not actually delivered)
- Metrics incorrect (completion rate vs delivery rate)
- Customer confusion (order shows "completed" but not received)
- Reporting inaccurate

**Proposed Correction:**
Define clear semantics:

**Option A (Recommended):**
- `pending` = Order created, payment/fulfillment pending
- `processing` = Payment confirmed, preparing for fulfillment
- `completed` = Order fulfilled and delivered to customer (FINAL)
- `cancelled` = Order cancelled (FINAL)

**Option B:**
- Keep `completed` = "paid and ready to ship"
- Use `fulfillment_status` field for delivery tracking
- Add `delivered` as final order status only for COD

**Regression Risk:** High (affects existing orders, requires data migration)

---

### BUG-H002: Shipment Creation Not Automated

**Evidence:**
- File: `app/Models/Shipment.php` - Model exists
- File: `app/Http/Controllers/Api/ShipmentController.php` - Manual CRUD
- No automatic shipment creation in order lifecycle
- No shipment record created on order fulfillment

**Current Behavior:**
- Shipments must be created manually via API
- Order can be "completed" without shipment record
- Tracking number assignment is manual
- No link between order status change and shipment creation

**Business Impact:**
- **HIGH:** Manual process prone to errors
- Orders slip through without tracking
- Customer cannot see shipment status
- Admin workload increased
- Inconsistent data (some orders have shipments, others don't)

**Proposed Correction:**
1. Create shipment automatically when:
   - **Option A:** Order payment confirmed (status → processing)
   - **Option B:** Admin explicitly triggers fulfillment
   - **Option C:** Order status changed to specific fulfillment status

2. Add to `OrderService::changeOrderStatus()`:
   ```php
   if ($newStatus === 'processing' && !$order->shipment) {
       $this->shipmentService->createForOrder($order);
   }
   ```

3. Populate shipment with:
   - Order delivery address
   - Calculated shipping cost
   - Estimated delivery date
   - Initial status: 'pending'

**Regression Risk:** Medium (new side effect in order status changes)

---

### BUG-H003: Missing One-Shipment-Per-Order Database Constraint

**Evidence:**
- File: `database/migrations/2026_07_28_000004_create_shipments_table.php:14`
- Line 14: `$table->foreignId('order_id')->constrained('orders')->restrictOnDelete();`
- No UNIQUE constraint on `order_id`

**Current Behavior:**
- Application code assumes one shipment per order
- Database allows multiple shipments per order
- Race condition: Two admins could create shipments simultaneously
- No enforcement of business rule at data layer

**Business Impact:**
- **HIGH:** Data integrity risk
- Ambiguity: Which shipment is "the" shipment for the order?
- Customer tracking confusion (multiple tracking numbers)
- Reporting errors (duplicate shipment records)

**Proposed Correction:**
```sql
ALTER TABLE shipments
ADD CONSTRAINT shipments_order_id_unique UNIQUE (order_id);
```

**Affected Files:**
- Migration file (add constraint)
- ShipmentService (handle unique constraint violation gracefully)

**Regression Risk:** Medium (requires checking existing data for violations before applying)

---

### BUG-H004: Payment Status Column Inconsistently Populated

**Evidence:**
- File: `packages/marvel/src/Database/Models/Order.php:99-115`
- `payment_status` column exists in orders table
- Accessor method falls back to transaction status if column null
- Not all flows populate the column

**Current Behavior:**
```php
public function getPaymentStatusAttribute(): ?string
{
    if (array_key_exists('payment_status', $this->attributes) 
        && $this->attributes['payment_status'] !== null) {
        return $this->attributes['payment_status'];
    }
    
    // Fallback: derive from transaction or order status
    $latestTransaction = $this->transactions()->latest()->first();
    if ($latestTransaction) {
        return match ($latestTransaction->status) {
            'paid' => PaymentStatus::SUCCESS,
            'failed' => PaymentStatus::FAILED,
            default => PaymentStatus::PENDING,
        };
    }
    // ... more fallback logic
}
```

**Issue:**
- Column added later as enhancement
- Legacy orders have NULL payment_status
- Inconsistent: Sometimes from column, sometimes derived
- Queries filtering by payment_status may miss records

**Business Impact:**
- **HIGH:** Admin filters on payment_status unreliable
- Analytics incorrect (payment success rate calculations)
- Customer order display inconsistent

**Proposed Correction:**
1. Backfill payment_status for all existing orders:
   ```sql
   UPDATE orders SET payment_status = 'payment-success' 
   WHERE status = 'completed' AND payment_status IS NULL;
   
   UPDATE orders SET payment_status = 'payment-pending' 
   WHERE status = 'pending' AND payment_status IS NULL;
   ```

2. Make column NOT NULL with default value
3. Ensure all order creation paths set payment_status explicitly

**Regression Risk:** Low (improves data quality, accessor still works)

---

### BUG-H005: Fulfillment Status Rarely Used

**Evidence:**
- File: `packages/marvel/src/Database/Models/Order.php:42-46`
- Constants defined for fulfillment_status
- Column exists in orders table
- Very few references in codebase

**Current Behavior:**
- Column exists but not actively managed
- No workflow updates fulfillment_status
- Defaults to 'pending'
- Shipment status not synchronized with fulfillment_status

**Business Impact:**
- **HIGH:** Wasted database column
- Confusion: Three status columns (status, payment_status, fulfillment_status)
- Unclear which to use for filtering/display
- Shipment status vs fulfillment status duplication

**Proposed Correction:**
**Option A (Recommended):** Remove fulfillment_status, use shipment.status
**Option B:** Synchronize fulfillment_status with shipment.status
**Option C:** Define clear usage and implement workflow

**Regression Risk:** Medium (depends on option chosen)

---

## MEDIUM SEVERITY

### BUG-M001: Governorate Shipping Price Integration Incomplete

**Evidence:**
- File: `packages/marvel/src/Database/Models/ShippingPrice.php`
- Model exists with governorate relationship
- File: `app/Services/General/OrderService.php:406-430` - `resolveShippingPrice()`
- Method exists but integration unclear

**Current Behavior:**
- ShippingPrice model defines region-based pricing
- CheckoutRepository uses legacy shipping class system
- New shipping_prices table not consistently queried
- Unclear which system is authoritative

**Business Impact:**
- **MEDIUM:** Delivery fees may not reflect configured prices
- Admin cannot reliably set region-specific pricing
- Inconsistent pricing behavior across flows

**Proposed Correction:**
1. Audit all checkout flows to use ShippingPrice model
2. Deprecate legacy Shipping class system
3. Add fallback handling for unmapped governorates
4. Document migration path

**Regression Risk:** Medium (affects order pricing calculation)

---

### BUG-M002: Admin Order Filters Missing Critical Dimensions

**Evidence:**
- File: `routes/api.php:186-191` - Admin tracking endpoints
- File: `app/Http/Controllers/Api/Admin/AdminOrderTrackingController.php`
- Limited filter support

**Current Behavior:**
- Can filter orders in dashboard
- Missing filters:
  - Date range (created_at, paid_at, delivered_at)
  - Payment method
  - Shipment status
  - Fulfillment type (delivery vs pickup)
  - Governorate/region

**Business Impact:**
- **MEDIUM:** Admin inefficiency (cannot find specific orders)
- Support team struggles to locate customer orders
- Reporting requires custom queries
- Export functionality limited

**Proposed Correction:**
Add comprehensive filter parameters to `AdminOrderTrackingController::listOrders()`:
- `date_from`, `date_to` (with field selector: created|paid|delivered)
- `payment_status[]` (multi-select)
- `payment_method[]`
- `shipment_status[]`
- `fulfillment_type`
- `governorate_id`
- `customer_search` (name, email, phone)

**Regression Risk:** Low (additive feature)

---

### BUG-M003: Public Order Tracking Endpoint Lacks Shipment Data

**Evidence:**
- File: `routes/api.php:118` - Public tracking endpoint
- Route: `POST /api/v1/general/track-order`
- File: `app/Http/Controllers/Api/General/OrderTrackingController.php`

**Current Behavior:**
- Public tracking by order number + verification (email/phone)
- Returns order status
- Does not include shipment tracking information

**Business Impact:**
- **MEDIUM:** Customer cannot track shipment without login
- Increased support inquiries ("where is my order?")
- Competitors offer public tracking links

**Proposed Correction:**
1. Include shipment data in public tracking response
2. Add shipment tracking events timeline
3. Provide estimated delivery date
4. Show current shipment status and location

**Regression Risk:** Low (additive, backward compatible)

---

### BUG-M004: No Notification Failure Retry or Dead Letter Queue

**Evidence:**
- Listeners use `ShouldQueue`
- No explicit retry policy visible
- Failed notifications not tracked separately

**Current Behavior:**
- Queue retries based on Laravel config
- After max retries, job fails silently
- No recovery mechanism for critical notifications

**Business Impact:**
- **MEDIUM:** Customers may never receive critical notifications
- No visibility into notification delivery failures
- Cannot manually retry failed notifications

**Proposed Correction:**
1. Implement failed notification tracking
2. Add admin UI to view failed notifications
3. Add manual retry button
4. Set up dead letter queue monitoring

**Regression Risk:** Low (observability improvement)

---

### BUG-M005: Shipment Status Transition Validation Not Enforced

**Evidence:**
- File: `app/Models/Shipment.php:63-76` - `canTransitionTo()` method exists
- Method defines allowed transitions
- File: `app/Services/Shipment/ShipmentService.php:47-73` - `updateStatus()` does not call validation

**Current Behavior:**
```php
// ShipmentService::updateStatus() - Line 55
$shipment->update([
    'status' => $newStatus,
    // ... other fields
]);
// No call to $shipment->canTransitionTo($newStatus)
```

**Business Impact:**
- **MEDIUM:** Invalid shipment status transitions allowed
- Data integrity issues (e.g., cancelled → delivered)
- Historical data unreliable for analytics

**Proposed Correction:**
```php
public function updateStatus(int $id, string $newStatus, ?string $notes = null): Shipment
{
    $shipment = $this->find($id);
    
    if (!$shipment->canTransitionTo($newStatus)) {
        throw new \InvalidArgumentException(
            "Cannot transition from {$shipment->status} to {$newStatus}"
        );
    }
    
    // ... proceed with update
}
```

**Regression Risk:** Medium (may break existing invalid transitions)

---

## LOW SEVERITY

### BUG-L001: Order Number Generation Race Condition

**Evidence:**
- File: `packages/marvel/src/Database/Models/Order.php:141-148`
- Boot method generates order_number in `created` event

**Current Behavior:**
```php
static::created(function (self $order) {
    if (empty($order->order_number)) {
        $order->order_number = 'ORD-' . str_pad((string) $order->id, 8, '0', STR_PAD_LEFT);
        $order->saveQuietly();
    }
});
```

**Issue:**
- Uses `saveQuietly()` to avoid triggering events
- No transaction guarantee with parent order creation
- Rare race condition: Order created but number not saved if process crashes

**Business Impact:**
- **LOW:** Extremely rare, auto-recovers via accessor
- Potential duplicate order number if ID wraps (unlikely)

**Proposed Correction:**
Generate order_number before initial save using UUID or timestamp-based system

**Regression Risk:** Low

---

### BUG-L002: Missing Database Indexes for Common Queries

**Evidence:**
- Review of migration files
- Common filter fields lack indexes

**Missing Indexes:**
```sql
-- orders table
INDEX (user_id, created_at)
INDEX (governorate_id)
INDEX (fulfillment_type, created_at)

-- shipments table
INDEX (status, created_at)
INDEX (courier, status)

-- order_status_history table
INDEX (new_status, changed_at)
```

**Business Impact:**
- **LOW:** Performance degradation on large datasets
- Admin dashboard slow on order filtering

**Proposed Correction:**
Add indexes in new migration

**Regression Risk:** None (performance improvement only)

---

### BUG-L003: No Soft Deletes on Shipments

**Evidence:**
- File: `app/Models/Shipment.php`
- No `SoftDeletes` trait
- Orders use soft deletes, shipments do not

**Current Behavior:**
- Deleting shipment permanently removes record
- Order soft delete doesn't cascade to shipment
- Inconsistent deletion behavior

**Business Impact:**
- **LOW:** Shipment data loss if deleted
- Cannot recover accidentally deleted shipments

**Proposed Correction:**
Add `SoftDeletes` trait to Shipment model and migration

**Regression Risk:** Low

---

## SUMMARY BY SEVERITY

| Severity | Count | Immediate Action Required |
|----------|-------|--------------------------|
| CRITICAL | 3 | ✅ YES - Before Production |
| HIGH | 5 | ✅ YES - Critical for Operations |
| MEDIUM | 5 | ⚠️ RECOMMENDED - Quality & UX |
| LOW | 3 | ℹ️ OPTIONAL - Future Enhancement |
| **TOTAL** | **16** | |

---

## PRIORITY MATRIX

**Fix Immediately (Critical Path):**
1. BUG-C001: Shipment tracking event history
2. BUG-C002: Payment callback idempotency
3. BUG-C003: Notification deduplication

**Fix Before Production Launch:**
4. BUG-H001: Order status state machine
5. BUG-H002: Shipment auto-creation
6. BUG-H003: One-shipment-per-order constraint
7. BUG-H004: Payment status backfill

**Fix in Next Sprint:**
8. BUG-H005: Fulfillment status cleanup
9. BUG-M001: Shipping price integration
10. BUG-M002: Admin filters

**Backlog:**
- All MEDIUM and LOW severity issues

---

**END OF BUG REGISTER**
