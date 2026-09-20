# PHASE 2B: COMPLETE STATE MACHINE DEFINITIONS
## ORDER LIFECYCLE - FORMAL STATE MODELS

**Date:** 2026-09-XX  
**Status:** State Models Defined - Ready for Implementation  
**Related Documents:** [Phase 1 Discovery](PHASE1_DISCOVERY_AUDIT.md) | [Phase 2 Gap Analysis](PHASE2_GAP_ANALYSIS.md)

---

## STATE MACHINE ARCHITECTURE OVERVIEW

The order lifecycle uses **FIVE INDEPENDENT STATE MACHINES** that coordinate through events and validation:

1. **Order Status** - Business completion tracking
2. **Payment Status** - Payment lifecycle tracking  
3. **Fulfillment Status** - Physical/digital fulfillment tracking
4. **Inventory State** - Inventory reservation lifecycle
5. **Shipment Status** - Delivery tracking (when applicable)

**Coordination Pattern:** State machines are updated through `OrderService::changeOrderStatus()` which validates transitions, triggers side effects, and records immutable history.

---

## STATE MACHINE 1: ORDER STATUS

### States

| State | Meaning | Terminal | Can Cancel | Inventory Implication |
|---|---|---|---|---|
| `pending` | Created, awaiting payment confirmation | No | Yes | Reserved |
| `processing` | Payment confirmed, preparing for fulfillment | No | Yes | Committed |
| `completed` | Business obligations fulfilled (invoiced, recorded) | Quasi-terminal | Conditional | Committed |
| `delivered` | Physical delivery confirmed to customer | Yes (terminal) | No | Committed |
| `cancelled` | Order voided before or after payment | Yes (terminal) | N/A | Released or Restored |

### Transition Matrix

```
FROM          TO                                TRIGGER
────────────  ────────────────────────────────  ─────────────────────────────────
pending       → pending                         Retry/update allowed
pending       → processing                      Payment confirmed (online/gateway)
pending       → completed                       Payment confirmed (COD/cashier) OR direct completion
pending       → cancelled                       User/admin cancellation, payment timeout
processing    → processing                      Update allowed
processing    → completed                       Fulfillment ready
processing    → cancelled                       User/admin cancellation
completed     → completed                       Update allowed  
completed     → delivered                       Physical delivery confirmed
delivered     → delivered                       Terminal (no transitions)
cancelled     → cancelled                       Terminal (no transitions)
```

### Validation Rules

**Enforced by:** `OrderService::canTransitionOrderStatus()`

```php
private static array $allowedOrderTransitions = [
    'pending' => ['pending', 'processing', 'completed', 'cancelled'],
    'processing' => ['processing', 'completed', 'cancelled'],
    'completed' => ['completed', 'delivered'],
    'delivered' => ['delivered'],
    'cancelled' => ['cancelled'],
];
```

### Side Effects per Transition

| Transition | Inventory Action | Payment Action | Promotion Action | Coupon Action | Notification | Other |
|---|---|---|---|---|---|---|
| `pending` → `processing` | Commit reserved inventory | Validate payment confirmed | - | - | OrderStatusChanged broadcast | - |
| `pending` → `completed` | Commit inventory | Validate payment | Finalize usage | Consume coupon | OrderStatusChanged, PaymentSucceeded | Generate invoice, grant entitlements |
| `pending` → `cancelled` | Release inventory | - | - | Release reservation | OrderStatusChanged, OrderCancelled | - |
| `processing` → `completed` | Already committed | - | Finalize if not done | Consume if not done | OrderStatusChanged | Generate invoice |
| `processing` → `cancelled` | Restore inventory | Initiate refund if paid | - | Release/refund | OrderStatusChanged, OrderCancelled | - |
| `completed` → `delivered` | - | - | - | - | OrderStatusChanged, OrderDelivered | Update fulfillment status |
| `completed` → `cancelled` (refund scenario) | Restore inventory | Process refund | **NO DECREMENT** (anti-abuse) | - | OrderStatusChanged, OrderRefunded | Credit note |

### State Semantics (DECISION-1 Resolution)

**`completed`** = Payment confirmed + business obligations met
- Invoice generated
- Financial records updated  
- Promotion finalized
- Coupon consumed
- Digital entitlements granted (if applicable)
- **Business considers the transaction complete**

**`delivered`** = Physical goods received by customer (optional terminal state)
- Only applicable to shipped orders
- Not used for digital products, pickup orders (unless explicitly marked)
- Triggered by shipment delivery confirmation
- **Customer considers the transaction complete**

