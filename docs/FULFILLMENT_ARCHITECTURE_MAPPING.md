# Fulfillment System Architecture Mapping

**Project:** D:\work\meem  
**Date:** 2026-09-22  
**Phase:** Discovery & Architecture Mapping  
**Status:** IN PROGRESS

---

## Executive Summary

This document maps the existing order/inventory/shipment architecture to identify integration points for the new Fulfillment/Picking system.

**Critical Finding:** The codebase has a mature, well-architected order lifecycle with:
- Separate inventory reservation system (OrderReservationService)
- Shipment tracking with 10-state machine
- Order-owned inventory states (none → active → committed/released/restored)
- Immutable audit trail (OrderStatusHistory)

**Integration Strategy:** Add fulfillment layer WITHOUT disrupting existing reservation/stock authority.

---

## 1. Current System Components

### 1.1 Order System

**Location:** `packages/marvel/src/Database/Models/Order.php` (317 lines)

**Status Fields:**
- `status`: Order lifecycle (pending, processing, completed, delivered, cancelled)
- `payment_status`: Payment state (payment-pending, payment-success, payment-failed, payment-refunded)
- `fulfillment_status`: Operational fulfillment state (pending, processing, ready_for_pickup, out_for_delivery, delivered, cancelled)
- `inventory_state`: Reservation lifecycle (none, active, released, committed, restored)
- `shipment_status`: Shipment tracking (pending, label_created, picked_up, in_transit, out_for_delivery, delivered, failed_delivery, returned, cancelled)

**Key Relationships:**
```php
public function orderItems(): HasMany  // OrderProduct (order lines)
public function transactions(): HasMany  // Payment transactions
public function statusHistory(): HasMany  // Immutable audit trail
```

**Inventory Tracking:**
- `inventory_state`: Tracks reservation lifecycle
- `inventory_reserved_at`: Timestamp when reservation created
- `reservation_expires_at`: TTL for unpaid orders
- `inventory_state_restored_at`: Timestamp when inventory released back

**Critical Method:**
```php
public function recordStatusChange(...): OrderStatusHistory
```
Creates immutable audit records with old/new status for order, payment, and fulfillment.

---

### 1.2 Inventory/Stock System

**Stock Authority:** `packages/marvel/src/Database/Models/Product.php`

**Fields:**
- `stock_quantity`: Physical inventory count (master authority)
- `reserved_quantity`: Locked for active orders/carts
- `sold_quantity`: Cumulative sold (informational)

**Available Stock Formula:**
```php
$available = $stock_quantity - $reserved_quantity;
```

**Reservation Service:** `app/Services/Inventory/OrderReservationService.php`

**Key Methods:**
```php
reserveForOrder(Order $order): void
  - Aggregates physical lines from order
  - Locks stock rows (lockForUpdate())
  - Increments reserved_quantity
  - Sets order.inventory_state = 'active'
  - Records reservation timestamp

commit(Order $order): bool
  - Decrements stock_quantity
  - Decrements reserved_quantity
  - Increments sold_quantity
  - Sets order.inventory_state = 'committed'

release(Order $order): bool
  - Decrements reserved_quantity
  - Sets order.inventory_state = 'released'
  - Used for order cancellation before payment

aggregatePhysicalLines(Order $order): Collection
  - Groups order items by product_id/variant_id
  - Filters out DIGITAL products
  - Returns [(product_id, variant_id, quantity), ...]

lockStockRow(int $productId, ?int $variantId): Product|ProductVariant
  - Uses lockForUpdate() for concurrency safety
```

**Restore Service:** `app/Services/Inventory/InventoryRestoreService.php`

**Key Method:**
```php
restore(Order $order): bool
  - Decrements sold_quantity (reversal of commit)
  - Increments stock_quantity (return to shelf)
  - Sets order.inventory_state_restored_at
  - Used for refund scenarios
```

**Locking Mechanism:** Pessimistic locking via `lockForUpdate()` (InnoDB row-level locks)

**Authority Decision:** ✅ **Stock remains master authority**  
ProductLocation will be a breakdown/allocation layer, NOT a replacement.

---

