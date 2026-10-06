# PHASE 2: Cart Lifecycle — Production Operations Manual

## Executive Summary

The Cart is the customer's **current shopping selection** — a pure selection container. It is created on first add, holds item lines with price snapshots, survives checkout as a reusable row, and never owns inventory. Stock is validated and reserved **atomically at checkout against the Order** (`OrderReservationService`); the cart performs no reservation, no stock mutation, and no expiry. There is no cart reaper: nothing ever deletes cart items or releases stock based on time.

> Historical note: before the redesign, the cart owned inventory reservations (`reserveItem`, 3-day TTL, `expireCarts`, terminal `checked_out`/`expired` statuses). That architecture is **retired**. All reservation/TTL/expiry language below refers to the current model unless a section is explicitly marked historical.

**Source Files:** `app/Services/General/CartInventoryService.php`, `packages/marvel/src/Database/Models/Cart.php`, `packages/marvel/src/Database/Models/CartItem.php`, `packages/marvel/src/Database/Repositories/CartRepository.php`, `packages/marvel/src/Http/Controllers/CartController.php`, `app/Console/Commands/NotifyAbandonedCarts.php`

---

## 1. Cart Stages

```
CREATED (active, empty) → ITEMS ADDED (active) ⇄ CHECKOUT (slice read by Order)
        → CHECKOUT DONE (row reused: slice removed, survivors kept)
        → CLEARED BY USER (active, empty) → (reminder: abandoned-cart notice only)
```

### Stage 1: Cart Created
- **Trigger:** Customer's first add-to-cart (no existing cart for the user)
- **How:** `CartRepository::persistCart` — find own cart under `lockForUpdate`, create (`user_id`, `status=active`) if missing
- **Invariant:** one cart per user, backed by `UNIQUE(carts.user_id)`; a duplicate-key race resolves to the winner's row instead of erroring
- **Status:** `active` (the only status the application writes)
- **Can customer edit?** Yes — add / change quantity / remove / clear
- **Should reservation exist?** No — carts never reserve inventory
- **Should coupon stay?** N/A (no lines yet)
- **Should prices refresh?** No — prices are snapshotted at add time, refreshed authoritatively at checkout
- **Should cart survive?** Yes — the row is never deleted by checkout or clearing

### Stage 2: Items Added (Active Cart)
- **Trigger:** `POST /cart` (add), `PUT /cart/update-item` (set/inc/dec), `POST /cart/bulk-items` (bulk merge)
- **How:** `persistCart` (cart lock) → `syncItems` (product must be `active()`; FAST lines require FAST-eligible product; variable products require a variant) → `CartInventoryService::incrementItem/decrementItem` (cart lock → item lock → merge duplicates by product+variant+method → `upsertItem`: price snapshot, promotion marks reset, activity touched)
- **Quantity bounds:** each line's *resulting* quantity must satisfy `1 <= qty <= cart.max_item_quantity` (default 100, `config/cart.php`, override `CART_MAX_ITEM_QUANTITY`); violations fail at the HTTP boundary (422) or the service choke point (400)
- **Database writes:**
  - `cart_items`: new row or updated (quantity, price snapshot, total_price; promotion preview columns cleared)
  - `cart`: `status=active`, `total_price` re-summed, `reserved_at=now()`, `expires_at=now()+3days` (activity window — NOT a reservation TTL)
  - `products` / `product_variants`: **never written** (no `reserved_quantity`, no stock counters)
- **Coupon?** Can be applied/removed (coupon quota is NOT reserved at apply time)
- **Promotion?** Marks are preview-only; any cart edit strips stale marks and revalidates
- **Prices?** Snapshotted at add time; NOT live

### Stage 3: Coupon Applied
- **Trigger:** coupon apply endpoint (Phase 03)
- **How:** Coupon validation → `cart->update(['coupon' => $code])`
- **Database write:** `cart.coupon` set to coupon code
- **Coupon reservation?** No — quota consumed only via the order lifecycle
- **Clearing:** removing the last line clears the coupon automatically; clearing a coupon-bearing cart requires explicit `confirm`