**Decision Rule:**
- All paid orders reach `completed`
- Only physically shipped orders may reach `delivered`
- `completed` is sufficient terminal state for digital/pickup orders
- `delivered` provides additional tracking for physical fulfillment

---

## STATE MACHINE 2: PAYMENT STATUS

### States

| State | Meaning | Terminal |
|---|---|---|
| `payment-pending` | Awaiting payment confirmation | No |
| `payment-success` | Payment confirmed and settled | Quasi-terminal |
| `payment-failed` | Payment rejected or declined | Quasi-terminal |
| `payment-refunded` | Payment reversed (full or partial) | Yes (terminal) |

### Transition Matrix (NEW - Currently Not Enforced)

```
FROM                  TO                                    TRIGGER
────────────────────  ────────────────────────────────────  ─────────────────────────────
payment-pending       → payment-success                     Gateway callback/webhook confirms
payment-pending       → payment-failed                      Gateway reports failure
payment-pending       → payment-pending                     Retry allowed (new transaction)
payment-success       → payment-refunded                    Admin initiates refund
payment-failed        → payment-pending                     User retries payment (new transaction)
payment-refunded      → payment-refunded                    Terminal (no transitions)
```

### Validation Rules (TO BE IMPLEMENTED - GAP-H005)

```php
// NEW - Does not exist yet
private static array $allowedPaymentTransitions = [
    'payment-pending' => ['payment-pending', 'payment-success', 'payment-failed'],
    'payment-success' => ['payment-success', 'payment-refunded'],
    'payment-failed' => ['payment-failed', 'payment-pending'], // retry
    'payment-refunded' => ['payment-refunded'],
];
```

### Side Effects per Transition

| Transition | Order Status Action | Inventory Action | Financial Action | Notification |
|---|---|---|---|---|
| `pending` → `success` | Trigger completion flow (`completed` or `processing`) | Commit inventory | Record payment | PaymentSucceeded event |
| `pending` → `failed` | Keep or cancel order (based on retry policy) | Release inventory if cancelled | - | PaymentFailed event |
| `success` → `refunded` | Change order to `cancelled` | Restore inventory | Process gateway refund, credit note | OrderRefunded event |

### Current Implementation Gap

⚠️ **No explicit validation** - transitions happen via direct column updates  
⚠️ **Computed property** - `Order::getPaymentStatusAttribute()` falls back to transaction status  
⚠️ **Action Required:** Implement `canTransitionPaymentStatus()` validation (GAP-H005)

---

## STATE MACHINE 3: FULFILLMENT STATUS

### States

| State | Meaning | Terminal |
|---|---|---|
| `pending` | Awaiting preparation | No |
| `processing` | Being prepared/packed | No |
| `ready_for_pickup` | Ready at pickup location | No |
| `out_for_delivery` | In transit to customer | No |
| `delivered` | Received by customer | Yes (terminal) |
| `cancelled` | Fulfillment cancelled | Yes (terminal) |

### Transition Matrix

```
FROM                    TO                                        TRIGGER
──────────────────────  ────────────────────────────────────────  ───────────────────────────
pending                 → pending                                 Update allowed
pending                 → processing                              Fulfillment started
pending                 → cancelled                               Order cancelled
processing              → processing                              Update allowed
processing              → ready_for_pickup                        Pickup orders
processing              → out_for_delivery                        Create shipment
processing              → cancelled                               Order cancelled
ready_for_pickup        → ready_for_pickup                        Update allowed
ready_for_pickup        → delivered                               Customer picked up
ready_for_pickup        → cancelled                               Order cancelled
out_for_delivery        → out_for_delivery                        Update allowed
out_for_delivery        → delivered                               Shipment delivered
out_for_delivery        → cancelled                               Order cancelled
delivered               → delivered                               Terminal
cancelled               → cancelled                               Terminal
```

### Validation Rules

**Enforced by:** `OrderService::canTransitionFulfillmentStatus()`

```php
private static array $allowedFulfillmentTransitions = [
    'pending' => ['pending', 'processing', 'cancelled'],
    'processing' => ['processing', 'ready_for_pickup', 'out_for_delivery', 'cancelled'],
    'ready_for_pickup' => ['ready_for_pickup', 'delivered', 'cancelled'],
    'out_for_delivery' => ['out_for_delivery', 'delivered', 'cancelled'],
    'delivered' => ['delivered'],
    'cancelled' => ['cancelled'],
];
```

### Side Effects per Transition