### 1.3 Shipment System

**Location:** `app/Models/Shipment.php` (85 lines)

**Status States (10-state machine):**
```php
pending → label_created → picked_up → in_transit → out_for_delivery → delivered
                                                  ↘ delayed ↗
failed_delivery → returned
cancelled (from any state)
```

**Transition Matrix:**
```php
'pending' => ['label_created', 'cancelled']
'label_created' => ['picked_up', 'cancelled']
'picked_up' => ['in_transit', 'cancelled']
'in_transit' => ['out_for_delivery', 'delayed']
'out_for_delivery' => ['delivered', 'failed_delivery']
'delivered' => []
'failed_delivery' => ['out_for_delivery', 'returned']
'returned' => []
'delayed' => ['in_transit', 'out_for_delivery']
'cancelled' => []
```

**Fields:**
- `uuid`: Unique identifier
- `order_id`: FK to orders
- `tracking_number`: Courier tracking code
- `courier`: Carrier name
- `status`: Current shipment state
- `shipping_method`: Shipping tier
- `shipping_cost`: Cost
- `items`: JSON array of shipped items
- `shipped_at`, `estimated_delivery_at`, `delivered_at`: Timestamps

**Relationship:**
```php
public function order(): BelongsTo  // belongs to Order
```

**Package Support:** ❌ **Single shipment per order (items stored as JSON array)**  
No explicit Package/PackageItem tables exist.

**Integration Gap:** Shipment status changes do NOT automatically sync to `order.fulfillment_status` or `order.shipment_status`.

**Service:** `app/Services/Shipment/ShipmentService.php`
- Contains `updateStatus()` method
- No event emission detected in initial scan

---

### 1.4 Order Items

**Location:** `packages/marvel/src/Database/Models/OrderProduct.php` (implied from Order relationship)

**Key Fields:**
- `order_id`: FK to orders
- `product_id`: FK to products
- `product_variant_id`: FK to product_variants (nullable)
- `quantity`: Ordered quantity
- `price`: Unit price
- `total`: Line total

**Authority:** OrderProduct is the source of truth for "what was ordered"

---

## 2. Planned Additions

### 2.1 New Entities

#### 2.1.1 Warehouse
**Purpose:** Physical storage location  
**Maps to:** NEW TABLE  
**Replaces:** Nothing  
**Integrates with:** Stock, Location, Fulfillment

**Schema:**
```sql
warehouses:
  id, code, name, address, city, country, status, is_default, metadata, timestamps
```

**Authority:** Warehouse is a container; Stock remains inventory authority.

---

#### 2.1.2 Location
**Purpose:** Specific storage position within warehouse (shelf, bin, zone)  
**Maps to:** NEW TABLE  
**Replaces:** Nothing  
**Integrates with:** Warehouse (parent), ProductLocation

**Schema:**
```sql
locations:
  id, warehouse_id, parent_id (self-ref), code, name, type, status, priority, metadata, timestamps
  UNIQUE(warehouse_id, code)
```

**Hierarchy:** Supports nested locations (e.g., Zone A → Shelf A-01 → Bin A-01-05)

---

#### 2.1.3 ProductLocation
**Purpose:** Breakdown of stock quantity by physical location  
**Maps to:** NEW TABLE  
**Replaces:** Nothing  
**Integrates with:** Stock (as breakdown), Location

**Schema:**
```sql
product_locations:
  id, product_id, location_id, warehouse_id (denormalized), quantity, reserved_quantity, timestamps
  UNIQUE(product_id, location_id)
  CHECK(quantity >= 0)
  CHECK(reserved_quantity >= 0)
  CHECK(reserved_quantity <= quantity)
```

**CRITICAL INVARIANT:**
```
SUM(ProductLocation.quantity WHERE product_id = X) <= Stock.stock_quantity WHERE product_id = X
```

**Authority Decision:** ProductLocation is a breakdown for picking optimization, NOT primary authority.  
**Sync Strategy:** Reconciliation service validates invariant periodically.

---

#### 2.1.4 Fulfillment
**Purpose:** Operational record of warehouse fulfillment process for an order  
**Maps to:** NEW TABLE  
**Replaces:** Nothing  
**Integrates with:** Order (1:N - order can have multiple fulfillments for split shipments)