### Stage 4: Promotion Considered
- **Trigger:** Frontend calls `eligiblePromotions`, user selects one
- **How:** promotion preview marks (`promotion_id`, `discount_amount`) on cart lines
- **Promotion reservation?** No — usage incremented only after payment
- **Staleness:** any cart mutation strips stale marks (`upsertItem` reset + `revalidatePromotion`); totals recomputed at checkout

### Stage 5: Checkout Reads a Slice
- **Trigger:** Customer checks out (Phase 01)
- **How:** checkout reads the SCHEDULED or FAST slice via `getActiveCartForUser` (read-only) → **the Order validates stock and reserves atomically** via `OrderReservationService`
- **Can customer edit?** Concurrent edits serialize on the cart lock; post-checkout adds land on the reused row cleanly
- **Stock guaranteed?** By the order, not the cart — insufficient stock fails the checkout, never the cart

### Stage 6: Post-Checkout Slice Cleanup (Cart Survives)
- **Trigger:** Order created for a slice
- **How:** `clearCheckedOutSlice` (inside the checkout transaction): deletes the ordered shipping-method slice (+ legacy gift artifacts); if lines remain, re-totals and extends the activity window; if empty, resets totals/coupon and parks the activity window
- **Cart status:** `active` in all cases — there is no `checked_out` terminal state
- **Cart items:** only the ordered slice is deleted; surviving lines stay shoppable
- **New cart?** Never — future adds reuse the same row

### Stage 7: Customer Clears the Cart
- **Trigger:** `DELETE /cart/delete-items` (or single line via `DELETE /cart/delete-item/{id}`)
- **How:** `releaseCart` deletes lines (when requested), resets to `active`, clears coupon/totals; last-line removal clears the coupon
- **Cart status:** `active` (not terminal — the row is reused)
- **Inventory:** untouched (nothing was ever reserved)
- **Coupon?** Removed when the cart becomes empty

### Stage 8: Abandonment Reminder (Notification Only)
- **Trigger:** `cart:notify-abandoned`, hourly (`withoutOverlapping` + `onOneServer`)
- **Eligibility (all required):** `status=active` AND `reserved_at` older than 24h AND `expires_at` in the future AND `reminder_sent_at IS NULL` AND customer (`type=user`) account
- **How:** per-cart atomic claim (`reminder_sent_at` NULL → timestamp in a single UPDATE; exactly one worker wins) → notify → counted. No transaction is held open across the notification; delivery durability comes from the notification queue once claimed
- **Effect:** notification only. No status change, no item deletion, no stock movement — there is deliberately **no expiry reaper**

---

## 2. Timeline Diagram

```
TIME →
├─[t=0]── Cart created on first add (active, one per user)
├─[t=1]── Items added/updated/removed (price snapshots; NO stock movement)
├─[t=2]── Coupon applied (not consumed; cleared if cart emptied)
├─[t=3]── Promotion preview marks (not consumed; stripped on any edit)
├─[t=4]── Checkout clicked → ORDER validates stock + reserves atomically
├─[t=5]── Order created → ordered slice deleted, SAME cart row reused
│
├─ IF PAYMENT SUCCEEDS:
│   └─ Order lifecycle proceeds (Phase 01); cart row stays active
│
├─ IF PAYMENT FAILS:
│   └─ Order stays pending (no cart changes); cart remains fully usable
│
├─ IF CUSTOMER ABANDONS (24h inactive):
│   └─ At most one reminder notification; cart untouched, no expiry
│
└─ IF CUSTOMER REMOVES ALL ITEMS:
    └─ Coupon removed, cart empty + active, no expiration
```

## 3. State Machine

