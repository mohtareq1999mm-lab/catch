# PHASE 11: RETURN LIFECYCLE

> Production Operations Manual — Return Lifecycle Analysis & Production Recommendations
> Last Updated: 2026-07-28

---

## TABLE OF CONTENTS

1. [CURRENT STATE: No Dedicated Return System](#current-state-no-dedicated-return-system)
2. [How Returns Currently Work](#how-returns-currently-work)
3. [What Is Missing](#what-is-missing)
4. [Production Recommendation: Build a Dedicated Return System](#production-recommendation-build-a-dedicated-return-system)
5. [Recommended Architecture](#recommended-architecture)
6. [ReturnStatus Enum — Complete Transition Matrix](#returnstatus-enum--complete-transition-matrix)
7. [ReturnRequest Model Design](#returnrequest-model-design)
8. [ReturnItem Model Design](#returnitem-model-design)
9. [Inspection Workflow](#inspection-workflow)
10. [Restocking Flow](#restocking-flow)
11. [Replacement Flow](#replacement-flow)
12. [Integration Points](#integration-points)
13. [Edge Cases & Failure Modes](#edge-cases--failure-modes)
14. [Implementation Roadmap](#implementation-roadmap)

---

## CURRENT STATE: No Dedicated Return System

After a thorough search of the entire codebase, **no dedicated return system exists**.

### Models NOT Found

| Model | Exists? |
|---|---|
| `ReturnRequest` | **NO** |
| `ReturnItem` | **NO** |
| `ReturnStatus` enum | **NO** |
| `ReturnPolicy` | Not in app code (may be in Marvel package) |
| `ReturnReason` | Not in app code (may be in Marvel package) |

### Services NOT Found

| Service | Exists? |
|---|---|
| `ReturnService` | **NO** |
| `ReturnInspectionService` | **NO** |
| `ReturnRestockingService` | **NO** |

### Controllers NOT Found

| Controller | Exists? |
|---|---|
| `ReturnController` | **NO** |
| `ReturnAdminController` | **NO** |

### Events/Listeners NOT Found

| Event/Listener | Exists? |
|---|---|
| `ReturnRequested` | **NO** |
| `ReturnApproved` | **NO** |
| `ReturnReceived` | **NO** |
| `ReturnInspected` | **NO** |

---

## How Returns Currently Work

### Current Implicit Return Flow

Returns are handled **through the refund system** only. There is no separate return request flow.

```
Customer contacts support (out of band — email/phone)
  │
  ▼
Admin creates refund request in dashboard
  │
  ▼
Refund model created (customer requests refund)
  │
  ▼
Admin approves refund
  │
  ▼
RefundApproved event fires:
  ├── RatingRemoved (deletes reviews)
  ├── RestoreInventoryOnRefund (restores stock)
  └── GenerateCreditNoteOnRefund (creates CN, marks invoice corrected)
```

### Limitations of Current Approach

| Aspect | Current | Missing |
|---|---|---|
| Return initiation | Customer must contact support | No self-service return portal |
| Return authorization | No RMA number generated | No tracking of return window |
| Item inspection | Not modeled | No condition assessment |
| Restocking fee | Not modeled | No fee calculation |
| Replacement | Not supported | No replacement order flow |
| Return shipping | Not tracked | No return label generation |
| Warehouse receipt | Not tracked | No receiving workflow |
| Quality check | Not modeled | No pass/fail inspection |
| Refund-to-return link | Implicit via refund | No explicit relationship |
| Return window enforcement | Not implemented | No date validation |

---

## What Is Missing

### Complete Feature List

```
RETURN REQUEST
  ✗ Customer submits return request via API
  ✗ RMA number generation (gapless sequence)
  ✗ Return window validation (e.g., "within 14 days")
  ✗ Per-item return selection (partial returns)
  ✗ Return reason categorization
  ✗ Supporting image upload

RETURN AUTHORIZATION
  ✗ Admin reviews return request
  ✗ Approve/reject with reason
  ✗ Generate RMA number
  ✗ Issue return shipping label (courier integration)
  ✗ Set return deadline

INSPECTION WORKFLOW
  ✗ Warehouse receives returned items
  ✗ Condition assessment (pass/fail/partial)
  ✗ Damage level classification
  ✗ Photo evidence capture
  ✗ Inspection decision:
      - Approve (full refund)
      - Partial refund (with restocking fee)
      - Reject (item not in original condition)
      - Replace (send new item)

RESTOCKING
  ✗ Return to inventory (with condition flag)
  ✗ Restocking fee calculation
  ✗ Damaged inventory handling

REPLACEMENT
  ✗ Create replacement order
  ✗ Link to original order
  ✗ No-charge fulfillment
  ✗ Track replacement shipment

INTEGRATION
  ✗ Link return ↔ refund
  ✗ Link return ↔ credit note
  ✗ Link return ↔ replacement order
  ✗ Notifications at each stage
```

---

## Production Recommendation: Build a Dedicated Return System

### Business Justification

1. **Customer Experience**: Self-service returns reduce support tickets
2. **Operational Efficiency**: Warehouse needs a structured receiving workflow
3. **Financial Accuracy**: Restocking fees need explicit calculation
4. **Inventory Accuracy**: Condition-based restocking ensures correct stock levels
5. **Audit Trail**: Return lifecycle must be trackable from request to resolution
6. **Legal Compliance**: Return window enforcement is required in many jurisdictions

---

## Recommended Architecture

```
┌──────────────────────────────────────────────────────────────────┐
│                      RETURN SYSTEM                               │
├──────────────────────────────────────────────────────────────────┤
│                                                                  │
│  ReturnController                                                │
│    ├── customer: requestReturn(), viewReturn(), cancelReturn()   │
│    └── admin:   list(), show(), authorize(), inspect(),           │
│                 approveRefund(), rejectReturn(), issueReplacement() │
│                                                                  │
│  ReturnService                                                    │
│    ├── requestReturn(orderId, items[], reason, images)           │
│    ├── authorizeReturn(returnId, decision, notes)                │
│    ├── receiveReturn(returnId, receivedAt)                       │
│    ├── inspectReturn(returnId, items[], condition)               │
│    ├── calculateRefund(returnId)                                 │
│    ├── issueReplacement(returnId)                                │
│    └── cancelReturn(returnId)                                    │
│                                                                  │
│  ReturnInspectionService                                         │
│    ├── assessCondition(item, conditionData)                      │
│    ├── calculateRestockingFee(item, condition)                   │
│    └── determineDecision(itemInspections[])                      │
│                                                                  │
│  ReturnRestockingService                                         │
│    ├── restockItem(returnItem, condition)                        │
│    └── markAsDamaged(returnItem)                                 │
│                                                                  │
│  Events                                                          │
│    ├── ReturnRequested   → SendReturnConfirmation (email)        │
│    │                        NotifyAdminOfReturn (dashboard)      │
│    ├── ReturnAuthorized  → SendReturnLabel (email)               │
│    │                        SetReturnDeadline (job)              │
│    ├── ReturnReceived    → NotifyWarehouse (notification)        │
│    ├── ReturnInspected   → CalculateRefund (job)                 │
│    ├── RefundCalculated  → ProcessRefund (job → existing flow)   │
│    └── ReplacementIssued → FulfillReplacement (job)              │
│                                                                  │
└──────────────────────────────────────────────────────────────────┘
```

---

## ReturnStatus Enum — Complete Transition Matrix

### 12-State Enum Design

```php
enum ReturnStatus: string
{
    case PENDING          = 'pending';           // Customer submitted request
    case AWAITING_APPROVAL = 'awaiting_approval'; // Under admin review
    case APPROVED         = 'approved';          // Admin authorized return
    case REJECTED         = 'rejected';          // Admin denied return
    case AWAITING_SHIPMENT = 'awaiting_shipment'; // Waiting for customer to ship
    case IN_TRANSIT       = 'in_transit';        // Customer shipped item back
    case RECEIVED         = 'received';          // Warehouse received package
    case INSPECTING       = 'inspecting';        // Quality inspection in progress
    case INSPECTED        = 'inspected';         // Inspection complete
    case PARTIAL_REFUND   = 'partial_refund';    // Partial refund issued
    case FULL_REFUND      = 'full_refund';       // Full refund issued
    case REPLACEMENT      = 'replacement';       // Replacement item sent
    case CANCELLED        = 'cancelled';         // Customer cancelled return
    case CLOSED           = 'closed';            // Terminal state (all resolved)
}
```

### Full Transition Matrix

```
┌──────────────────┐
│     PENDING      │
└──────┬──────┬────┘
       │      │
       ▼      ▼
AWAITING_APPROVAL  CANCELLED
       │
       │
    ┌──┴──┐
    ▼     ▼
APPROVED REJECTED
    │
    ▼
AWAITING_SHIPMENT
    │
    ▼
IN_TRANSIT
    │
    ▼
RECEIVED
    │
    ▼
INSPECTING
    │
    ▼
INSPECTED
    │
    ├────────────┬────────────┐
    ▼            ▼            ▼
FULL_REFUND  PARTIAL_REFUND  REPLACEMENT
    │            │            │
    └────────────┴────────────┘
                  │
                  ▼
               CLOSED
```

### Allowed Transitions Per State

| From State | Allowed To |
|---|---|
| `pending` | `awaiting_approval`, `cancelled` |
| `awaiting_approval` | `approved`, `rejected`, `cancelled` |
| `approved` | `awaiting_shipment`, `cancelled` |
| `rejected` | *(none — terminal, can be appealed via new request)* |
| `awaiting_shipment` | `in_transit`, `cancelled` |
| `in_transit` | `received`, `cancelled` |
| `received` | `inspecting` |
| `inspecting` | `inspected` |
| `inspected` | `full_refund`, `partial_refund`, `replacement` |
| `full_refund` | `closed` |
| `partial_refund` | `closed` |
| `replacement` | `closed` |
| `cancelled` | *(none — terminal)* |
| `closed` | *(none — terminal)* |

---

## ReturnRequest Model Design

### Fields

| Column | Type | Description |
|---|---|---|
| `id` | bigint (PK) | Auto-increment |
| `uuid` | uuid | Public identifier |
| `rma_number` | string | Return Merchandise Authorization (e.g., `RMA-2026-000001`) |
| `order_id` | bigint (FK) | References `orders.id` |
| `customer_id` | bigint (FK) | References `users.id` |
| `status` | string | ReturnStatus value |
| `return_reason_id` | bigint (FK, nullable) | Reason for return |
| `refund_policy_id` | bigint (FK, nullable) | Applicable refund policy |
| `customer_notes` | text | Customer's explanation |
| `admin_notes` | text | Admin internal notes |
| `inspection_notes` | text | Warehouse inspection notes |
| `restocking_fee_percent` | decimal (default: 0) | Restocking fee percentage |
| `restocking_fee_amount` | decimal (default: 0) | Calculated restocking fee |
| `refund_amount` | decimal (nullable) | Final refund amount after fees |
| `refund_id` | bigint (FK, nullable) | Links to refunds table |
| `credit_note_id` | bigint (FK, nullable) | Links to credit_notes table |
| `replacement_order_id` | bigint (FK, nullable) | Links to replacement order |
| `return_label_url` | string (nullable) | Shipping label for return |
| `tracking_number` | string (nullable) | Return shipment tracking |
| `courier` | string (nullable) | Return courier name |
| `return_window_expires_at` | datetime | Deadline for customer to ship |
| `customer_shipped_at` | datetime | When customer shipped |
| `received_at` | datetime | When warehouse received |
| `inspected_at` | datetime | When inspection completed |
| `approved_at` | datetime | Admin authorization timestamp |
| `rejected_at` | datetime | Admin rejection timestamp |
| `images` | json (nullable) | Customer uploaded images |
| `inspection_images` | json (nullable) | Warehouse inspection images |
| `metadata` | json | Additional metadata |
| `created_at` | timestamp | Created |
| `updated_at` | timestamp | Updated |

---

## ReturnItem Model Design

### Fields

| Column | Type | Description |
|---|---|---|
| `id` | bigint (PK) | Auto-increment |
| `return_request_id` | bigint (FK) | References `return_requests.id` |
| `order_item_id` | bigint (FK) | References `order_items.id` |
| `product_id` | bigint (FK) | References `products.id` |
| `product_variant_id` | bigint (FK, nullable) | References `product_variants.id` |
| `sku` | string | Product SKU |
| `name` | string | Product name at time of return |
| `quantity` | int | Quantity being returned |
| `unit_price` | decimal | Price paid per unit |
| `total_price` | decimal | Total for this item |
| `condition` | string (nullable) | Inspected condition (new, used, damaged, defective) |
| `condition_notes` | text (nullable) | Inspector notes |
| `restocking_fee_percent` | decimal (default: 0) | Per-item restocking fee |
| `restocking_fee_amount` | decimal (default: 0) | Calculated fee |
| `refund_amount` | decimal (nullable) | Refund for this item |
| `is_restocked` | bool (default: false) | Whether item was returned to inventory |
| `is_damaged` | bool (default: false) | Whether item was damaged |
| `created_at` | timestamp | Created |
| `updated_at` | timestamp | Updated |

### Condition Enum

```php
enum ReturnItemCondition: string
{
    case NEW           = 'new';            // Unopened, like new
    case OPENED        = 'opened';         // Opened but unused
    case USED          = 'used';           // Used but functional
    case DEFECTIVE     = 'defective';      // Manufacturing defect
    case DAMAGED       = 'damaged';        // Customer damaged
    case INCOMPLETE    = 'incomplete';     // Missing parts/accessories
    case WRONG_ITEM    = 'wrong_item';     // Wrong item shipped
}
```

---

## Inspection Workflow

### Flow

```
Warehouse receives package
  │
  └──▶ Return status: RECEIVED
        │
        ├── Open package
        ├── Verify contents against return request
        ├── Log any discrepancies
        │
        └──▶ Start inspection
              │
              └──▶ Return status: INSPECTING
                    │
                    ├── For each returned item:
                    │     ├── Assess condition
                    │     ├── Capture photos
                    │     ├── Check completeness (accessories, manuals)
                    │     ├── Test functionality (if applicable)
                    │     └── Log condition notes
                    │
                    └──▶ Complete inspection
                          │
                          └──▶ Return status: INSPECTED
                                │
                                ├──▶ Determine decision:
                                │     ├── All items pass → FULL_REFUND
                                │     ├── Some items used/damaged → PARTIAL_REFUND
                                │     └── Customer wants replacement → REPLACEMENT
                                │
                                ├──▶ Calculate refund:
                                │     refund = total_paid - restocking_fee
                                │
                                └──▶ Process:
                                      ├── FULL_REFUND → trigger RefundApproved
                                      ├── PARTIAL_REFUND → trigger RefundApproved with amount
                                      └── REPLACEMENT → create replacement order
```

### Inspection Decision Matrix

| Condition | Restocking Fee | Refund % | Restock? |
|---|---|---|---|
| NEW (sealed) | 0% | 100% | Yes |
| OPENED (unused) | 10% | 90% | Yes (as open-box) |
| USED (functional) | 20% | 80% | Yes (as used) |
| DEFECTIVE (our fault) | 0% | 100% | No (return to vendor) |
| DAMAGED (customer) | 50% | 50% | No (scrap/discard) |
| INCOMPLETE (missing parts) | 25% | 75% | No |
| WRONG_ITEM (our fault) | 0% | 100% | No (return to vendor) |

---

## Restocking Flow

### Restocking Fee Calculation

```php
class ReturnRestockingService
{
    public function calculateRestockingFee(ReturnItem $item, ReturnItemCondition $condition): array
    {
        $feePercent = match ($condition) {
            ReturnItemCondition::NEW        => 0.00,
            ReturnItemCondition::OPENED     => 0.10,
            ReturnItemCondition::USED       => 0.20,
            ReturnItemCondition::DEFECTIVE  => 0.00,
            ReturnItemCondition::DAMAGED    => 0.50,
            ReturnItemCondition::INCOMPLETE => 0.25,
            ReturnItemCondition::WRONG_ITEM => 0.00,
        };

        $feeAmount = round($item->total_price * $feePercent, 2);
        $refundAmount = $item->total_price - $feeAmount;

        return [
            'fee_percent' => $feePercent,
            'fee_amount' => $feeAmount,
            'refund_amount' => $refundAmount,
        ];
    }
}
```

### Inventory Restocking Logic

```php
class ReturnRestockingService
{
    public function restockItem(ReturnItem $item, ReturnItemCondition $condition): void
    {
        if (!$this->shouldRestock($condition)) {
            $item->update(['is_restocked' => false, 'is_damaged' => true]);
            return;
        }

        $product = $item->product_variant_id
            ? ProductVariant::find($item->product_variant_id)
            : Product::find($item->product_id);

        // Restock with condition-based adjustments
        $product->increment('stock_quantity', $item->quantity);
        $product->decrement('sold_quantity', $item->quantity);

        // For open-box/used items, mark condition for special sale
        if ($condition === ReturnItemCondition::OPENED) {
            // Flag as open-box item
        }

        $item->update(['is_restocked' => true]);
    }

    private function shouldRestock(ReturnItemCondition $condition): bool
    {
        return in_array($condition, [
            ReturnItemCondition::NEW,
            ReturnItemCondition::OPENED,
            ReturnItemCondition::USED,
        ]);
    }
}
```

### Integration with Existing Inventory System

- **Variant products**: Restock to `product_variants.stock_quantity`
- **Simple products**: Restock to `products.stock_quantity`
- **Gift items**: Skip (same as current refund behavior)
- **Rental/Digital**: Not returnable (validate at request time)

---

## Replacement Flow

### Flow

```
Customer requests replacement
  │
  ▼
Admin authorizes return (APPROVED)
  │
  ▼
Customer ships back item
  │
  ▼
Warehouse receives + inspects
  │
  ▼
Decision: REPLACEMENT
  │
  ├──▶ Create replacement order (new order linked to original)
  │     ├── Copy items from original order
  │     ├── Set price to zero (no charge)
  │     ├── Link to original via metadata
  │     └── Set status to 'processing'
  │
  ├──▶ Fulfill replacement
  │     ├── Allocate inventory
  │     ├── Create shipment
  │     └── Notify customer with tracking
  │
  └──▶ Close return
        └── Status: CLOSED
```

### Replacement Order Fields

The existing `orders` table would reuse a new type:

| Field | Value |
|---|---|
| `order.status` | `processing` (or new `replacement` status) |
| `order.metadata.original_order_id` | Original order ID |
| `order.metadata.return_request_id` | Return request ID |
| `order.metadata.is_replacement` | `true` |
| `order.price` | `0.00` (no charge) |
| `order.total_price` | `0.00` |

---

## Integration Points

### Integration with Existing Refund System

```
Return INSPECTED
  │
  ├── Decision: FULL_REFUND
  │     │
  │     └──▶ Call existing refund flow:
  │           ├── Create Refund (amount = full)
  │           ├── Admin approves refund
  │           └── Existing RefundApproved listeners fire:
  │                 ├── RatingRemoved
  │                 ├── RestoreInventoryOnRefund 
  │                 │     └── NOTE: Inventory already restored by
  │                 │          ReturnRestockingService → guard against double
  │                 └── GenerateCreditNoteOnRefund
  │
  ├── Decision: PARTIAL_REFUND
  │     │
  │     └──▶ Call existing refund flow:
  │           ├── Create Refund (amount = partial)
  │           └── Same flow as above
  │
  └── Decision: REPLACEMENT
        │
        └──▶ Create replacement order (NO refund)
              └── Fulfill replacement
```

### Existing Listeners Compatibility

| Existing Listener | Compatibility | Modification Needed |
|---|---|---|
| `RatingRemoved` | Compatible | None — deletes reviews regardless |
| `RestoreInventoryOnRefund` | **Conflict** | If inventory was already restored by `ReturnRestockingService`, this will double-restore. Need `inventory_restored_at` check or skip when return-based refund. |
| `GenerateCreditNoteOnRefund` | Compatible | Creates CN as expected |

### Idempotency for RestoreInventoryOnRefund Conflict

Option 1: Add return-based guard to `RestoreInventoryOnRefund`:
```php
if ($order->inventory_restored_via_return) {
    return;  // Inventory already restored by return system
}
```

Option 2: Set `inventory_restored_at` in the return system so the existing guard works.

---

## Edge Cases & Failure Modes

### 1. Return Window Expired

**Scenario**: Customer submits return request 30 days after delivery, but policy allows 14 days.

**Mitigation**: Check `order.delivered_at + policy.return_window_days` at request time. Reject if expired.

### 2. Item Not in Original Order

**Scenario**: Customer returns an item that was not in the original order (wrong item, swapped).

**Mitigation**: Verify each `ReturnItem.order_item_id` belongs to the order. Include serial number tracking for high-value items.

### 3. Partial Return with Discount/Promotion

**Scenario**: Customer returns 1 of 3 items purchased with a "buy 2 get 1 free" promotion.

**Challenge**: How to recalculate the discount applied to the returned item.

**Approach**: Proportional discount allocation:
```
refund = (item_price / order_subtotal) × total_paid
```

### 4. Return of Gift Items

**Scenario**: Customer received a free gift item and wants to return it.

**Mitigation**: Gift items (`is_gift = true`) should be included in the return but marked as non-refundable (value = 0).

### 5. Multiple Returns for Same Order

**Scenario**: Customer returns items in multiple batches.

**Design**: Supported — multiple return requests can reference the same order. Each return tracks its own items.

### 6. Return After Refund Already Issued

**Scenario**: Customer receives refund (via support ticket), then submits a return request.

**Mitigation**: Check if order already has a completed refund before allowing return. Could link return to existing refund.

### 7. Inspection Discrepancy

**Scenario**: Customer claims item is "new" but warehouse finds it "used".

**Mitigation**: 
- Capture inspection photos as evidence
- Allow customer to dispute
- Admin override with notes

### 8. Lost Return Shipment

**Scenario**: Customer shipped item but it never arrived.

**Mitigation**: 
- Require tracking number at shipment
- Automatically mark as lost after X days without receipt
- Courier claim process

### 9. Restocking Fee Dispute

**Scenario**: Customer disputes the restocking fee.

**Mitigation**: Admin can override fee per return item or waive entirely. Log override reason.

### 10. Replacement Item Out of Stock

**Scenario**: The replacement item is no longer in stock.

**Mitigation**: 
- Notify admin to offer alternatives
- Default to full refund if replacement unavailable
- Backorder option

### 11. RMA Number Generation

**Design**: Reuse `InvoiceNumberService` with a new series `RMA`:

```php
$rmaData = $this->numberService->generateNext('RMA');
// Returns: RMA-2026-000001
```

### 12. Return → Refund Loop Prevention

**Scenario**: A return triggers a refund, which triggers inventory restoration, but inventory was already restored by the return restocking.

**Mitigation**: 
- Set `Order.inventory_restored_at` during return restocking
- `RestoreInventoryOnRefund` checks this field and exits early
- OR skip inventory restoration in `RestoreInventoryOnRefund` when refund originates from a return

---

## Implementation Roadmap

### Phase 1: Foundation (Week 1-2)

| Task | Effort | Dependencies |
|---|---|---|
| Create `ReturnStatus` enum with transition matrix | 2h | None |
| Create `ReturnRequest` model + migration | 4h | None |
| Create `ReturnItem` model + migration | 3h | None |
| Create `ReturnReason` model + seed data | 2h | None |
| Create `ReturnService` with `requestReturn()` | 8h | Models |

### Phase 2: Authorization (Week 2-3)

| Task | Effort | Dependencies |
|---|---|---|
| Create `ReturnController` + permission middleware | 4h | Phase 1 |
| Create admin authorize/reject flow | 4h | Phase 1 |
| Add RMA number generation via `InvoiceNumberService` | 1h | Phase 1 |
| Add return window validation | 2h | Refund policy |
| Create `ReturnAuthorized` / `ReturnRejected` events | 2h | Phase 1 |
| Add email notifications | 4h | Events |

### Phase 3: Warehouse/Inspection (Week 3-4)

| Task | Effort | Dependencies |
|---|---|---|
| Create `ReturnInspectionService` | 8h | Phase 1 |
| Create warehouse receive flow | 4h | Phase 1 |
| Create inspection form + condition assessment | 6h | Phase 1 |
| Add inspection image upload | 3h | Media library |
| Create `ReturnInspected` event | 2h | Phase 1 |

### Phase 4: Refund/Replacement (Week 4-5)

| Task | Effort | Dependencies |
|---|---|---|
| Create refund calculation with restocking fees | 6h | Phase 3 |
| Integrate with existing refund system | 4h | Phase 3 |
| Create replacement order flow | 8h | Order system |
| Add `ReturnRestockingService` | 4h | Phase 3 |
| Integration testing | 8h | All above |

### Phase 5: Polish (Week 5-6)

| Task | Effort | Dependencies |
|---|---|---|
| Customer self-service return portal (API) | 6h | Phase 2 |
| Admin dashboard return views | 8h | Phase 3 |
| Return label generation (courier API) | 16h | Courier integration |
| Analytics/reporting | 4h | Phase 4 |
| Load testing | 4h | Phase 4 |

### Total Estimated Effort: ~120 hours (3 weeks for 1 developer)

---

## Summary

| Aspect | Current State | Target State |
|---|---|---|
| Return request | Via refund model only | Dedicated `ReturnRequest` model |
| Return status | No status tracking | 12-state `ReturnStatus` enum |
| RMA number | None | Gapless RMA sequence |
| Inspection | No process | Full warehouse inspection workflow |
| Restocking | No fee | Condition-based fee calculation |
| Replacement | Not possible | Replacement order flow |
| Inventory | Restored on refund | Restored on inspection decision |
| Notifications | Refund emails only | Full return lifecycle emails |
| Self-service | Phone/email only | API + portal |
| Audit trail | Implicit | Complete timeline |