**Schema:**
```sql
fulfillments:
  id, order_id, warehouse_id, fulfillment_number, type (standard|split|partial), 
  status (pending|planned|picking|picked|packing|packed|shipped|completed|cancelled),
  planned_at, picking_started_at, picking_completed_at, packing_started_at, 
  packing_completed_at, shipped_at, completed_at, cancelled_at, cancellation_reason,
  metadata, timestamps
```

**Relationship to Order.fulfillment_status:**
- `Order.fulfillment_status` = customer-facing aggregated view
- `Fulfillment.status` = operational warehouse view
- Sync Logic: Order.fulfillment_status derived from Fulfillments

**State Machine:**
```
pending → planned → picking → picked → packing → packed → shipped → completed
         ↘        ↘        ↘        ↘        ↘
                   cancelled (from any state before shipped)
```

---

#### 2.1.5 FulfillmentItem
**Purpose:** Line items within a fulfillment (maps to OrderItem)  
**Maps to:** NEW TABLE  
**Integrates with:** Fulfillment, OrderItem (FK), Product

**Schema:**
```sql
fulfillment_items:
  id, fulfillment_id, order_item_id, product_id, 
  allocated_quantity, picked_quantity, packed_quantity,
  status (pending|picking|picked|packing|packed|completed),
  metadata, timestamps
  CHECK(allocated_quantity > 0)
  CHECK(picked_quantity >= 0 AND picked_quantity <= allocated_quantity)
  CHECK(packed_quantity >= 0 AND packed_quantity <= picked_quantity)
```

**Split Fulfillment Support:**
- One OrderItem can map to multiple FulfillmentItems across different Fulfillments
- `SUM(FulfillmentItem.allocated_quantity WHERE order_item_id = X) = OrderItem.quantity`

---

#### 2.1.6 FulfillmentBatch
**Purpose:** Group multiple orders for wave/batch picking  
**Maps to:** NEW TABLE

**Schema:**
```sql
fulfillment_batches:
  id, warehouse_id, batch_number, status (pending|in_progress|completed|cancelled),
  created_by, started_at, completed_at, cancelled_at, metadata, timestamps
```

---

#### 2.1.7 PickingRequirement
**Purpose:** Aggregated product picking requirement for a batch  
**Maps to:** NEW TABLE  
**Integrates with:** Batch, Product

**Schema:**
```sql
picking_requirements:
  id, batch_id, product_id, product_variant_id, required_quantity, 
  picked_quantity, status (pending|in_progress|completed), metadata, timestamps
```

**Aggregation Logic:**
```
SELECT product_id, SUM(fi.allocated_quantity) as required_quantity
FROM fulfillment_items fi
JOIN fulfillments f ON fi.fulfillment_id = f.id
WHERE f.id IN (batch_fulfillments)
GROUP BY product_id
```

---

#### 2.1.8 PickingTask
**Purpose:** Individual pick task (product from specific location)  
**Maps to:** NEW TABLE  
**Integrates with:** PickingRequirement, ProductLocation

**Schema:**
```sql
picking_tasks:
  id, picking_requirement_id, product_location_id, location_id, 
  warehouse_id (denormalized), quantity_to_pick, quantity_picked,
  status (pending|in_progress|completed|skipped), picker_id, 
  started_at, completed_at, metadata, timestamps
```

**Allocation Strategy:**
```
1. Get PickingRequirement (Product A, quantity 50)
2. Query ProductLocation WHERE product_id = A ORDER BY location.priority DESC
3. Allocate 50 units across locations (e.g., Loc1: 30, Loc2: 20)
4. Create PickingTask for each allocation
```

---

#### 2.1.9 Package
**Purpose:** Physical package/box for shipment  
**Maps to:** NEW TABLE or EXTEND Shipment  
**Integrates with:** Fulfillment, Shipment

**Decision:** Create separate Package table (multi-package support)

