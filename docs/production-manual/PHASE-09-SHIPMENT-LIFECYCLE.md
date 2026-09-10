# PHASE 9: SHIPMENT LIFECYCLE

> Production Operations Manual — Shipment Lifecycle Management
> Last Updated: 2026-07-28

---

## TABLE OF CONTENTS

1. [Architecture Overview](#architecture-overview)
2. [Shipment Model](#shipment-model)
3. [ShipmentStatus Enum — Complete Transition Matrix](#shipmentstatus-enum--complete-transition-matrix)
4. [ShipmentService](#shipmentservice)
5. [ShipmentController & Security Issues](#shipmentcontroller--security-issues)
6. [BUG-2: Missing Permission Middleware — HIGH Severity](#bug-2-missing-permission-middleware--high-severity)
7. [Missing Inventory Link](#missing-inventory-link)
8. [Missing Events/Listeners](#missing-eventslisteners)
9. [Database Schema](#database-schema)
10. [Edge Cases & Failure Modes](#edge-cases--failure-modes)
11. [Production Recommendations](#production-recommendations)

---

## Architecture Overview

```
ShipmentController
  ├─ index()    → ShipmentService::list()
  ├─ show()     → ShipmentService::find()
  ├─ showByUuid()→ ShipmentService::findByUuid()
  ├─ store()    → ShipmentService::create()
  ├─ updateStatus() → ShipmentService::updateStatus()
  └─ update()   → ShipmentService::update()

ShipmentService
  ├─ list(filters, perPage)
  ├─ find(id)
  ├─ findByUuid(uuid)
  ├─ create(data)          → status = 'pending'
  ├─ updateStatus(id, status, notes)  → validates transition, sets timestamps
  └─ update(id, data)     → direct update (no validation)

Shipment Model
  └─ canTransitionTo(target) → validates from ShipmentStatus enum
```

### Current Limitations

| Area | Status |
|---|---|
| Events/Listeners | **NOT IMPLEMENTED** |
| Permission Middleware | **MISSING** (BUG-2) |
| Inventory Link | **NOT IMPLEMENTED** |
| Notifications | **NOT IMPLEMENTED** |
| Timeline/Audit Log | **NOT IMPLEMENTED** |

---

## Shipment Model

Source: `app/Models/Shipment.php`

### Fillable Fields

| Field | Type | Description |
|---|---|---|
| `uuid` | uuid (auto) | Public identifier |
| `order_id` | bigint (FK) | References `orders.id` |
| `tracking_number` | string | Courier tracking number |
| `courier` | string | Courier name (e.g., "ARAMEX", "DHL") |
| `status` | string | ShipmentStatus value |
| `shipping_method` | string | Shipping method name |
| `shipping_cost` | float | Cost of shipping |
| `currency` | string | Currency code |
| `origin_address` | json | Warehouse/origin address |
| `destination_address` | json | Customer delivery address |
| `items` | json | Items included in this shipment |
| `total_weight` | float | Total package weight |
| `weight_unit` | string | kg, lb, etc. |
| `shipped_at` | datetime | When picked up/dispatched |
| `estimated_delivery_at` | datetime | ETA from courier |
| `delivered_at` | datetime | Actual delivery timestamp |
| `notes` | text | Internal notes |
| `metadata` | json | Additional metadata |

### UUID Auto-Generation

```php
static::creating(function (self $shipment) {
    if (empty($shipment->uuid)) {
        $shipment->uuid = (string) Str::orderedUuid();
    }
});
```

---

## ShipmentStatus Enum — Complete Transition Matrix

Source: `app/Enums/ShipmentStatus.php`

### 10 States

| # | Case | Value | Description |
|---|------|-------|-------------|
| 1 | `PENDING` | `pending` | Initial state after creation |
| 2 | `LABEL_CREATED` | `label_created` | Shipping label generated |
| 3 | `PICKED_UP` | `picked_up` | Courier picked up the package |
| 4 | `IN_TRANSIT` | `in_transit` | Package in transit to destination |
| 5 | `OUT_FOR_DELIVERY` | `out_for_delivery` | Package on delivery vehicle |
| 6 | `DELIVERED` | `delivered` | Successfully delivered (TERMINAL) |
| 7 | `FAILED_DELIVERY` | `failed_delivery` | Delivery attempt failed |
| 8 | `RETURNED` | `returned` | Returned to sender (TERMINAL) |
| 9 | `DELAYED` | `delayed` | Transit delayed |
| 10 | `CANCELLED` | `cancelled` | Shipment cancelled (TERMINAL) |

### Full Transition Matrix

```
┌──────────────────┐
│     PENDING      │
└──────┬──────┬────┘
       │      │
       ▼      ▼
LABEL_CREATED  CANCELLED
       │
       │
       ▼
  ┌─────────────┐
  │ PICKED_UP   │
  └──────┬──────┘
         │
         ▼
    ┌──────────┐
    │IN_TRANSIT│
    └────┬─────┘
         │
    ┌────┴──────┐
    ▼           ▼
OUT_FOR_DELIVERY  DELAYED
    │                │
    │                └──────▶ IN_TRANSIT (reattempt)
    │                        OUT_FOR_DELIVERY (skip ahead)
    │
    ├────────────────────┐
    ▼                    ▼
DELIVERED          FAILED_DELIVERY
(terminal)               │
                    ┌────┴────┐
                    ▼         ▼
           OUT_FOR_DELIVERY  RETURNED
                    │         (terminal)
                    ▼
              (reattempt delivery)
```

### Allowed Transitions Per State (from source code)

| From State | Allowed To | Notes |
|---|---|---|
| `pending` | `label_created`, `cancelled` | Initial transition |
| `label_created` | `picked_up`, `cancelled` | Label printed, awaiting pickup |
| `picked_up` | `in_transit`, `cancelled` | Package collected by courier |
| `in_transit` | `out_for_delivery`, `delayed` | Moving through network |
| `out_for_delivery` | `delivered`, `failed_delivery` | Last mile |
| `delivered` | *(none — terminal)* | Final success state |
| `failed_delivery` | `out_for_delivery`, `returned` | Retry or return to sender |
| `returned` | *(none — terminal)* | Final return state |
| `delayed` | `in_transit`, `out_for_delivery` | Delayed → resumed transit or skip to delivery |
| `cancelled` | *(none — terminal)* | Cancelled before dispatch |

### Transition Diagram (ASCII)

```
                  ┌─────────┐
                  │ PENDING │
                  └────┬────┘
                       │
              ┌────────┼────────┐
              │        │        │
              ▼        ▼        ▼
        ┌─────────┐         ┌──────────┐
        │ LABEL   │         │CANCELLED │
        │ CREATED │         │(terminal)│
        └────┬────┘         └──────────┘
             │
             ▼
        ┌─────────┐
        │ PICKED  │
        │  UP     │
        └────┬────┘
             │
             ▼
        ┌──────────┐
        │IN_TRANSIT│◄────┐
        └────┬─────┘     │
             │           │
        ┌────┴────┐      │
        │         │      │
        ▼         ▼      │
  ┌────────┐ ┌────────┐  │
  │OUT FOR │ │DELAYED │──┘
  │DELIVERY│ └────────┘
  └───┬───-┘
      │
  ┌───┴──────┐
  │          │
  ▼          ▼
┌────────┐ ┌────────────┐
│DELIVERED│ │  FAILED    │
│(terminal)│ │ DELIVERY   │
└─────────┘ └──────┬─────┘
                   │
              ┌────┴────┐
              │         │
              ▼         ▼
        ┌────────┐ ┌────────┐
        │OUT FOR │ │RETURNED│
        │DELIVERY│ │(terminal)│
        └────────┘ └────────┘
```

### Transition Validation (on Model)

```php
public function canTransitionTo(string $target): bool
{
    return in_array($target, self::allowedTransitions($this->status), true);
}

public static function allowedTransitions(string $from): array
{
    return match ($from) {
        'pending' => ['label_created', 'cancelled'],
        'label_created' => ['picked_up', 'cancelled'],
        'picked_up' => ['in_transit', 'cancelled'],
        'in_transit' => ['out_for_delivery', 'delayed'],
        'out_for_delivery' => ['delivered', 'failed_delivery'],
        'delivered' => [],
        'failed_delivery' => ['out_for_delivery', 'returned'],
        'returned' => [],
        'delayed' => ['in_transit', 'out_for_delivery'],
        'cancelled' => [],
        default => ['cancelled'],
    };
}
```

**Note**: The model uses a static `match` statement, NOT the enum's `allowedTransitions()` method. Both should be kept in sync.

---

## ShipmentService

Source: `app/Services/Shipment/ShipmentService.php`

### list()

```php
public function list(array $filters = [], int $perPage = 15)
```

Filters supported:

| Filter | Type | Behavior |
|---|---|---|
| `order_id` | int | Exact match |
| `status` | string | Exact match |
| `courier` | string | Exact match |
| `tracking_number` | string | LIKE search (contains) |
| `from` | date | `created_at >= from` |
| `to` | date | `created_at <= to` |

Default sort: `created_at DESC`. Max per page: 100.

Always eager-loads `order` relation.

### find() / findByUuid()

```php
public function find(int $id): Shipment
public function findByUuid(string $uuid): Shipment
```

Both use `findOrFail` / `firstOrFail` and eager-load `order`.

### create()

```php
public function create(array $data): Shipment
```

- Wrapped in `DB::transaction`
- Forces `status = 'pending'` regardless of input
- Returns the created Shipment

### updateStatus()

Source: `ShipmentService.php:42`

```php
public function updateStatus(int $id, string $newStatus, ?string $notes = null): Shipment
```

Flow:
```
DB::transaction
  ├─ Shipment::lockForUpdate()->findOrFail($id)
  ├─ Validate transition via $shipment->canTransitionTo($newStatus)
  │   └─ Throws RuntimeException on invalid transition
  ├─ Set timestamps:
  │     shipped_at = now() when newStatus is 'shipped' or 'picked_up'
  │     delivered_at = now() when newStatus is 'delivered'
  ├─ Update: status, notes, timestamps
  └─ Return $shipment->fresh()
```

### update()

```php
public function update(int $id, array $data): Shipment
```

- Direct update — NO transition validation
- Does NOT force any fields
- Can bypass status validation if misused

---

## ShipmentController & Security Issues

Source: `app/Http/Controllers/Api/ShipmentController.php`

### Endpoints

| Method | URI | Controller Method | Permission Middleware |
|---|---|---|---|
| GET | `/api/v1/shipments` | `index()` | **NONE** |
| GET | `/api/v1/shipments/{id}` | `show()` | **NONE** |
| GET | `/api/v1/shipments/uuid/{uuid}` | `showByUuid()` | **NONE** |
| POST | `/api/v1/shipments` | `store()` | **NONE** |
| PUT | `/api/v1/shipments/{id}/status` | `updateStatus()` | **NONE** |
| PUT | `/api/v1/shipments/{id}` | `update()` | **NONE** |

### BUG-2: Missing Permission Middleware — HIGH Severity

**Vulnerability**: The `ShipmentController` has **NO middleware** for permission checks. Compare with `InvoiceController` which has:

```php
// InvoiceController (secure)
$this->middleware('permission:' . Permission::VIEW_INVOICES, ['only' => ['index']]);
$this->middleware('permission:' . Permission::VIEW_INVOICE, ['only' => ['show', 'showByUuid']]);
// etc.
```

**Impact**:
- Any authenticated user can list all shipments
- Any authenticated user can view any shipment's details
- Any authenticated user can create shipments
- Any authenticated user can update shipment status
- Any authenticated user can modify shipment data

**Exploitation Scenario**:
1. A customer can call `PUT /api/v1/shipments/{id}/status` to change any shipment to `delivered`
2. A malicious user can list all shipments to see tracking info, courier details, customer addresses
3. No role/permission check at all

**Fix Required**:
```php
// Add to ShipmentController constructor
$this->middleware('permission:' . Permission::VIEW_SHIPMENTS, ['only' => ['index']]);
$this->middleware('permission:' . Permission::VIEW_SHIPMENT, ['only' => ['show', 'showByUuid']]);
$this->middleware('permission:' . Permission::CREATE_SHIPMENT, ['only' => ['store']]);
$this->middleware('permission:' . Permission::UPDATE_SHIPMENT_STATUS, ['only' => ['updateStatus']]);
$this->middleware('permission:' . Permission::UPDATE_SHIPMENT, ['only' => ['update']]);
```

**Note**: The above `Permission` enum values may not exist yet. If not, they must be created in `Marvel\Enums\Permission`.

---

## Missing Inventory Link

### Current Behavior

The shipment system tracks **physical delivery only**. There is NO integration with inventory.

### What Does Not Happen

When a shipment status changes:
- **No inventory decrement**: `sold_quantity` is not incremented, `stock_quantity` is not decremented
- **No order fulfillment status**: `order.fulfillment_status` is not updated
- **No order status change**: `order.status` is not updated to `completed` or `fulfilled`

### Impact

- Inventory tracking relies entirely on the order placement (which decrements stock at purchase time)
- When a shipment is marked `delivered`, there is no side effect to update the order
- The `order.fulfillment_status` must be updated manually or via separate logic
- Multi-shipment orders (partial fulfillment) have no tracking on fulfilling individual items

### Production Recommendation

Add an event-based integration:

```php
// ShipmentService::updateStatus() should fire:
ShipmentStatusUpdated::dispatch($shipment);

// Listener: MarkOrderFulfilled
// When all shipments for an order are 'delivered', set order.fulfillment_status = 'fulfilled'

// Listener: UpdateInventoryOnShipment
// When shipment is 'picked_up', decrement stock (or mark as "in_transit" allocation)
```

---

## Missing Events/Listeners

### Current State

**No events, no listeners, no notifications** exist for shipments.

### Comparison with Invoice System

| Feature | Invoice System | Shipment System |
|---|---|---|
| Created event | `InvoiceCreated` | **NONE** |
| Status change event | Via `InvoiceStatus` enum + timeline | **NONE** |
| Email notifications | Via `PaymentSucceeded` → listeners | **NONE** |
| Audit timeline | `InvoiceTimelineService` | **NONE** |
| Queue jobs | `GenerateInvoicePdfJob` | **NONE** |

### Recommended Event Architecture

```php
// Events
ShipmentCreated            // Fired when shipment is created
ShipmentStatusUpdated      // Fired when status changes (carries old + new status)

// Listeners (on ShipmentStatusUpdated)
SendShipmentStatusNotification    // Email/SMS to customer
UpdateOrderFulfillmentStatus      // Check if all shipments delivered → fulfilled
LogShipmentTimeline               // Audit log (similar to InvoiceTimelineService)
SyncToCourierApi                   // Push status to courier's tracking system
```

---

## Database Schema

### `shipments` Table

| Column | Type | Description |
|---|---|---|
| `id` | bigint (PK) | Auto-increment |
| `uuid` | uuid | Public identifier |
| `order_id` | bigint (FK) | References `orders.id` |
| `tracking_number` | string (nullable) | Courier tracking number |
| `courier` | string (nullable) | Courier name/service |
| `status` | string | ShipmentStatus value |
| `shipping_method` | string (nullable) | Shipping method name |
| `shipping_cost` | decimal (nullable) | Shipping cost |
| `currency` | string (nullable) | Currency code |
| `origin_address` | json (nullable) | Warehouse/return address |
| `destination_address` | json (nullable) | Delivery address |
| `items` | json (nullable) | Items in this shipment |
| `total_weight` | decimal (nullable) | Package weight |
| `weight_unit` | string (nullable) | kg / lb / g / oz |
| `shipped_at` | datetime (nullable) | Dispatch/pickup timestamp |
| `estimated_delivery_at` | datetime (nullable) | Courier ETA |
| `delivered_at` | datetime (nullable) | Actual delivery timestamp |
| `notes` | text (nullable) | Internal notes |
| `metadata` | json (nullable) | Additional metadata |
| `created_at` | timestamp | Created |
| `updated_at` | timestamp | Updated |

---

## Edge Cases & Failure Modes

### 1. Concurrent Status Update

**Problem**: Two users update shipment status simultaneously.

**Mitigation**: `lockForUpdate()` in `updateStatus()` provides row-level locking.

**Failure Mode**: If `updateStatus()` is called without the lock (e.g., from a future event listener), concurrent updates can cause race conditions.

### 2. Update Without Validation

**Problem**: `update()` method bypasses all status transition validation.

**Impact**: A caller can set any status value directly, including invalid transitions or non-existent statuses.

**Mitigation**: Remove `update()` from public API or add validation. Require all status changes through `updateStatus()`.

### 3. Duplicate Shipment Creation

**Problem**: Multiple shipments created for the same order unintentionally.

**Mitigation**: None currently. Consider adding a unique constraint or check in `create()`.

### 4. Delivered Before Picked Up

**Problem**: Invalid transition `pending → delivered` — currently prevented by the transition matrix. But `update()` can bypass this.

### 5. Cancelled Pending Shipment

**Problem**: An order is cancelled after shipment creation. The shipment status must be updated to `cancelled` independently.

**Recommendation**: Listen to order cancelled event and trigger shipment cancellation.

### 6. Partial Delivery / Multi-Shipment Orders

**Problem**: An order split into multiple shipments (different items or partial quantities).

**Impact**: There is no logic to track whether all shipments are delivered. The order fulfillment status cannot be determined automatically.

**Recommendation**: Add `order.shipments` relationship and a method to check delivery completeness.

### 7. Courier Tracking Number Changes

**Problem**: The courier may provide a new tracking number after pickup.

**Impact**: The current `tracking_number` field is set once; no history is kept.

### 8. Failed Delivery — Retry Loop

**Problem**: A delivery can fail repeatedly (`failed_delivery → out_for_delivery → failed_delivery`).

**Mitigation**: The transition matrix allows this cycle. Consider adding retry count or time-based limiting.

### 9. Missing Delivery Notification

**Problem**: When status changes to `delivered`, the customer is not notified.

**Impact**: Customer doesn't know their package arrived.

### 10. No Return Window Enforcement

**Problem**: When status changes to `returned`, there is no timeout or return window.

**Impact**: A return might sit indefinitely. Consider adding an expected return-by date.

---

## Production Recommendations

### Priority Matrix

| Priority | Item | Effort | Impact |
|---|---|---|---|
| **P0 — CRITICAL** | Add permission middleware to ShipmentController | 1h | Security |
| **P1 — HIGH** | Add events/listeners for status changes | 4h | Functionality |
| **P1 — HIGH** | Add customer notifications (email/SMS) on status change | 8h | User experience |
| **P2 — MEDIUM** | Link shipment delivery to order fulfillment status | 4h | Business logic |
| **P2 — MEDIUM** | Add audit timeline for shipments | 4h | Audit trail |
| **P3 — LOW** | Add inventory allocation on shipment dispatch | 8h | Inventory accuracy |
| **P3 — LOW** | Implement courier API integration for automatic tracking | 16h | Automation |
| **P4 — NICE TO HAVE** | Multi-shipment order support | 8h | Flexibility |

### Recommended Implementation Order

1. **Add permission middleware** (security fix — do first)
2. **Add `ShipmentStatusUpdated` event and listeners** (foundation)
3. **Add customer notifications** (user-facing value)
4. **Add audit timeline** (operational)
5. **Link to order fulfillment** (business logic)
6. **Courier integration** (automation — long-term)

### Event Service Provider Wiring (recommended)

```php
// app/Providers/EventServiceProvider.php
ShipmentCreated::class => [
    LogShipmentCreated::class,           // sync
    SendShipmentCreatedNotification::class,  // queue:medium
],

ShipmentStatusUpdated::class => [
    LogShipmentStatusUpdated::class,     // sync (audit)
    SendShipmentStatusNotification::class, // queue:medium
    UpdateOrderFulfillmentStatus::class,   // queue:low
],
```

---

## Key Files Reference

| File | Purpose |
|---|---|
| `app/Enums/ShipmentStatus.php` | 10-state enum with transition matrix |
| `app/Models/Shipment.php` | Eloquent model with transition validation |
| `app/Services/Shipment/ShipmentService.php` | Business logic layer |
| `app/Http/Controllers/Api/ShipmentController.php` | REST controller (NO PERMISSION MIDDLEWARE!) |
| `app/Http/Requests/Shipment/CreateShipmentRequest.php` | Create validation |
| `app/Http/Requests/Shipment/UpdateShipmentRequest.php` | Update validation |
| `app/Http/Requests/Shipment/UpdateShipmentStatusRequest.php` | Status update validation |