| Transition | Shipment Action | Order Status Action | Notification | Other |
|---|---|---|---|---|
| `pending` → `processing` | - | - | FulfillmentStarted | - |
| `processing` → `out_for_delivery` | **Create shipment** (GAP-H001) | - | ShipmentCreated | - |
| `out_for_delivery` → `delivered` | Update shipment.status | Consider order `delivered` | DeliveryConfirmed | COD auto-confirm (GAP-H004) |
| `*` → `cancelled` | Cancel shipment if exists | - | FulfillmentCancelled | - |

### Coordination with Shipment Status

**Current:** Manual synchronization  
**Proposed (GAP-L005):** Bidirectional event listeners

- Fulfillment `out_for_delivery` → Create shipment with status `pending`
- Shipment `delivered` → Update fulfillment to `delivered`
- Fulfillment `cancelled` → Cancel shipment

---

## STATE MACHINE 4: INVENTORY STATE

### States

| State | Meaning | Stock Impact | Reserved Impact | Terminal |
|---|---|---|---|---|
| `none` | No reservation made | 0 | 0 | No |
| `active` | Reserved during checkout | -N (stock) | +N (reserved) | No |
| `committed` | Payment confirmed | 0 | -N (reserved) | Quasi-terminal |
| `released` | Cancelled before payment | +N (stock) | -N (reserved) | Yes (terminal) |
| `restored` | Refunded after payment | +N (stock) | 0 | Yes (terminal) |

### Transition Matrix

```
FROM          TO              TRIGGER                           STOCK CHANGE    RESERVED CHANGE
────────────  ──────────────  ────────────────────────────────  ──────────────  ────────────────
none          → active        Order creation (checkout)          -N              +N
active        → committed     Payment success                    0               -N
active        → released      Cancellation before payment        +N              -N
committed     → restored      Refund after payment               +N              0
```

### Validation Rules

**Enforced by:** State checks in `OrderReservationService` methods

```php
// In reserveForOrder()
if ($order->inventory_state !== Order::INVENTORY_STATE_NONE) {
    return; // Idempotent - already reserved
}

// In commit()
if ($order->inventory_state !== Order::INVENTORY_STATE_ACTIVE) {
    throw new \Exception('Cannot commit inventory that is not in active state');
}

// In release()
if ($order->inventory_state !== Order::INVENTORY_STATE_ACTIVE) {
    return; // Idempotent - already released
}
```

### Side Effects per Transition

| Transition | Database Mutations | Validation | Locking |
|---|---|---|---|
| `none` → `active` | `products.stock_quantity -= N`<br>`products.reserved_quantity += N`<br>`order.inventory_state = 'active'`<br>`order.inventory_reserved_at = now()` | Check `stock_quantity - reserved_quantity >= N` | `Product::lockForUpdate()` |
| `active` → `committed` | `products.reserved_quantity -= N`<br>`order.inventory_state = 'committed'` | Check `inventory_state === 'active'` | `Product::lockForUpdate()` |
| `active` → `released` | `products.stock_quantity += N`<br>`products.reserved_quantity -= N`<br>`order.inventory_state = 'released'` | Check `inventory_state === 'active'` | `Product::lockForUpdate()` |
| `committed` → `restored` | `products.stock_quantity += N`<br>`order.inventory_state = 'restored'` | Check `inventory_state === 'committed'` | `Product::lockForUpdate()` |

### Concurrency Protection

✅ **Pessimistic Locking:** All mutations use `Product::whereKey($id)->lockForUpdate()`  
✅ **State-Based Idempotency:** Checks prevent duplicate operations  
✅ **Transaction Wrapping:** All mutations in `DB::transaction()`  
✅ **Double-Entry Accounting:** Stock changes always balance: `ΔStock + ΔReserved = 0` (except restore)

---

## STATE MACHINE 5: SHIPMENT STATUS

### States

| State | Meaning | Terminal |
|---|---|---|
| `pending` | Shipment created, awaiting label | No |
| `label_created` | Shipping label generated | No |
| `picked_up` | Picked up by courier | No |
| `in_transit` | In transit to destination | No |
| `out_for_delivery` | Out for delivery | No |
| `delivered` | Delivered to recipient | Yes (terminal) |
| `failed_delivery` | Delivery attempt failed | No |
| `returned` | Returned to sender | Yes (terminal) |
| `delayed` | Delayed in transit | No |
| `cancelled` | Shipment cancelled | Yes (terminal) |

### Transition Matrix

