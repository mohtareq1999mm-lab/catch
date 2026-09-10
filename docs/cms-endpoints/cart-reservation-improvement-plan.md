# Cart Reservation Improvement Plan

## Current Behavior

### Reservation Flow

1. **Add to Cart** → `CartInventoryService::reserveItem()`
   - Pessimistic lock on Product/Variant row
   - `reserved_quantity += delta` (stock is NOT deducted, only marked as reserved)
   - `expires_at = now + 3 days`

2. **Checkout** → `ensureCartReservation()`
   - Re-syncs reservation with current stock
   - Refreshes `expires_at`

3. **Payment Success** → `finalizeItemsByShippingMethod()` or `finalizeCart()`
   - `stock_quantity -= quantity` (physical deduction)
   - `reserved_quantity -= quantity` (reservation cleared)
   - `sold_quantity += quantity`

4. **Cancellation** → `releaseCart()`
   - `reserved_quantity -= quantity` (reservation released)
   - Cart back to `active`

5. **Expiry** → `expireCart()` (via cron)
   - Same as cancellation
   - Cart → `expired`
   - Items deleted

### Physical Stock Equation

```
available_stock = stock_quantity - reserved_quantity
```

During reservation: `reserved_quantity ↑`, `available_stock ↓`. Physical `stock_quantity` unchanged. This allows other customers to see accurate real-time availability while the first customer has a temporary hold.

---

## Risks

### P1 — "Accept-Pay" Scenario (Browser Abandonment)

**Scenario:**
1. User adds product to cart → reserved
2. User completes MyFatoorah payment → browser closed before callback
3. MyFatoorah has `Paid` status
4. Callback never arrives (or arrives after cart expires)
5. After 3 days → `expireCart()` releases reservation
6. Another customer buys the same stock
7. Original order is still `pending` with `Paid` transaction

**Impact:**
- First customer: charged but no fulfillment (order stuck at `pending`)
- Second customer: receives the product
- Admin must manually reconcile

**Resolution:** Admin can see the `Paid` transaction linked to a `pending` order and manually complete it. However, if the stock was sold to the second customer, the order can't be fulfilled.

### P2 — Stock Double-Restore in Cancellation Path

**Scenario:**
1. User creates order via online payment
2. MyFatoorah callback fails → `changeOrderStatus('cancelled')`
3. `OrderCancelled` event fires → `RestoreProductInventory` queued
4. `releaseCart()` releases reserved stock
5. `RestoreProductInventory` runs: adds product_quantity to `stock_quantity`

**Problem:** Since `finalizeStock` was never called (payment failed), `stock_quantity` was never decremented. Step 5 incorrectly adds back stock that was never subtracted. Available stock increases by 2x order quantity.

**Severity:** High. Overstates available stock.

### P3 — COD Never Picked Up

**Scenario:**
1. User orders via COD
2. Cart is finalized immediately (stock deducted)
3. User never picks up the order
4. No automatic mechanism to restore stock

**Resolution:** Admin must cancel the order, which fires `OrderCancelled` → `RestoreProductInventory` restores stock. No automated timeout for COD orders.

### P4 — Cart Expiry with Mix of Scheduled and Fast Items

The cart can have items with different `shipping_method` values (SCHEDULED and FAST). When the cart expires:
- `expireCart()` releases ALL items regardless of shipping method
- If only one shipping method's payment succeeds, the other items remain in the cart
- The callback uses `finalizeItemsByShippingMethod()` to finalize only the paid shipping method's items
- Remaining items are still reserved in the cart

**Risk:** If the remaining items expire before the customer completes their second checkout, those items' stock is released without notification.

---

## Recommended Architecture

### Short-Term Fixes (1-2 hours each)

#### Fix P2: Guard `RestoreProductInventory` Against Double-Restore

Add a check in `RestoreProductInventory` to verify the stock was actually finalized before restoring:

```php
// In RestoreProductInventory::handle()
if ($event->order->status !== 'cancelled') {
    return; // Only restore on cancellation
}

// Check if stock was already deducted (i.e., finalized)
$order = $event->order;
if ($order->getOriginal('status') === 'completed') {
    // Stock was finalized, proceed with restore
} else {
    // Stock was never finalized (payment failed pre-finalize), skip restore
    return;
}
```

**Note:** This requires the `OrderCancelled` event to be dispatched BEFORE `changeOrderStatus()` is called, or the listener needs access to both old and new status.

#### Fix P1: Add a Payment Verification Job

Create a scheduled job that finds orders with `status = 'pending'` and associated `transaction.status = 'paid'` that are older than N hours. For each:
1. Verify the payment status with MyFatoorah
2. If still `Paid` → finalize the cart and complete the order
3. If `Failed` → cancel the order and release the cart

### Medium-Term (4-6 hours)

#### Implement a "Soft Reservation" with Heartbeat

**Current:** Reservation is locked until checkout (3 day TTL).

**Proposed:** Add a `reserved_at` timestamp. If the user hasn't interacted with the cart in N minutes after checkout starts, release the reservation automatically. This prevents abandoned checkout from blocking inventory for 3 days.

```php
Schema::table('carts', function (Blueprint $table) {
    $table->timestamp('checkout_started_at')->nullable()->after('reserved_at');
});
```

When checkout begins → set `checkout_started_at = now()`. A cron job checks:
```
checkout_started_at IS NOT NULL
AND expires_at < now() - 30 min
AND status = 'active'
```
→ Release cart items back to available stock.

### Long-Term (engineering project)

#### Event Sourcing for Inventory

Instead of mutating `stock_quantity` directly, record inventory events:
```
{ type: 'reserve', product_id: 1, quantity: 2, cart_id: 42 }
{ type: 'finalize', product_id: 1, quantity: 2, order_id: 99 }
{ type: 'release', product_id: 1, quantity: 2, cart_id: 42 }
```

Current stock is computed by summing events. This makes the system auditable and eliminates double-restore bugs because each event is idempotent.

---

## Implementation Phases

### Phase 1 (Immediate, ~2 hours)

| Step | Description | Risk Mitigated |
|------|-------------|----------------|
| 1.1 | Guard `RestoreProductInventory` with status check | P2 |
| 1.2 | Add `PaymentVerificationJob` scheduled task | P1 |
| 1.3 | Add test for double-restore scenario | Regression |

### Phase 2 (Short-term, ~4 hours)

| Step | Description | Risk Mitigated |
|------|-------------|----------------|
| 2.1 | Add `checkout_started_at` column + heartbeat release | P1 (abandonment) |
| 2.2 | Add admin command to reconcile paid-but-pending orders | P3 |
| 2.3 | Add notification when COD order is N days old without pickup | P3 |

### Phase 3 (Medium-term, ~8 hours)

| Step | Description | Risk Mitigated |
|------|-------------|----------------|
| 3.1 | Design inventory event schema | All |
| 3.2 | Create `InventoryEvent` model + migration | All |
| 3.3 | Migrate reservation/finalize/release to event-driven | All |
| 3.4 | Add event replay for audit/recovery | All |

---

## Rollback Strategy

- **Phase 1:** Remove the guard condition → behavior reverts to current (buggy but known)
- **Phase 2:** Remove cron job and heartbeat column
- **Phase 3:** Keep events table as immutable log; write path reverts to direct mutation

---

## Effort Estimate

| Phase | Effort | Risk Reduction |
|-------|--------|----------------|
| 1 | ~2 hours | High (P1 + P2) |
| 2 | ~4 hours | Medium |
| 3 | ~8 hours | High (auditability) |

---

*End of Plan*