**Schema:**
```sql
packages:
  id, fulfillment_id, shipment_id (nullable initially, set when shipped),
  package_number, status (pending|packed|shipped|delivered),
  weight, dimensions (JSON), tracking_number, metadata, timestamps
```

---

#### 2.1.10 PackageItem
**Purpose:** Items within a package  
**Maps to:** NEW TABLE  
**Integrates with:** Package, FulfillmentItem

**Schema:**
```sql
package_items:
  id, package_id, fulfillment_item_id, product_id, quantity, metadata, timestamps
```

---

### 2.2 Services Architecture

#### Services Reused (NO CHANGES)
- ✅ `OrderReservationService` - Keep existing reservation logic
- ✅ `InventoryRestoreService` - Keep existing restore logic
- ✅ `OrderService` - Minor additions for fulfillment integration

#### Services Created
1. **ProductLocationService**
   - `syncWithStock(productId)`: Validate invariant
   - `allocateFromLocations(productId, quantity, warehouseId)`: Find best locations
   - `updateLocationQuantity(locationId, delta, reason)`: Adjust with validation

2. **FulfillmentService**
   - `createFromOrder(Order)`: Generate Fulfillment + FulfillmentItems
   - `transitionStatus(Fulfillment, newStatus)`: State machine enforcement
   - `syncOrderFulfillmentStatus(Order)`: Aggregate fulfillments → order.fulfillment_status

3. **BatchPickingService**
   - `createBatch(orderIds[], warehouseId)`: Group orders into batch
   - `aggregateRequirements(Batch)`: Calculate picking requirements
   - `allocateLocations(PickingRequirement)`: Create picking tasks

4. **PickingService**
   - `startPicking(PickingTask)`: Begin pick
   - `confirmPick(PickingTask, quantity)`: Record picked quantity
   - `completeRequirement(PickingRequirement)`: Mark complete

5. **PackingService**
   - `createPackage(FulfillmentItem[])`: Group items into package
   - `verifyBarcode(Package, productId)`: Scan verification
   - `finalizePackage(Package)`: Mark ready for shipment

6. **ShipmentIntegrationService**
   - `linkPackageToShipment(Package, Shipment)`: Associate
   - `syncShipmentToOrder(Shipment)`: Update order.shipment_status
   - (Enhance existing ShipmentService to emit events)

---

## 3. Critical Integration Points

### 3.1 Stock Authority Decision

**Decision:** ✅ ProductLocation = breakdown of Stock (Stock remains master)

**Invariant:**
```sql
SUM(product_locations.quantity WHERE product_id = X) <= products.stock_quantity WHERE id = X
```

**Sync Strategy:**
- ProductLocationService validates invariant on every update
- Reconciliation command runs periodically to detect drift
- Drift resolution: Log error + alert, Stock wins

**Why NOT replace Stock?**
1. Existing reservation system deeply integrated
2. Backward compatibility (200+ files reference Stock)
3. Product model accessor `available_stock_attribute` used everywhere
4. Risk: High - would require rewriting checkout, cart, product listing

---

### 3.2 Reservation Integration

**Decision:** ✅ Keep existing Order-time reservation (OrderReservationService)

**Integration Point:** Batch picking determines "from which location" but does NOT re-reserve

**Flow:**
```
1. Checkout → OrderReservationService.reserveForOrder()
   - Stock.reserved_quantity += order.quantity
   - Order.inventory_state = 'active'

2. Payment Success → OrderReservationService.commit()
   - Stock.stock_quantity -= order.quantity
   - Stock.reserved_quantity -= order.quantity
   - Stock.sold_quantity += order.quantity
   - Order.inventory_state = 'committed'

3. Create Fulfillment (NEW)
   - FulfillmentService.createFromOrder(order)
   - Fulfillment.status = 'pending'

4. Create Batch (NEW)
   - BatchPickingService.createBatch([order1, order2, ...])
   - Aggregate requirements
   - Allocate locations (read ProductLocation, no reservation)

5. Pick (NEW)
   - PickingService.confirmPick(task, quantity)
   - ProductLocation.quantity -= quantity (actual deduction)
   - Fulfillment.status = 'picked'

6. Pack & Ship (NEW)
   - PackingService.finalizePackage()
   - Shipment created/linked
```