```
                    ┌─────────────────┐
                    │     CREATED     │
                    │ (status=active) │
                    │    items = 0    │
                    └────────┬────────┘
                             │ add item (persistCart: find-or-create under lock,
                             │ UNIQUE(user_id) + duplicate-race resolve)
                             ▼
                    ┌─────────────────┐
                    │  ITEMS ADDED    │◄──────────────┐
                    │ (status=active) │               │ add / set / remove /
                    │ items > 0, no   │───────────────┘ clear (locked merges,
                    │ stock movement  │  quantity 1..max, promo revalidated)
                    └────────┬────────┘
                             │ checkout reads a slice;
                             │ ORDER reserves stock atomically
                             ▼
                    ┌─────────────────┐
                    │ SLICE REMOVED,  │
                    │ ROW REUSED      │
                    │ (status=active) │── survivors stay shoppable;
                    └─────────────────┘   empty cart parked (no reminder)
```

There are no other cart states. `checked_out` / `expired` appear only as legacy
schema-enum vestiges and dashboard-analytics labels — the application never writes them.

## 4. Data Model

### Cart
```sql
carts: id, user_id UNIQUE, coupon (nullable), total_price (cached sum),
       status (always 'active' as written), reserved_at, expires_at
       (abandoned-cart ACTIVITY window, not a reservation TTL),
       reminder_sent_at (nullable; set once by the atomic claim),
       timestamps
```

### CartItem
```sql
cart_items: id, cart_id, product_id, product_variant_id, quantity (1..max),
            reserved_quantity (legacy column; cart no longer writes it),
            price (snapshot), total_price,
            attributes (json), discount_amount, shipping_method (SCHEDULED|FAST),
            is_gift (bool), promotion_id (nullable, preview only), timestamps
```

## 5. Key Design Decisions

1. **Cart is single per user** — one cart at a time, enforced by `UNIQUE(carts.user_id)` plus duplicate-race-safe creation. No multi-cart support.
2. **Cart NEVER touches inventory** — no reservation, no counters, no commit, no release. Stock is validated/reserved atomically at checkout against the Order.
3. **Prices are NOT live** — cart item prices are set at add-to-cart time; refreshed only at checkout (preserved decision).
4. **Coupon is NOT reserved** — applied to cart but quota consumed only via the order lifecycle (preserved decision).
5. **Promotion is NOT reserved** — preview marks only, stripped on any edit; usage incremented only at payment (preserved decision).
6. **Per-line maximum quantity** — `cart.max_item_quantity` (default 100): absurd quantities are rejected at the cart boundary; legitimate availability remains a checkout concern.
7. **Cart row is immortal** — checkout removes only the ordered slice; clearing empties but never deletes; no terminal statuses, no reaper.
8. **3-day value is an activity/reminder window** — `reserved_at`/`expires_at` drive the 24h-inactivity abandoned-cart reminder only. They are NOT an inventory reservation TTL.
9. **Reminder is at-most-once** — scheduler single-server execution plus a per-cart atomic claim; concurrent workers cannot double-notify.
10. **Cart IDs are not enumerable** — `GET /cart/{id}` scopes to the caller's own carts first; foreign and nonexistent IDs both return 404.

## 6. Retired Problems (Closed by Redesign — Do Not Reopen)

| Historical problem | Resolution |
|--------------------|------------|
| No cart cleanup command (`expireCarts` unregistered) | Moot — nothing to clean; no reaper exists or is needed |
| No concurrent-add locking / oversell on add | Moot — cart+item `lockForUpdate` merges exist AND oversell at add is structurally impossible (no reservation at add) |
| Stale prices | Accepted by design (decision 3); checkout refreshes authoritatively |
| Cart-owned reservation TTL / stock release from cart | Retired — inventory authority moved to the Order |

## 7. Production Recommendations

1. Keep `CART_MAX_ITEM_QUANTITY` at the default unless analytics show legitimate bulk-buy lines hitting it; raise deliberately, never remove the bound.
2. Run schedulers on a centralized cache (Redis/Memcached) so `onOneServer` constrains multi-server schedulers; the per-cart atomic claim already protects shared-database workers regardless of cache driver.
3. Treat `status` values other than `active` in `carts` as data anomalies (no writer produces them); the legacy enum vestige is harmless and needs no migration.
4. Do NOT reintroduce cart-level reservation, expiry, or terminal statuses — checkout, Order Flow, fulfillment, coupon, promotion, and payment behavior all assume the selection-container contract.