```
FROM                    TO                                COURIER TYPICAL
──────────────────────  ────────────────────────────────  ───────────────────────
pending                 → label_created                   System/admin creates label
pending                 → cancelled                       Order cancelled before ship
label_created           → picked_up                       Courier scans pickup
label_created           → cancelled                       Order cancelled
picked_up               → in_transit                      Courier scans in-transit
picked_up               → cancelled                       Rare (contact courier)
in_transit              → out_for_delivery                Arrives at destination hub
in_transit              → delayed                         Delay notification
out_for_delivery        → delivered                       Driver confirms delivery
out_for_delivery        → failed_delivery                 Recipient unavailable
delivered               → delivered                       Terminal
failed_delivery         → out_for_delivery                Retry delivery
failed_delivery         → returned                        Max attempts exceeded
returned                → returned                        Terminal
delayed                 → in_transit                      Delay resolved
delayed                 → out_for_delivery                Catches up
cancelled               → cancelled                       Terminal
```

### Validation Rules

**Enforced by:** `Shipment::canTransitionTo(string $target)`

```php
private static array $allowedTransitions = [
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
];
```

### Side Effects per Transition

| Transition | Order Action | Notification | Tracking History | Other |
|---|---|---|---|---|
| `pending` → `label_created` | - | ShipmentLabelCreated | Record event | - |
| `picked_up` → `in_transit` | - | ShipmentInTransit | Record event | - |
| `out_for_delivery` → `delivered` | Update fulfillment to `delivered`, consider order `delivered` | ShipmentDelivered | Record event | **COD auto-confirm** (GAP-H004) |
| `out_for_delivery` → `failed_delivery` | - | DeliveryFailed | Record event with reason | - |
| `failed_delivery` → `returned` | Consider order cancellation/refund | ShipmentReturned | Record event | Initiate return flow |
| `*` → `cancelled` | - | ShipmentCancelled | Record event | - |

### Tracking History (GAP-C002 - TO BE IMPLEMENTED)

**New Table:** `shipment_tracking_events`

```sql
CREATE TABLE shipment_tracking_events (
    id BIGINT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
    shipment_id BIGINT UNSIGNED NOT NULL,
    status VARCHAR(50) NOT NULL,
    location VARCHAR(255), -- City, hub, address
    description TEXT,
    occurred_at TIMESTAMP NOT NULL,
    recorded_by_type VARCHAR(50), -- 'system', 'courier', 'admin'
    recorded_by_id BIGINT UNSIGNED,
    metadata JSON,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (shipment_id) REFERENCES shipments(id) ON DELETE CASCADE,
    INDEX idx_shipment_occurred (shipment_id, occurred_at)
);
```

**Immutability Protection:**

```php
// ShipmentTrackingEvent model
protected static function boot()
{
    parent::boot();
    
    static::updating(fn() => throw new \Exception('Tracking events are immutable'));
    static::deleting(fn() => throw new \Exception('Tracking events cannot be deleted'));
}
```

---

## CROSS-STATE-MACHINE COORDINATION

### Event-Driven Synchronization

```
Payment Success (Transaction)
    ↓ PaymentSucceeded Event
    → OrderService::changeOrderStatus('completed')
        ↓ Triggers
        → Inventory: active → committed
        → Fulfillment: pending → processing
        → (GAP-H001) Create Shipment: status=pending
        → OrderStatusChanged Event (broadcast)

Shipment Delivered
    ↓ ShipmentDelivered Event
    → (GAP-L005) Update Order: fulfillment_status='delivered'
    → (GAP-H004) If COD: OrderService::markCodAsPaid()
        ↓ Triggers
        → Order: pending → completed
        → Payment: pending → success
        → Inventory: active → committed
        → OrderStatusChanged Event
```

### Invariants (Must Always Hold)

1. **Inventory Consistency:**
   ```
   ∀ product: stock_quantity + reserved_quantity = constant (ignoring new stock)
   ```

2. **Payment-Order Consistency:**
   ```
   payment_status = 'success' ⟹ order_status ∈ {'processing', 'completed', 'delivered'}
   payment_status = 'pending' ⟹ order_status ∈ {'pending'}
   ```

3. **Fulfillment-Shipment Consistency:**
   ```
   fulfillment_status = 'out_for_delivery' ⟹ ∃ shipment
   shipment.status = 'delivered' ⟹ fulfillment_status = 'delivered'
   ```

4. **Order-Inventory Consistency:**
   ```
   order_status = 'pending' ⟹ inventory_state ∈ {'none', 'active'}
   order_status ∈ {'completed', 'delivered'} ⟹ inventory_state = 'committed'
   order_status = 'cancelled' ⟹ inventory_state ∈ {'released', 'restored'}
   ```