**Changes to Existing:** ✅ **NONE**

**Why this works:**
- Stock authority unchanged (OrderReservationService continues to work)
- ProductLocation is operational detail (where to pick from)
- No double-reservation (Stock already committed at payment time)

---

### 3.3 Fulfillment Status Sync

**Decision:** Order.fulfillment_status = derived view from Fulfillments

**Sync Logic:**
```php
FulfillmentService::syncOrderFulfillmentStatus(Order $order) {
  $fulfillments = $order->fulfillments;
  
  // All completed → delivered
  if ($fulfillments->every(fn($f) => $f->status === 'completed')) {
    $order->update(['fulfillment_status' => 'delivered']);
    return;
  }
  
  // Any shipped → out_for_delivery
  if ($fulfillments->some(fn($f) => $f->status === 'shipped')) {
    $order->update(['fulfillment_status' => 'out_for_delivery']);
    return;
  }
  
  // Any in progress → processing
  if ($fulfillments->some(fn($f) => in_array($f->status, ['planned', 'picking', 'picked', 'packing', 'packed']))) {
    $order->update(['fulfillment_status' => 'processing']);
    return;
  }
  
  // Default
  $order->update(['fulfillment_status' => 'pending']);
}
```

**Trigger:** Call after every Fulfillment.status transition

---

### 3.4 Shipment Integration

**Current Gap:** Shipment status changes isolated (no sync to order)

**Enhancement Required:**
```php
// Create event
class ShipmentStatusChanged implements ShouldBroadcast, ShouldDispatchAfterCommit {
  public Shipment $shipment;
}

// Listener to sync
class SyncOrderShipmentStatus {
  public function handle(ShipmentStatusChanged $event) {
    $order = $event->shipment->order;
    $order->update(['shipment_status' => $event->shipment->status]);
    // Also call FulfillmentService::syncOrderFulfillmentStatus if needed
  }
}
```

**Package → Shipment Link:**
```php
// When package finalized
$package->update(['status' => 'packed']);

// When ready to ship (may be external system creating shipment)
ShipmentService::createFromPackages([$package1, $package2]);
// Links package.shipment_id = shipment.id
```

---

## 4. Risk Assessment

### 4.1 High Risk Areas

| Risk | Impact | Mitigation |
|------|--------|------------|
| **Stock-Location Quantity Mismatch** | Critical - overselling | ProductLocationService validates invariant on every update, periodic reconciliation |
| **Concurrent Batch Creation** | High - double-picking | Use DB transactions + status guards |
| **Double-Pick Prevention** | High - inventory loss | PickingTask.status guards, quantity validation |
| **Allocation Accuracy** | Medium - wrong products shipped | Barcode verification in PackingService |
| **Split Fulfillment Edge Cases** | Medium - partial shipments lost | Validation: SUM(FulfillmentItem.allocated) = OrderItem.quantity |

### 4.2 Mitigation Strategies

1. **Transaction-Level Locking**
   ```php
   DB::transaction(function() {
     $stock = Product::lockForUpdate()->find($id);
     // ... update
   });
   ```

2. **Idempotency Keys**
   - Batch creation: Check batch_number uniqueness
   - Pick confirmation: Check PickingTask.status before updating

3. **Status Guards**
   ```php
   if ($fulfillment->status !== 'pending') {
     throw new \Exception('Cannot transition from current status');
   }
   ```

4. **Quantity Validation**
   ```php
   if ($productLocation->quantity < $quantityToPick) {
     throw new InsufficientStockException();
   }
   ```

---

## 5. Implementation Priority

### Phase 1: Foundation (Week 1-2)
- [x] Architecture mapping (this document)
- [ ] Warehouse + Location tables + models
- [ ] ProductLocation table + model
- [ ] ProductLocationService with invariant validation
- [ ] Migration to seed default warehouse
- [ ] Basic tests (invariant validation, allocation logic)

### Phase 2: Fulfillment Core (Week 2-3)
- [ ] Fulfillment + FulfillmentItem tables + models
- [ ] FulfillmentService (createFromOrder, transitionStatus, syncOrderStatus)
- [ ] Integration with Order model (add relationship)
- [ ] Order controller integration (auto-create fulfillment after payment)
- [ ] Tests (state transitions, split fulfillment, status sync)

