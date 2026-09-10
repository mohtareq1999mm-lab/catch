# PHASE 10: REFUND LIFECYCLE

> Production Operations Manual — Refund Lifecycle Management
> Last Updated: 2026-07-28

---

## TABLE OF CONTENTS

1. [Architecture Overview](#architecture-overview)
2. [Refund Model](#refund-model)
3. [RefundStatus Enum](#refundstatus-enum)
4. [Event Architecture](#event-architecture)
5. [RefundApproved Event & Listeners](#refundapproved-event--listeners)
6. [RatingRemoved Listener](#ratingremoved-listener)
7. [RestoreInventoryOnRefund Listener](#restoreinventoryonrefund-listener)
8. [GenerateCreditNoteOnRefund Listener](#generatecreditonoteonrefund-listener)
9. [RefundRequested (Marvel) Event & Notification](#refundrequested-marvel-event--notification)
10. [RefundUpdate (Marvel) Event & Notification](#refundupdate-marvel-event--notification)
11. [Payment Gateway Refund](#payment-gateway-refund)
12. [Event Flow Diagram](#event-flow-diagram)
13. [Database Schema](#database-schema)
14. [Edge Cases & Failure Modes](#edge-cases--failure-modes)
15. [Production Recommendations](#production-recommendations)

---

## Architecture Overview

```
Customer requests refund → Refund::create()
  └─▶ RefundRequested (Marvel event — model event)
        └─▶ SendRefundRequestedNotification (mail to admin)

Admin approves refund → Refund::update(['status' => 'approved'])
  └─▶ RefundUpdate (Marvel event — model event)
  │     └─▶ SendRefundUpdateNotification (mail to customer + admin)
  │
  └─▶ RefundApproved::dispatch() (App event — explicit dispatch)
        ├─▶ RatingRemoved (sync) — deletes reviews
        ├─▶ RestoreInventoryOnRefund (queue:medium) — restores stock
        └─▶ GenerateCreditNoteOnRefund (queue:medium) — creates CN, marks invoice corrected
```

### Two Event Systems

This codebase has TWO different `RefundApproved` event classes:

| Event | Namespace | File | Dispatch Method |
|---|---|---|---|
| `RefundApproved` | `App\Events\` | `app/Events/RefundApproved.php` | Explicit `RefundApproved::dispatch()` |
| `RefundApproved` | `Marvel\Events\` | (NOT FOUND — referenced by listeners) | (presumed model event) |

The listeners reference `App\Events\RefundApproved`:
- `RatingRemoved` uses `App\Events\RefundApproved`
- `RestoreInventoryOnRefund` uses `App\Events\RefundApproved`
- `GenerateCreditNoteOnRefund` uses `App\Events\RefundApproved`

---

## Refund Model

Source: `packages/marvel/src/Database/models/Refund.php`

### Table

```php
protected $table = 'refunds';
```

### Guarded

```php
public $guarded = [];  // ALL fields mass-assignable
```

**IMPORTANT**: `$guarded = []` means all attributes are mass-assignable. Ensure validation is thorough in Form Requests.

### Casts

```php
protected $casts = [
    'images' => 'json',
];
```

### Model Events (auto-dispatched)

```php
protected $dispatchesEvents = [
    'created' => RefundRequested::class,   // Marvel\Events\RefundRequested
    'updated' => RefundUpdate::class,      // Marvel\Events\RefundUpdate
];
```

### Relations

| Method | Type | Target |
|---|---|---|
| `customer()` | BelongsTo | `User` via `customer_id` |
| `order()` | BelongsTo | `Order` via `order_id` |
| `shop()` | BelongsTo | `Shop` via `shop_id` |
| `refund_policy()` | BelongsTo | `RefundPolicy` via `refund_policy_id` |
| `refund_reason()` | BelongsTo | `RefundReason` via `refund_reason_id` |

### Key Fields (from usage analysis)

Based on how the model is used in events, listeners, and notifications:

| Field | Type | Description |
|---|---|---|
| `id` | bigint (PK) | Auto-increment |
| `amount` | decimal | Refund amount |
| `title` | string | Refund title/description |
| `description` | text | Detailed description |
| `order_id` | bigint (FK) | References `orders.id` |
| `customer_id` | bigint (FK) | References `users.id` |
| `shop_id` | bigint (FK) | References `shops.id` |
| `status` | string | RefundStatus value |
| `images` | json | Supporting images |
| `refund_policy_id` | bigint (FK) | References `refund_policies.id` |
| `refund_reason_id` | bigint (FK) | References `refund_reasons.id` |

---

## RefundStatus Enum

Source: `packages/marvel/src/Enums/RefundStatus.php`

### Values

```php
final class RefundStatus extends Enum
{
    public const APPROVED  = 'approved';
    public const PENDING   = 'pending';
    public const REJECTED  = 'rejected';
    public const PROCESSING = 'processing';
}
```

**Note**: This is a `BenSampo\Enum\Enum` (PHP 7 style, not a native PHP 8 enum). It uses constants, not cases.

### Status Descriptions

| Value | Description |
|---|---|
| `pending` | Customer requested refund, awaiting admin review |
| `processing` | Admin is processing the refund |
| `approved` | Refund approved, inventory restored, credit note generated |
| `rejected` | Refund rejected by admin |

### Missing: Transition Matrix

Unlike `InvoiceStatus` and `ShipmentStatus` which have complete `allowedTransitions()` methods, `RefundStatus` has **NO transition validation**. Status changes are not validated at the model or enum level.

### Missing: Cancelled Status

There is no `cancelled` status. If a customer wants to cancel a refund request, there's no explicit status for that.

---

## Event Architecture

### Two RefundApproved Classes

**IMPORTANT**: There are TWO different `RefundApproved` event classes that must be distinguished:

```
App\Events\RefundApproved                 (used by listeners)
  └─ public $refund (Marvel Refund model)
  └─ App\Events namespace

Marvel\Events\RefundApproved              (referenced by imports?)
  └─ Found only via imports in listener classes
  └─ Marvel\Events namespace
```

The listeners in `app/Listeners/` import `use App\Events\RefundApproved;`.

### Event Wiring (from source code analysis)

| Event | Listener | Sync/Async | Queue |
|---|---|---|---|
| `Refund::created` (model event) | `Marvel\Events\RefundRequested::class` | — | — |
| `Refund::updated` (model event) | `Marvel\Events\RefundUpdate::class` | — | — |
| `Marvel\Events\RefundRequested` | `Marvel\Notifications\RefundRequested` (via listener) | Async | default |
| `Marvel\Events\RefundUpdate` | `Marvel\Notifications\RefundUpdate` (via listener) | Async | default |
| `App\Events\RefundApproved` (explicit) | `RatingRemoved` | Sync | — |
| `App\Events\RefundApproved` (explicit) | `RestoreInventoryOnRefund` | Async | `medium` |
| `App\Events\RefundApproved` (explicit) | `GenerateCreditNoteOnRefund` | Async | `medium` |

### Where RefundApproved is Dispatched

The `RefundApproved` event must be explicitly dispatched somewhere (likely in an admin controller or service) when a refund is approved. Based on the listeners that handle it:

```php
// Presumed dispatch point (not found in current code scan):
$refund->update(['status' => RefundStatus::APPROVED]);
RefundApproved::dispatch($refund);
```

---

## RefundApproved Event & Listeners

Source: `app/Events/RefundApproved.php`

### Event

```php
class RefundApproved
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public $refund;  // Marvel\Database\Models\Refund

    public function __construct(Refund $refund)
    {
        $this->refund = $refund;
    }
}
```

### Listener Chain

```
RefundApproved::dispatch($refund)
  │
  ├──▶ RatingRemoved (sync)
  │     └── Deletes all reviews for user+order
  │
  ├──▶ RestoreInventoryOnRefund (queue:medium)
  │     └── Restores stock_quantity, decrements sold_quantity
  │     └── Idempotent via inventory_restored_at
  │
  └──▶ GenerateCreditNoteOnRefund (queue:medium)
        └── Creates CN, marks invoice as corrected
```

---

## RatingRemoved Listener

Source: `packages/marvel/src/Listeners/RatingRemoved.php`

### Behavior

```php
public function handle(RefundApproved $event)
{
    Review::where('user_id', $event->refund->customer_id)
        ->where('order_id', $event->refund->order_id)
        ->delete();
}
```

### What It Does

- Deletes ALL reviews submitted by the customer for the refunded order
- Synchronous — runs immediately in the dispatch process

### Edge Cases

| Scenario | Behavior |
|---|---|
| No reviews exist | No-op (no error) |
| Multiple reviews for same order | All deleted |
| Refund is partial (single item) | ALL item reviews for the order deleted, not just the refunded item |
| Customer_id is null | `where('user_id', null)` matches no records |

**Production Concern**: Deleting ALL reviews on ANY refund (even partial) is aggressive. A partial refund on one item should ideally only remove the review for that item, not the entire order's reviews.

---

## RestoreInventoryOnRefund Listener

Source: `app/Listeners/RestoreInventoryOnRefund.php`

### Queue Configuration

| Property | Value |
|---|---|
| Queue | `medium` |
| `$afterCommit` | `true` |
| Type | `ShouldQueue` |

### Idempotency Mechanism

```php
$updated = Order::whereKey($order->id)
    ->whereNull('inventory_restored_at')
    ->lockForUpdate()
    ->update(['inventory_restored_at' => now()]);

if ($updated === 0) {
    return;  // Already restored — idempotent guard
}
```

Uses a database-level idempotency check:
1. `Order.inventory_restored_at` starts as `null`
2. On first execution, sets it to `now()`
3. On subsequent executions (duplicate job), the `WHERE inventory_restored_at IS NULL` condition fails, so the update returns 0 affected rows and the listener exits early

### Inventory Restoration Logic

```php
foreach ($orderItems as $item) {
    if ($item->is_gift) {
        continue;  // Skip gift items
    }

    if ($item->product_variant_id) {
        // Restore variant stock
        $variant = ProductVariant::lockForUpdate()->find($item->product_variant_id);
        if ($variant) {
            $variant->stock_quantity = max(0, (int) $variant->stock_quantity + (int) $item->product_quantity);
            $variant->sold_quantity = max(0, (int) $variant->sold_quantity - (int) $item->product_quantity);
            $variant->save();
        }
    } else {
        // Restore product stock (if not rental or digital)
        $product = Product::lockForUpdate()->find($item->product_id);
        if ($product && !$product->is_rental && !$product->is_digital) {
            $product->stock_quantity = max(0, (int) $product->stock_quantity + (int) $item->product_quantity);
            $product->sold_quantity = max(0, (int) $product->sold_quantity - (int) $item->product_quantity);
            $product->save();
        }
    }
}
```

### Skipped Item Types

| Item Type | Skipped? | Reason |
|---|---|---|
| Gift items (`is_gift = true`) | YES | Gifts don't consume paid inventory |
| Rental products (`is_rental = true`) | YES | Rental stock is managed separately |
| Digital products (`is_digital = true`) | YES | Digital has no physical inventory to restore |
| Variants (with `product_variant_id`) | NO | Restores variant stock |
| Regular products (no variant) | NO | Restores product stock |

### Concurrent Safety

Each product/variant is locked with `lockForUpdate()` inside the transaction. The order-level idempotency check also uses `lockForUpdate()`.

### Error Handling

```php
catch (Exception $th) {
    \Log::error('Error restoring inventory on refund: ' . $th->getMessage());
    throw $th;  // Re-throw for queue retry
}
```

---

## GenerateCreditNoteOnRefund Listener

Source: `app/Listeners/GenerateCreditNoteOnRefund.php`

### Queue Configuration

| Property | Value |
|---|---|
| Queue | `medium` |
| `$afterCommit` | `true` |
| Type | `ShouldQueue` |

### Behavior

```php
public function handle(RefundApproved $event): void
{
    // 1. Find the latest active invoice for this order
    $invoice = Invoice::where('order_id', $order->id)
        ->whereIn('status', ['generated', 'ready', 'verified', 'downloaded', 'printed'])
        ->latest()
        ->first();

    if (!$invoice) {
        Log::warning('No active invoice found for refund credit note');
        return;
    }

    // 2. Generate credit note (CN series)
    $this->creditNoteService->generateForRefund(
        $invoice,
        (float) ($refund->amount ?? $order->total_price ?? 0),
        'Refund approved: ' . ($refund->title ?? 'No reason provided'),
    );

    // 3. Mark invoice as corrected
    $invoice->update([
        'status' => 'corrected',
        'corrected_at' => now(),
        'correction_reason' => 'Refund approved for order #' . $order->id,
    ]);

    // 4. Record timeline
    $this->timelineService->recordCorrected($invoice, 'Refund approved');
}
```

### Key Details

- Uses `CreditNoteService::generateForRefund()` (series `CN`, type `refund`)
- Only marks an invoice as corrected if its status is one of: `generated`, `ready`, `verified`, `downloaded`, `printed`
- If no eligible invoice exists, logs a warning and exits without error
- Amount defaults to `$refund->amount ?? $order->total_price ?? 0`

### Invoice Status Flow

```
Before refund:
  Invoice status: generated / ready / verified / downloaded / printed

After refund approved:
  Invoice status: corrected
  Credit note:    CN-2026-xxxxx (type: refund)
  Timeline:       corrected event recorded
```

### Race Condition

If two refunds are approved for the same order, the second listener will:
1. Find the invoice (still `corrected` — NOT in the allowed list)
2. Not match any active invoice
3. Log a warning and exit

This is safe — only the first refund creates a credit note.

---

## RefundRequested (Marvel) Event & Notification

Source: `packages/marvel/src/Events/RefundRequested.php`

### Event

```php
namespace Marvel\Events;

class RefundRequested
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public Refund $refund;

    public function __construct(Refund $refund)
    {
        $this->refund = $refund;
    }
}
```

### Auto-Dispatch

Triggered by the `Refund` model's `$dispatchesEvents`:

```php
protected $dispatchesEvents = [
    'created' => RefundRequested::class,
];
```

### Notification: RefundRequested (Marvel)

Source: `packages/marvel/src/Notifications/RefundRequested.php`

Implements `ShouldQueue`. Sends email via `MailMessage`.

**Admin Email** (receiver = 'admin'):
- Subject: `__('sms.order.refundRequested.admin.subject')`
- Link: `config('shop.dashboard_url') . '/refunds/' . $refund->id`
- Template: `emails.refund.refund-updated`

**Customer Email** (receiver = 'customer'):
- Subject: `__('sms.order.refundRequested.customer.subject')`
- Link: `config('shop.shop_url') . '/orders/' . $order->tracking_id`
- Template: `emails.refund.refund-requested`

---

## RefundUpdate (Marvel) Event & Notification

Source: `packages/marvel/src/Events/RefundUpdate.php`

### Event

```php
namespace Marvel\Events;

class RefundUpdate
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public Refund $refund;

    public function __construct(Refund $refund)
    {
        $this->refund = $refund;
    }
}
```

### Auto-Dispatch

Triggered by the `Refund` model's `$dispatchesEvents`:

```php
protected $dispatchesEvents = [
    'updated' => RefundUpdate::class,
];
```

### Notification: RefundUpdate (Marvel)

Source: `packages/marvel/src/Notifications/RefundUpdate.php`

Implements `ShouldQueue`. Sends email via `MailMessage`.

**Admin Email** (receiver = 'admin'):
- Subject: `__('sms.order.refundStatusChange.admin.subject')`
- Template: `emails.refund.refund-updated`

**Customer Email** (receiver = 'customer'):
- Subject: `__('sms.order.refundStatusChange.customer.subject')`
- Template: `emails.refund.refund-updated`

Both emails include the refund `status` in the body.

---

## Payment Gateway Refund

### MyFatoorah Gateway

The actual payment gateway refund is handled by **MyFatoorah** and is NOT part of this codebase. The application:
1. Does NOT call the payment gateway API for refunds
2. Does NOT track refund transaction status
3. Does NOT have a refund transaction ID stored on the refund or credit note

The `CreditNoteService::generateForRefund()` accepts an optional `$refundTransactionId` parameter but the `GenerateCreditNoteOnRefund` listener does NOT pass it (passes `null`).

### Production Gap

There is no integration between the refund approval in the app and the actual money movement in MyFatoorah. The flow should be:

```
Admin approves refund → RefundApproved::dispatch()
  ├──▶ MyFatoorahRefundJob (queue:high) — calls MyFatoorah API
  │     └──▶ On success: store gateway_refund_id, update refund status
  │     └──▶ On failure: mark refund as failed, notify admin
  │
  ├──▶ RestoreInventoryOnRefund (only after payment confirmed)
  └──▶ GenerateCreditNoteOnRefund
```

Current flow restores inventory and creates CN **regardless of whether the payment gateway refund succeeded**. This is a financial risk.

---

## Event Flow Diagram

```
┌──────────────────────────────────────────────────────────────┐
│                    REFUND LIFECYCLE                          │
├──────────────────────────────────────────────────────────────┤
│                                                              │
│  CUSTOMER                                                    │
│    │                                                         │
│    ▼                                                         │
│  Submit Refund Request                                       │
│    │                                                         │
│    ▼                                                         │
│  Refund::create([...])                                       │
│    │                                                         │
│    ├── dispatchesEvents['created']                           │
│    │   └── Marvel\Events\RefundRequested                     │
│    │       └── Marvel\Notifications\RefundRequested          │
│    │           ├── Email to admin (dashboard link)           │
│    │           └── Email to customer (order link)            │
│    │                                                         │
│    ▼                                                         │
│  ADMIN                                                       │
│    │                                                         │
│    ├── Reviews refund request                                │
│    ├── Can approve / reject                                  │
│    │                                                         │
│    ▼                                                         │
│  Refund::update(['status' => 'approved'])                    │
│    │                                                         │
│    ├── dispatchesEvents['updated']                           │
│    │   └── Marvel\Events\RefundUpdate                        │
│    │       └── Marvel\Notifications\RefundUpdate             │
│    │           ├── Email to admin                            │
│    │           └── Email to customer                         │
│    │                                                         │
│    └── Explicit: RefundApproved::dispatch($refund)           │
│        │                                                     │
│        ├──▶ RatingRemoved (SYNC)                             │
│        │     └── DELETE reviews WHERE user+order             │
│        │                                                     │
│        ├──▶ RestoreInventoryOnRefund (QUEUE: medium)         │
│        │     ├── Idempotent check (inventory_restored_at)    │
│        │     ├── Skip: gifts, rentals, digital               │
│        │     ├── Variant: stock++, sold--                    │
│        │     └── Product: stock++, sold--                    │
│        │                                                     │
│        └──▶ GenerateCreditNoteOnRefund (QUEUE: medium)      │
│              ├── Find active invoice for order               │
│              ├── CreditNoteService::generateForRefund()      │
│              │   └── Series: CN, type: refund                │
│              ├── Invoice status → 'corrected'                │
│              └── Timeline: corrected                         │
│                                                              │
└──────────────────────────────────────────────────────────────┘
```

---

## Database Schema

### `refunds` Table

| Column | Type | Description |
|---|---|---|
| `id` | bigint (PK) | Auto-increment |
| `amount` | decimal | Refund amount |
| `title` | string | Refund title |
| `description` | text | Refund description |
| `order_id` | bigint (FK) | References `orders.id` |
| `customer_id` | bigint (FK) | References `users.id` |
| `shop_id` | bigint (FK, nullable) | References `shops.id` |
| `status` | string | RefundStatus value |
| `images` | json (nullable) | Supporting images |
| `refund_policy_id` | bigint (FK, nullable) | References `refund_policies.id` |
| `refund_reason_id` | bigint (FK, nullable) | References `refund_reasons.id` |
| `created_at` | timestamp | Created |
| `updated_at` | timestamp | Updated |

### Additional Columns Detected via Usage

From `RefundStatus` enum usage, the `refunds` table likely has a `status` column that stores one of: `pending`, `processing`, `approved`, `rejected`.

---

## Edge Cases & Failure Modes

### 1. Idempotency: Duplicate RefundApproved Dispatch

**Problem**: `RefundApproved::dispatch()` is called twice for the same refund (e.g., admin double-clicks, job retry).

**Mitigation**: 
- `RestoreInventoryOnRefund` uses `inventory_restored_at` — second execution exits early
- `GenerateCreditNoteOnRefund` finds invoice by status — second execution finds invoice in `corrected` status (not in allowed list), logs warning, exits

**No Mitigation**: `RatingRemoved` has no idempotency — `DELETE` is idempotent by nature.

### 2. Refund Approved Without Payment Gateway Refund

**Problem**: Inventory is restored and credit note generated before the payment gateway confirms the refund.

**Risk**: If the payment gateway refund fails, the inventory has already been restored (out-of-sync).

**Recommendation**: Restructure the flow:
1. Dispatch `MyFatoorahRefundJob` first
2. On payment gateway success, then dispatch `RefundApproved`
3. Or use compensating transactions if inventory restoration fails

### 3. Order Cancelled + Refund

**Problem**: Order is cancelled, then refund is approved. `RestoreInventoryOnRefund` checks:

```php
if (!$order || $order->status === 'cancelled') {
    return;  // Skip inventory restoration
}
```

If the order is already cancelled, inventory is NOT restored. This assumes inventory was already restored during cancellation. If not, inventory becomes permanently out-of-sync.

### 4. Partial Refund — All Reviews Deleted

**Problem**: Refunding a single item in a multi-item order deletes ALL reviews for the entire order. See [RatingRemoved Listener](#ratingremoved-listener) edge cases.

### 5. Credit Note for Already-corrected Invoice

**Problem**: Invoice was already corrected for a previous reason, then refund is approved.

**Behavior**: `GenerateCreditNoteOnRefund` looks for invoice with status in: `generated`, `ready`, `verified`, `downloaded`, `printed`. If the invoice was already corrected, its status is `corrected`. The listener logs a warning and exits without creating a credit note.

**Impact**: No credit note is generated. The refund is processed (inventory restored, reviews deleted) but no financial document records the refund.

### 6. Missing RefundTransactionId

**Problem**: `GenerateCreditNoteOnRefund` passes `null` for `$refundTransactionId`.

**Impact**: The credit note has no reference to a payment gateway transaction. Cannot trace the refund to the actual money movement.

### 7. No Refund Policy Enforcement

**Problem**: There is no validation of refund window (e.g., "returns accepted within 14 days"), no restocking fee calculation, no condition assessment. These are presumably handled at the admin UI level.

### 8. Refund After Shipping

**Problem**: If the order was already shipped, approving a refund does not automatically trigger a return. See [Phase 11: Return Lifecycle](PHASE-11-RETURN-LIFECYCLE.md).

---

## Production Recommendations

### Priority Matrix

| Priority | Item | Effort | Impact |
|---|---|---|---|
| **P0 — CRITICAL** | Integrate payment gateway refund before inventory/CN | 16h | Financial |
| **P0 — CRITICAL** | Add transition validation to RefundStatus | 2h | Data integrity |
| **P1 — HIGH** | Add refund cancelled status | 1h | Completeness |
| **P1 — HIGH** | Pass refund transaction ID from gateway to credit note | 4h | Audit trail |
| **P2 — MEDIUM** | Partial refund: only delete reviews for refunded items | 4h | User experience |
| **P2 — MEDIUM** | Add refund policy time window enforcement | 8h | Business rules |
| **P3 — LOW** | Add refund audit log (similar to InvoiceTimeline) | 4h | Audit trail |
| **P3 — LOW** | Notify customer on refund rejection | 2h | Communication |

### Recommended RefundFlow Refactoring

```
Admin approves refund
  │
  ├──▶ Update refund status to 'processing'
  │
  ├──▶ Dispatch: ProcessPaymentGatewayRefund (queue:high)
  │     ├── Call MyFatoorah API
  │     ├── On success: update refund with gateway_refund_id
  │     │   └── Dispatch: RefundPaymentSucceeded
  │     │         ├── RestoreInventoryOnRefund
  │     │         ├── GenerateCreditNoteOnRefund
  │     │         └── Update refund status to 'approved'
  │     │
  │     └── On failure: update refund status to 'failed'
  │         └── Notify admin
  │
  └──▶ (OLD flow removed — no immediate inventory/CN)
```

---

## Key Files Reference

| File | Purpose |
|---|---|
| `packages/marvel/src/Enums/RefundStatus.php` | 4-value status enum |
| `packages/marvel/src/Database/Models/Refund.php` | Eloquent model |
| `app/Events/RefundApproved.php` | Refund approved event (App namespace) |
| `app/Listeners/RatingRemoved.php` | Sync listener: deletes reviews |
| `app/Listeners/RestoreInventoryOnRefund.php` | Queue listener: restores stock |
| `app/Listeners/GenerateCreditNoteOnRefund.php` | Queue listener: creates CN, marks corrected |
| `packages/marvel/src/Events/RefundRequested.php` | Refund requested event (Marvel) |
| `packages/marvel/src/Events/RefundUpdate.php` | Refund updated event (Marvel) |
| `packages/marvel/src/Notifications/RefundRequested.php` | Email notification on request |
| `packages/marvel/src/Notifications/RefundUpdate.php` | Email notification on update |
| `app/Services/Invoice/CreditNoteService.php` | Credit note generation (CN series) |