---

## STATE TRANSITION AUTHORIZATION

### Who Can Trigger Transitions?

| Transition | Customer | Admin | System | Courier/Webhook | Gateway Callback |
|---|---|---|---|---|---|
| Order: pending → processing | ❌ | ✅ | ✅ (payment confirm) | ❌ | ✅ |
| Order: pending → completed | ❌ | ✅ | ✅ (payment confirm) | ❌ | ✅ |
| Order: pending → cancelled | ✅ | ✅ | ✅ (timeout) | ❌ | ❌ |
| Order: processing → cancelled | ⚠️ (conditional) | ✅ | ❌ | ❌ | ❌ |
| Order: completed → delivered | ❌ | ✅ | ✅ (shipment delivered) | ✅ (webhook) | ❌ |
| Payment: pending → success | ❌ | ✅ (COD/cashier) | ❌ | ❌ | ✅ |
| Payment: success → refunded | ❌ | ✅ | ❌ | ❌ | ❌ |
| Fulfillment: * → * | ❌ | ✅ | ✅ (auto-sync) | ❌ | ❌ |
| Shipment: * → * | ❌ | ✅ | ✅ (courier API) | ✅ (webhook) | ❌ |

---

## FAILURE SCENARIOS & RECOVERY

### Scenario 1: Payment Callback Never Arrives

**Symptoms:** Order stuck in `pending`, inventory `active`, payment actually succeeded at gateway

**Detection:** Compare gateway transaction list with pending orders

**Recovery:**
1. Manual verification of payment via gateway dashboard
2. Call `/checkout-callback` manually with payment details
3. Or admin force-complete with `OrderService::changeOrderStatus('completed')`

**Prevention (GAP-M006):** Implement webhook handler separate from callback

---

### Scenario 2: Inventory Committed But Order Cancelled

**Symptoms:** `inventory_state='committed'` but `order_status='cancelled'`

**Should Never Happen:** Validation prevents cancellation after commit

**If Occurs:**
1. Investigate how validation was bypassed
2. Manual inventory restoration via `InventoryRestoreService::restore()`
3. Fix validation gap

---

### Scenario 3: Shipment Delivered But Order Still Processing

**Symptoms:** `shipment.status='delivered'` but `order_status='processing'`

**Cause:** Missing auto-sync (GAP-L005)

**Recovery:**
1. Admin manually updates order to `delivered`
2. Triggers side effects retroactively

**Prevention:** Implement `SyncFulfillmentOnShipmentChange` listener

---

### Scenario 4: Duplicate Payment Webhook + Callback

**Symptoms:** Both webhook and callback attempt to process same payment

**Current Risk:** Status-check idempotency (TOCTOU race)

**Recovery:** Database transaction + pessimistic locks prevent corruption, but may process twice

**Prevention (GAP-C001):** Implement idempotency token system

---

## TESTING STRATEGY

### Unit Tests (Per State Machine)

```php
// Example: Order Status State Machine
test_can_transition_from_pending_to_processing()
test_cannot_transition_from_delivered_to_cancelled()
test_all_terminal_states_reject_transitions()
test_side_effects_triggered_on_valid_transition()
```

### Integration Tests (Cross-Machine)

```php
test_payment_success_commits_inventory_and_updates_order()
test_shipment_delivery_updates_fulfillment_and_triggers_cod_confirm()
test_cancellation_releases_inventory_and_refunds_payment()
```

### Concurrency Tests

```php
test_concurrent_inventory_reservation_prevents_oversell()
test_duplicate_payment_callback_processes_once()
test_concurrent_cancellation_and_payment_success()
```

### State Invariant Tests

```php
test_inventory_equation_always_holds()
test_payment_success_implies_order_not_pending()
test_delivered_order_has_committed_inventory()
```

---

## IMPLEMENTATION CHECKLIST

Before starting Phase 3 implementation:

- [x] All state machines documented with transitions
- [x] Side effects defined for each transition
- [x] Validation rules specified
- [x] Coordination events mapped
- [x] Invariants defined
- [x] Authorization matrix created
- [x] Failure scenarios documented
- [x] Testing strategy defined
- [ ] **Stakeholder approval on semantics (DECISION-1 through DECISION-5)**
- [ ] **Technical review of state models**
- [ ] **Test plan approved**

---

**END OF PHASE 2B: STATE MACHINE DEFINITIONS**

**Status:** ✅ State Models Complete | ⏳ Awaiting Stakeholder Decisions | Ready for Phase 3 Implementation