### Phase 3: Batch Picking (Week 3-4)
- [ ] FulfillmentBatch + PickingRequirement + PickingTask tables + models
- [ ] BatchPickingService (createBatch, aggregateRequirements, allocateLocations)
- [ ] PickingService (startPicking, confirmPick, completeRequirement)
- [ ] Batch controller (create, view, complete)
- [ ] Tests (aggregation accuracy, allocation logic, concurrency)

### Phase 4: Packing & Integration (Week 4-5)
- [ ] Package + PackageItem tables + models
- [ ] PackingService (createPackage, verifyBarcode, finalizePackage)
- [ ] Enhance ShipmentService to emit events
- [ ] Create ShipmentStatusChanged event + SyncOrderShipmentStatus listener
- [ ] Link packages to shipments
- [ ] Tests (barcode verification, shipment sync, multi-package)

### Phase 5: Measurement & Final Report (Week 5-6)
- [ ] End-to-end integration tests
- [ ] Concurrency tests (batch creation, picking)
- [ ] Performance measurement (query counts, lock duration)
- [ ] Edge case validation (insufficient stock, split fulfillment, cancellation)
- [ ] Final assessment report

---

## 6. Open Questions

### Q1: When to create Fulfillment?
**Options:**
A. On order creation (immediately)
B. On payment success (committed inventory)
C. On admin action (manual trigger)

**Recommendation:** B (payment success) - aligns with inventory commit

### Q2: Should ProductLocation track reserved_quantity separately?
**Options:**
A. Yes - mirror Stock.reserved_quantity at location level
B. No - only track total quantity, reservation stays at Stock level

**Recommendation:** B initially (simpler), A if location-specific reservation needed later

### Q3: How to handle partial picks (picked < allocated)?
**Options:**
A. Block until full quantity picked
B. Allow partial, create new fulfillment for remaining
C. Allow partial, cancel remaining

**Recommendation:** A for MVP (full quantity required), B for v2 (split fulfillment)

### Q4: Shipment created by whom?
**Options:**
A. Auto-created when package finalized
B. Created by admin/system when label generated
C. Created by external courier webhook

**Recommendation:** B (admin creates with tracking info), C integrated later

---

## 7. Backward Compatibility Analysis

### 7.1 Database Schema Changes
- ✅ All new tables (no ALTER on existing tables)
- ✅ No FK constraints on existing tables
- ✅ Order model extended (add relationship method, no column changes)

### 7.2 Service Layer Changes
- ✅ OrderReservationService - NO CHANGES
- ✅ InventoryRestoreService - NO CHANGES
- ⚠️ OrderService - Minor additions (call FulfillmentService after payment)
- ⚠️ ShipmentService - Add event emission (non-breaking)

### 7.3 API Changes
- ✅ Existing order endpoints unchanged
- ✅ New endpoints for fulfillment (opt-in)
- ✅ Order response includes fulfillments relationship (optional eager load)

### 7.4 Migration Risk
- ✅ Low - additive only
- ✅ No data migration required (new system starts empty)
- ✅ Existing orders continue to work without fulfillments

---

## 8. Next Steps

1. ✅ **Complete this architecture mapping**
2. ⏳ **Review with team** (confirm decisions, resolve open questions)
3. ⏳ **Begin Phase 1 implementation** (Foundation - Warehouse/Location/ProductLocation)
4. ⏳ **Create measurement baseline** (current order processing time, query counts)
5. ⏳ **Implement incrementally** (one phase at a time, measure after each)
6. ⏳ **Produce final assessment report** (impact, gaps, production readiness)

---

## Document Metadata
- **Author:** AI Architecture Audit (Claude Opus 5)
- **Version:** 1.0
- **Status:** DISCOVERY COMPLETE
- **Last Updated:** 2026-09-22
- **Next Review:** After Phase 1 implementation

---

**END OF ARCHITECTURE MAPPING - PHASE 1 DISCOVERY COMPLETE**
