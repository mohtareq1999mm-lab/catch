# Promotion System Implementation Roadmap

> **Document Type**: Architecture Planning
> **Status**: Implementation-ready roadmap — no code changes made
> **Date**: 2026-07-14
> **Frozen Architecture**: ADR-001 respected throughout
> **Audit References**: promotion-system-analysis.md, promotion-lifecycle-audit.md, promotion-architecture-verification.md

---

## Table of Contents

1. [Executive Summary](#1-executive-summary)
2. [Architecture Constraints](#2-architecture-constraints)
3. [Implementation Strategy](#3-implementation-strategy)
4. [Implementation Phases](#4-implementation-phases)
5. [Prioritized TODO List](#5-prioritized-todo-list)
6. [Promotion Lifecycle Improvements](#6-promotion-lifecycle-improvements)
7. [Cart Synchronization Plan](#7-cart-synchronization-plan)
8. [Checkout Validation Plan](#8-checkout-validation-plan)
9. [Order Snapshot Plan](#9-order-snapshot-plan)
10. [API Improvement Plan](#10-api-improvement-plan)
11. [Architecture Compliance Review](#11-architecture-compliance-review)
12. [Regression Test Plan](#12-regression-test-plan)
13. [Risks](#13-risks)
14. [Open Questions](#14-open-questions)
15. [Final Recommended Implementation Order](#15-final-recommended-implementation-order)

---

## 1. Executive Summary

### Problem Statement

The Promotion System audit (completed 2026-07-13) identified **4 confirmed bugs** (2 P1, 1 P2, 1 P3), **7 unsafe architectural assumptions**, and **zero automatic promotion revalidation on any cart modification operation**. The regular checkout flow has a critical gap: promotion data is persisted during price calculation but read without re-validation during order creation, leaving a window for stale/corrupted data to reach order snapshots.

### Scope of This Document

This document provides a complete architecture-first implementation roadmap that fixes every confirmed issue while preserving the frozen runtime pricing architecture (ADR-001). No code is written. No architecture is refactored. Existing services are extended, not replaced.

### High-Level Strategy

The strategy has three layers:

1. **Cart Synchronization Layer** — Every cart modification triggers promotion revalidation via `PromotionEligibilityResolver`. No stale data survives.
2. **Checkout Validation Layer** — `getCheckoutTotalsFromCart()` is replaced with a fresh calculation path that re-validates before order creation.
3. **Defensive Persistence Layer** — Gift items receive proper `shipping_method`. Discount data is cleared on cart modification. Usage decrement is added for order cancellation.

### Success Criteria

- Zero stale promotion data after any cart operation
- Checkout never trusts persisted promotion data without re-validation
- Orders are correct snapshots even if promotion is deactivated mid-checkout
- Gift items are properly finalized for all shipping methods
- All existing API contracts are preserved (backward compatible)
- Frozen architecture (ADR-001) is never violated

---

## 2. Architecture Constraints

### 2.1 Frozen Architecture (ADR-001)

The following rules are **mandatory** and cannot be violated:

| Rule | Constraint | Impact on Promotion Work |
|------|-----------|------------------------|
| #1 Single Pricing Authority | All product-level pricing through `ProductPricingService` | No change — promotion is cart-level, not product-level |
| #2 Pre-Serialization Enrichment | Pricing set on model before Resource | Cart enrichment for `has_eligible_promotion` must follow same pattern |
| #3 Resource Purity | Resources serialize only | Must not compute promotion eligibility inside `CartResource` |
| #4 Model Purity | Models are data containers | `Promotion::discountAmount()` can stay (separate domain) but no new model logic |
| #5 Controller Purity | Controllers orchestrate only | Cart enrichment for `has_eligible_promotion` must NOT be in controller |
| #6 Zero Duplication | No duplicate formulas | `calculateCheckoutTotalsFromCart()` subtotal duplicates `PromotionService::subtotal()` |
| #7 Lightweight Accessors | No computing in accessors | No new accessors that compute values |
| #8 No Hidden Work | No hidden SQL | No new lazy loads or hidden queries |
| #9 Extensibility | Extend existing services | Must extend `PromotionService`, `CartInventoryService`, not create parallel services |

### 2.2 AI Development Rules

| Rule | Application |
|------|-------------|
| Understand → Analyze → Plan → Modify | This document completes Plan. Next step is Modify. |
| Reuse existing services | `PromotionEligibilityResolver`, `PromotionService`, `CartInventoryService`, `CartRepository` |
| No parallel flows | All promotion revalidation goes through `PromotionEligibilityResolver` |
| No business logic in Resources/Controllers/Models | Cart enrichment is pre-serialization in service layer |
| Backward-compatible API | New fields only, never remove or rename existing fields |

### 2.3 Existing System Boundaries

| Boundary | Definition |
|----------|-----------|
| Promotion scope | Cart-level only. Never product-level. |
| Promotion vs Coupon | Independent. Both applied sequentially. |
| Promotion vs Flash Sale | Separate systems. No interaction. |
| One promotion per order | `selected_promotion_id` is a single integer. No stacking. |

### 2.4 Approved Business Rules (from audit)

| Business Rule | Must Be Enforced |
|---------------|------------------|
| Cart modification triggers promotion revalidation | In all 7 cart operations |
| Promotion validity reflects current state | At all times |
| Checkout never trusts stale data | `addItemsInOrder()` must re-validate |
| Customers can select/change/remove/skip promotions | Removal must clear all promotion data |
| Orders are immutable snapshots | Promotion data frozen at order creation |

---

## 3. Implementation Strategy

### 3.1 Guiding Principles

1. **Fix bugs first, then harden.** P1 bugs (checkout trust, gift shipping) are resolved before P2/P3.
2. **Pessimistic safety.** When in doubt, clear promotion data and force re-apply. Performance is secondary to correctness.
3. **Single source of truth for revalidation.** All promotion revalidation goes through `PromotionService::applySelectedPromotion()` with the current `selected_promotion_id`. Zero new eligibility evaluation paths.
4. **Extend, don't replace.** Existing services (`CartInventoryService`, `CartRepository`, `PromotionService`, `OrderService`) are extended with new methods or modified at specific points. No new services.
5. **Transaction-safe checkout.** The regular checkout's two-step split is preserved for payment gateway compatibility, but order creation re-validates promotion within its own transaction.

### 3.2 Architectural Touch Points

| What Must Change | Current State | Target State |
|-----------------|---------------|--------------|
| `CartRepository::persistCart()` | No promotion involvement | Calls promotion revalidation after items change |
| `CartController::deleteItemFromCart()` | No promotion reset | Calls promotion revalidation |
| `CartController::destroy()` | No promotion reset | Calls promotion revalidation |
| `CartInventoryService::reserveItem()` | Payload has no promotion fields | Clears `promotion_id`, `discount_amount` on updated items |
| `CartInventoryService::reserveGiftItem()` | Missing `shipping_method` | Sets `shipping_method` from cart context |
| `CartInventoryService::finalizeItemsByShippingMethod()` | Skips gift items with no shipping_method | Includes all gift items |
| `OrderService::getCheckoutTotalsFromCart()` | Reads persisted data without re-validation | Re-validates promotion before computing totals |
| `OrderService::addItemsInOrder()` | Uses `getCheckoutTotalsFromCart()` | Uses re-validated `calculateCheckoutTotals()` |
| `PromotionService::applySelectedPromotion()` | No explicit "remove" path | Properly clears all promotion data when called with null |
| `PromotionService::incrementUsage()` | No decrement method | Add `decrementUsage()` for order cancellation |
| `CartResource` | No promotion fields | Add `has_eligible_promotion` (via enrichment) |
| `CartItemResource` | No promotion fields | Add `promotion_id`, `discount_amount`, `is_gift` (from model attributes) |

### 3.3 What Does NOT Change

- `PromotionEligibilityResolver` — already the single source of truth
- `PromotionApplicator` — allocation algorithm is correct
- `PromotionStrategy` hierarchy — strategy pattern is sound
- `Promotion::discountAmount()` — model purity violation but frozen architecture applies to product pricing, not promotion
- `Promotion::valid()` scope — already correct
- `FastShippingService` — already atomic and correct (single P1 bug in gift shipping_method)
- `OrderCreationService` — already correctly creates snapshots
- `CheckoutTotals` DTO — already encapsulates all required data
- `PromotionObserver` — logging is correct
- API route structure — no new endpoints required
- Response field names — backward compatible

### 3.4 Extension Points

| Extension Point | Parent | Purpose |
|----------------|--------|---------|
| `CartRepository::revalidatePromotion()` | `CartRepository` | New method: re-apply or clear promotion after cart modification |
| `CartInventoryService::clearItemPromotionData()` | `CartInventoryService` | New method: clear promotion_id, discount_amount on a single item |
| `CartInventoryService::refreshCartPromotion()` | `CartInventoryService` | New method: full promotion refresh after cart modification |
| `PromotionService::clearPromotionFromCart()` | `PromotionService` | New method: explicitly remove promotion, clear all fields |
| `PromotionService::decrementUsage()` | `PromotionService` | New method: decrement usage counter for cancelled orders |
| `OrderService::validateCheckoutPromotion()` | `OrderService` | New method: re-validate promotion before order creation |
| `CartResource` enrichment step | Controller layer | Pre-serialization `has_eligible_promotion` enrichment |

---

## 4. Implementation Phases

### Phase 1: P1 Bug Fixes — Data Integrity

**Purpose**: Fix the two critical bugs that can cause incorrect order totals and inventory leaks.

**Scope**:
1. Gift items missing `shipping_method` — `CartInventoryService::reserveGiftItem()`
2. `getCheckoutTotalsFromCart()` does not re-validate promotion

**Dependencies**: None. These are isolated bug fixes.

**Expected Outcome**:
- Gift items are finalized correctly in all shipping paths
- Regular checkout order creation re-validates promotion before creating order

**Risk Level**: **High** (directly affects financial accuracy and inventory)

**Testing Requirements**:
- Test: gift item is finalized with correct shipping_method in fast checkout
- Test: gift item is finalized with correct shipping_method in regular (scheduled) checkout
- Test: promotion re-validated during `addItemsInOrder()` — invalid promotion throws exception
- Test: promotion deactivated between calcInvoicePrice and addItemsInOrder → order creation fails
- Test: promotion expired between calcInvoicePrice and addItemsInOrder → order creation fails
- Test: usage limit reached between calcInvoicePrice and addItemsInOrder → order creation fails
- Test: cart modified between calcInvoicePrice and addItemsInOrder → discount recalculated correctly

**Rollback Considerations**:
- `reserveGiftItem()` change: If rollback needed, revert the `shipping_method` addition. Gift items revert to implicit default.
- `getCheckoutTotalsFromCart()` change: If rollback needed, restore original method. System reverts to trusting stale data.

---

### Phase 2: Cart-Promotion Synchronization

**Purpose**: Every cart modification triggers promotion revalidation. No stale promotion data survives.

**Scope**:
1. `CartRepository::persistCart()` — revalidate promotion after items change
2. `CartController::deleteItemFromCart()` — revalidate promotion after item removal
3. `CartController::destroy()` — clear promotion data
4. `CartInventoryService::reserveItem()` — clear promotion data on updated items
5. `CartInventoryService::refreshCartPromotion()` — new orchestration method

**Dependencies**: Phase 1 must be complete (provides the re-validation pattern).

**Expected Outcome**:
- Adding/removing/updating items re-validates the selected promotion
- If promotion becomes invalid after cart modification → automatically cleared
- If promotion remains valid → discount recalculated for new cart composition
- Cart totals always reflect correct state

**Risk Level**: **Medium** (performance impact of re-validation on every cart operation)

**Testing Requirements**:
- Test: add item → promotion revalidated, discount recalculated
- Test: remove item → promotion revalidated, discount recalculated
- Test: increase quantity → promotion revalidated, discount recalculated
- Test: decrease quantity → promotion revalidated, discount recalculated
- Test: change variant → promotion revalidated, discount recalculated
- Test: clear cart → promotion data cleared
- Test: merge cart → promotion revalidated
- Test: add item makes promotion invalid → promotion cleared automatically
- Test: add item makes a different promotion eligible → no change to current selection
- Test: concurrent cart modifications don't cause race conditions

**Rollback Considerations**:
- All changes are in cart modification paths. If rollback needed, revert the revalidation calls in controllers and repository. System reverts to no automatic revalidation.

---

### Phase 3: Promotion Removal & Lifecycle Cleanup

**Purpose**: Explicit "remove promotion" path. Proper cleanup when promotion is removed or becomes invalid.

**Scope**:
1. `PromotionService::applySelectedPromotion()` with null → properly clears all promotion data
2. `PromotionService::clearPromotionFromCart()` — new method
3. `CartInventoryService::clearItemPromotionData()` — new method
4. `CartItemResource` — expose `promotion_id`, `discount_amount`, `is_gift`

**Dependencies**: Phase 2 must be complete (cart synchronization established).

**Expected Outcome**:
- Calling checkout with `selected_promotion_id = null` removes gift items AND clears discount_amount/promotion_id on regular items
- Cart response exposes `promotion_id`, `discount_amount`, `is_gift` for each item
- Promotion data is never left in an inconsistent state

**Risk Level**: **Low** (primarily adding missing cleanup paths and serialization)

**Testing Requirements**:
- Test: remove promotion → all gift items deleted, all discount_amount/promotion_id cleared
- Test: switch promotion → old gifts removed, old discount cleared, new promotion applied
- Test: skip promotion → no promotion data, cart totals correct
- Test: CartItemResource includes promotion_id, discount_amount, is_gift
- Test: CartItemResource backward compatible (existing fields unchanged)

**Rollback Considerations**:
- Changes to `applySelectedPromotion()` with null and `CartItemResource`: revert method changes and resource field additions. Frontend stops receiving new fields but all existing functionality preserved.

---

### Phase 4: Order Lifecycle — Usage Decrement

**Purpose**: When an order is cancelled, promotion usage counter is decremented.

**Scope**:
1. `PromotionService::decrementUsage()` — new method
2. `OrderService::changeOrderStatus()` — call decrementUsage when cancelling order with promotion
3. Transaction safety — ensure decrement rolls back if order status change fails

**Dependencies**: Phase 1 must be complete (ensures promotion_id is correctly stored on order).

**Expected Outcome**:
- Cancelled orders no longer count toward promotion usage limits
- Usage counter is decremented atomically within the order status change transaction
- No double-decrement if order cancellation is called multiple times

**Risk Level**: **Low** (new method, no existing behavior changed)

**Testing Requirements**:
- Test: cancel order with promotion → usage decremented by 1
- Test: cancel order without promotion → usage unchanged
- Test: cancel same order twice → usage decremented only once
- Test: decrement never goes below 0
- Test: concurrent cancellation and new order → no race condition

**Rollback Considerations**:
- Revert the `changeOrderStatus()` modification. Usage stays incremented on cancellation (current behavior).

---

### Phase 5: API Enhancement — `has_eligible_promotion`

**Purpose**: Cart response exposes whether any promotions are eligible, without duplicating business logic.

**Scope**:
1. Controller-level enrichment for `CartResource` — pre-serialization
2. Lightweight eligibility check in `PromotionEligibilityResolver` — early-exit method
3. `has_eligible_promotion` field in cart response

**Dependencies**: Phase 2 must be complete (cart synchronization ensures cart items are stable).

**Expected Outcome**:
- Cart response includes `has_eligible_promotion: bool`
- Implementation reuses `PromotionEligibilityResolver` — zero duplicated logic
- No performance regression (early-exit for batch evaluation)
- Zero new SQL queries (reuses already-loaded cart items + promotion data)

**Risk Level**: **Low** (new field, backward compatible)

**Testing Requirements**:
- Test: cart with eligible promotion → `has_eligible_promotion` = true
- Test: cart without eligible promotion → `has_eligible_promotion` = false
- Test: cart with promotion but not eligible anymore → `has_eligible_promotion` = false
- Test: empty cart → `has_eligible_promotion` = false
- Test: no promotions in database → `has_eligible_promotion` = false
- Test: all promotion types eligible (percentage, fixed, gift)
- Test: promotion eligible but already applied → `has_eligible_promotion` = true (still eligible)
- Test: performance — cart response time not increased by more than 50ms

**Rollback Considerations**:
- Remove the enrichment step. Frontend stops receiving the field. All other functionality preserved.

---

### Phase 6: Regression Hardening & Performance

**Purpose**: Eliminate duplicated calculations, optimize eager loading, add defensive checks.

**Scope**:
1. Remove redundant `matchedEligibility()` call in `PromotionService::applySelectedPromotion()`
2. Extract shared subtotal calculation into a reusable method (extend `PromotionService`)
3. Ensure all cart promotion paths eager load required relations
4. Add defensive check for multiple promotion_ids on cart items (guard in `getCheckoutTotalsFromCart()`)

**Dependencies**: Phases 1-5 must be complete (all structural changes in place).

**Expected Outcome**:
- Subtotal calculation centralized in one place
- `matchedEligibility()` called once per apply, not three times
- No N+1 queries in any promotion path
- Guard against inconsistent cart state

**Risk Level**: **Low** (performance improvements and defensive guards)

**Testing Requirements**:
- Test: subtotal calculation same result from all callers
- Test: `matchedEligibility()` called exactly once during `applySelectedPromotion()`
- Test: guard catches inconsistent promotion_ids on cart items
- Test: all promotion paths verified for correct eager loading

**Rollback Considerations**:
- Revert individual optimizations — each is self-contained and non-critical.

---

## 5. Prioritized TODO List

### P1 — Critical (Must Fix Before Production)

| # | Priority | Description | Affected Modules | Affected Services | Affected Controllers | Affected DB Tables | Affected API Endpoints | Architecture Impact | Business Impact | Risk | Testing Required | Acceptance Criteria | Dependencies |
|---|----------|-------------|-----------------|-------------------|---------------------|-------------------|------------------------|---------------------|----------------|------|------------------|---------------------|--------------|
| 1 | **P1** | Fix `reserveGiftItem()` — set `shipping_method` from cart context | CartInventoryService | CartInventoryService | — | cart_items | — | None (payload field addition) | Gift items get finalized for correct shipping method | Med | Feature Test | Gift items finalized with correct shipping_method. No inventory leak. | None |
| 2 | **P1** | Fix `getCheckoutTotalsFromCart()` — re-validate promotion instead of reading stale persisted data | OrderService | OrderService, PromotionService | OrderController | cart_items, orders, order_products | POST /api/v1/general/checkout (step 2) | Medium — changes order creation data source | Stale promotions never reach order creation | High | Feature Test, Edge Case Test | `addItemsInOrder()` calls `calculateCheckoutTotals()` with stored `selected_promotion_id`. Invalid promotions throw exception. Order never created with stale data. | #1 |

### P2 — High (Must Fix Next)

| # | Priority | Description | Affected Modules | Affected Services | Affected Controllers | Affected DB Tables | Affected API Endpoints | Architecture Impact | Business Impact | Risk | Testing Required | Acceptance Criteria | Dependencies |
|---|----------|-------------|-----------------|-------------------|---------------------|-------------------|------------------------|---------------------|----------------|------|------------------|---------------------|--------------|
| 3 | **P2** | Add `clearItemPromotionData()` — clear `promotion_id`/`discount_amount` on cart item modify | CartInventoryService | CartInventoryService | CartController | cart_items | PUT /api/v1/carts, POST /api/v1/carts | Low — extends existing service method | No stale discount_amount after quantity/variant change | Med | Feature Test | After cart modification, `discount_amount` and `promotion_id` are cleared on modified items | None |
| 4 | **P2** | Add `revalidatePromotion()` to `CartRepository` — trigger after every cart modification | CartRepository, PromotionService | CartRepository, PromotionService, CartInventoryService | CartController | carts, cart_items | All cart endpoints | Medium — adds promotion revalidation to cart flow | Cart always internally consistent after any operation | Med | Integration Test | Every cart modification revalidates promotion. Invalid promotions auto-cleared. Discount recalculated. | #1, #2, #3 |

### P3 — Medium (Should Fix)

| # | Priority | Description | Affected Modules | Affected Services | Affected Controllers | Affected DB Tables | Affected API Endpoints | Architecture Impact | Business Impact | Risk | Testing Required | Acceptance Criteria | Dependencies |
|---|----------|-------------|-----------------|-------------------|---------------------|-------------------|------------------------|---------------------|----------------|------|------------------|---------------------|--------------|
| 5 | **P3** | Fix `applySelectedPromotion()` with null — clear discount_amount/promotion_id on all items, not just gifts | PromotionService | PromotionService | OrderController | cart_items | POST /api/v1/general/checkout | None (fixes existing method's behaviour) | Explicit promotion removal leaves cart in consistent state | Low | Feature Test | Calling `applySelectedPromotion()` with null clears all promotion data (gifts, discount_amount, promotion_id) | #3 |
| 6 | **P3** | Add `PromotionService::decrementUsage()` — decrement usage on order cancellation | PromotionService, OrderService | PromotionService, OrderService | OrderController | promotions | Order cancellation endpoints | Low — new method | Cancelled orders no longer consume promotion usage | Low | Feature Test | Order cancellation decrements usage. No double-decrement. Never negative. | #2 |
| 7 | **P3** | Expose `promotion_id`, `discount_amount`, `is_gift` in `CartItemResource` | CartItemResource | — | — | cart_items | GET /api/v1/carts, GET /api/v1/carts/{id} | None (new serialization fields) | Frontend can display per-item promotion status | Low | Feature Test | CartItemResource returns `promotion_id`, `discount_amount`, `is_gift`. Existing fields unchanged. | None |

### P4 — Low (Nice to Have)

| # | Priority | Description | Affected Modules | Affected Services | Affected Controllers | Affected DB Tables | Affected API Endpoints | Architecture Impact | Business Impact | Risk | Testing Required | Acceptance Criteria | Dependencies |
|---|----------|-------------|-----------------|-------------------|---------------------|-------------------|------------------------|---------------------|----------------|------|------------------|---------------------|--------------|
| 8 | **P4** | Add `has_eligible_promotion` to GET Cart response via pre-serialization enrichment | CartResource, PromotionService, PromotionEligibilityResolver | PromotionService, PromotionEligibilityResolver | CartController | — | GET /api/v1/carts, GET /api/v1/carts/{id} | Low — controller-level enrichment, not in Resource | Frontend knows immediately if promotions available | Low | Feature Test, Performance Test | Cart response includes `has_eligible_promotion: bool`. Uses `PromotionEligibilityResolver`. Zero duplicated logic. | #4 |
| 9 | **P4** | Remove redundant `matchedEligibility()` call in `PromotionService::applySelectedPromotion()` | PromotionService | PromotionService | — | — | — | None (code cleanup) | Performance improvement | Low | Unit Test | `matchedEligibility()` called once during apply. Same result from single call. | Phase 6 |
| 10 | **P4** | Extract shared subtotal calculation (price * qty, exclude gifts) into reusable PromotionService method | PromotionService, PromotionEligibilityResolver, OrderService | PromotionService, PromotionEligibilityResolver, OrderService | — | — | — | Low — DRY fix | Subtotal formula in one place | Low | Unit Test | All three callers use same method. Same results. | None |

---

## 6. Promotion Lifecycle Improvements

### 6.1 Cart Modification (All Operations)

**Current**: Zero promotion involvement.

**Target**: Every cart modification must trigger the following sequence:

1. **Clear discount on modified items** — `CartInventoryService::clearItemPromotionData()` resets `promotion_id` and `discount_amount` on the items being added/updated/deleted.
2. **Re-evaluate selected promotion** — If the cart has a selected promotion (any item has `promotion_id`), call `PromotionService::applySelectedPromotion()` with the current promotion ID.
3. **Update cart totals** — After re-validation, cart `total_price` reflects new discount allocation.
4. **Handle invalidation** — If re-validation fails (promotion no longer eligible), clear all promotion data from cart items and gifts.

**Responsibility**: `CartRepository::revalidatePromotion()` — orchestrates the revalidation. Called from `CartRepository::persistCart()` and `CartController::deleteItemFromCart()`.

### 6.2 Promotion Selection

**Current**: Occurs during `calcInvoicePrice()`. Writes to cart_items and cart.

**Target**: Same flow, but:
- Promotion selection now also clears ALL existing promotion data (not just gifts) before applying — handled by `applySelectedPromotion()` fix (Todo #5).
- The `selected_promotion_id` is stored as a request parameter (unchanged).

**Responsibility**: `PromotionService::applySelectedPromotion()` — unchanged interface, improved cleanup.

### 6.3 Promotion Removal

**Current**: No explicit removal. Calling `calcInvoicePrice()` with null removes gifts but leaves discount_amount.

**Target**: Explicit removal via `PromotionService::clearPromotionFromCart()`:
1. Remove all gift items (`removeGiftItems()`)
2. Clear `promotion_id` and `discount_amount` on all non-gift items
3. Recalculate cart `total_price` as sum of undiscounted `price * quantity`
4. Return `CheckoutTotals` with zero promotion data

**Responsibility**: `PromotionService::clearPromotionFromCart()` — new method. Called from `applySelectedPromotion()` when `$promotionId` is null.

### 6.4 Promotion Invalidation

**Current**: Only detected during `applySelectedPromotion()` (inside `calcInvoicePrice` or `createFastOrder`).

**Target**: Invalidation is detected automatically:
1. **During cart modification** — `revalidatePromotion()` calls `applySelectedPromotion()` → exception thrown if invalid → caught and handled → promotion cleared.
2. **During checkout** — `getCheckoutTotalsFromCart()` re-validates → exception thrown if invalid → checkout fails.
3. **During order creation** — `finalizeOrder()` re-verifies via `lockForUpdate()` guard (already exists).

**Responsibility**: Cart path — `CartRepository::revalidatePromotion()`. Checkout path — `OrderService::validateCheckoutPromotion()`.

### 6.5 Promotion Revalidation

**Current**: Called explicitly during `calcInvoicePrice()` and `createFastOrder()`.

**Target**: Called automatically:
1. After every cart modification
2. During `addItemsInOrder()` (regular checkout step 2)
3. During `createFastOrder()` (already done)

**Responsibility**: `PromotionService::applySelectedPromotion()` — unchanged. New callers are the cart modification paths.

### 6.6 Gift Lifecycle

| Phase | Current | Target |
|-------|---------|--------|
| Creation | `reserveGiftItem()` without `shipping_method` | `reserveGiftItem()` sets `shipping_method` from cart context |
| Re-apply | `removeGiftItems()` clears old gifts before new apply | Same (already correct) |
| Removal | `removeGiftItems()` called, but only if `applySelectedPromotion()` path executes | Also called from `clearPromotionFromCart()` |
| Finalization | `finalizeItemsByShippingMethod()` misses gifts in fast checkout | Gift items have matching `shipping_method` → finalized correctly |
| Expiry | `expireCarts()` → `expireCart()` → `releaseStock()` for all items including gifts | Same (already correct) |

### 6.7 Discount Lifecycle

| Phase | Current | Target |
|-------|---------|--------|
| Apply | `applyOutcome()` sets `discount_amount`, `promotion_id`, `total_price` | Same (correct) |
| Modify | `reserveItem()` preserves stale discount_amount | `clearItemPromotionData()` clears it; `revalidatePromotion()` recalculates |
| Remove | No cleanup when promotion removed | `clearPromotionFromCart()` clears all discount data |
| Re-calculate | Only during `applySelectedPromotion()` | Also during cart modification re-validation |

### 6.8 Checkout Validation

See Section 8.

### 6.9 Order Snapshot

See Section 9.

### 6.10 Usage Update

| Action | Current | Target |
|--------|---------|--------|
| Order created | `incrementUsage()` called | Same (correct) |
| Order cancelled | No decrement | `decrementUsage()` called |
| Order failed | Rollback via transaction | Same (correct) |

### 6.11 Inventory Update

| Action | Current | Target |
|--------|---------|--------|
| Gift reserve | `reserveGiftItem()` | Same (correct) + shipping_method fix |
| Gift release | `releaseItem()` via `removeGiftItems()` | Same (correct) |
| Gift finalize | Missed in fast checkout | Fixed via shipping_method |
| Regular item reserve | `reserveItem()` | Same (correct) |

---

## 7. Cart Synchronization Plan

### 7.1 Every Cart Operation — Detailed Analysis

#### 7.1.1 Add Product (POST /api/v1/carts)

| Question | Answer |
|----------|--------|
| Should promotion be revalidated? | **Yes.** New items change the matched subtotal and item set. |
| Should discount be recalculated? | **Yes.** Proportional allocation must include new items. |
| Should gifts be recalculated? | **Yes.** If the promotion is a gift type, new items might affect eligibility. |
| Should totals be recalculated? | **Yes.** Cart total_price changes. |
| Should promotion metadata change? | No (promotion_id stays the same if still valid). Cleared if invalid. |
| Which existing service should be responsible? | `CartRepository::persistCart()` → `CartRepository::revalidatePromotion()` |
| Can existing architecture be reused? | Yes. `revalidatePromotion()` calls `PromotionService::applySelectedPromotion()`. |

**Flow**:
```
CartRepository::storeCart()
    └── CartRepository::persistCart($request, 'add')
        ├── CartInventoryService::reserveItem()          [existing]
        │   └── ADD: clearItemPromotionData() on new item [new]
        └── CartRepository::revalidatePromotion($cart)    [new]
            ├── Load selected_promotion_id from any item
            │   (first non-null promotion_id across all items)
            ├── If found:
            │   └── PromotionService::applySelectedPromotion($cart, $promotionId)
            │       ├── removeGiftItems()                 [existing]
            │       ├── re-validate eligibility            [existing]
            │       ├── recalculate discount allocation   [existing]
            │       └── update cart totals                [existing]
            └── If not found:
                └── No action (no promotion to revalidate)
```

#### 7.1.2 Remove Product (DELETE /api/v1/carts/{itemId})

| Question | Answer |
|----------|--------|
| Should promotion be revalidated? | **Yes.** Removing an item changes the matched subtotal. |
| Should discount be recalculated? | **Yes.** Allocation was based on all items including the deleted one. |
| Should gifts be recalculated? | **Yes.** If removing item makes cart ineligible for gift promotion. |
| Should totals be recalculated? | **Yes.** |
| Should promotion metadata change? | Only if promotion becomes invalid. |
| Which existing service should be responsible? | `CartController::deleteItemFromCart()` → `CartRepository::revalidatePromotion()` |
| Can existing architecture be reused? | Yes. |

**Flow**:
```
CartController::deleteItemFromCart()
    ├── CartInventoryService::releaseItem($item, true)    [existing]
    └── CartRepository::revalidatePromotion($cart)        [new]
        └── (same as Add flow above)
```

#### 7.1.3 Increase Quantity (PUT /api/v1/carts)

| Question | Answer |
|----------|--------|
| Should promotion be revalidated? | **Yes.** Changes matched subtotal and quantity. |
| Should discount be recalculated? | **Yes.** Allocation changes with new quantity-weighted proportions. |
| Should gifts be recalculated? | **Yes.** |
| Should totals be recalculated? | **Yes.** |
| Should promotion metadata change? | Only if promotion becomes invalid. |
| Which existing service? | `CartRepository::persistCart($request, 'set')` → `CartRepository::revalidatePromotion()` |
| Can existing architecture be reused? | Yes. |

**Flow**: Same as Add Product, but using `mode = 'set'`.

#### 7.1.4 Decrease Quantity (PUT /api/v1/carts)

| Question | Answer |
|----------|--------|
| Should promotion be revalidated? | **Yes.** Changes matched subtotal and quantity. |
| Should discount be recalculated? | **Yes.** |
| Should gifts be recalculated? | **Yes.** |
| Should totals be recalculated? | **Yes.** |
| Should promotion metadata change? | Only if promotion becomes invalid. |
| Which existing service? | Same as Increase Quantity. |
| Can existing architecture be reused? | Yes. |

#### 7.1.5 Change Variant (PUT /api/v1/carts)

| Question | Answer |
|----------|--------|
| Should promotion be revalidated? | **Yes.** If same row updated: price changes, matched subtotal changes. If new row: different product_id may affect specific_product matching. |
| Should discount be recalculated? | **Yes.** |
| Should gifts be recalculated? | **Yes.** |
| Should totals be recalculated? | **Yes.** |
| Should promotion metadata change? | Only if promotion becomes invalid (e.g., new variant not in promotion's specific_products). |
| Which existing service? | Same as Add Product. |
| Can existing architecture be reused? | Yes. |

**Note**: If variant change results in a new `CartItem` row (different `product_variant_id`), the old row's promotion data is left behind. `revalidatePromotion()` handles this by checking all items for any `promotion_id` — the old row may or may not be deleted depending on the update logic.

#### 7.1.6 Replace Product (PUT /api/v1/carts)

Same as Change Variant (functionally equivalent in this system).

#### 7.1.7 Clear Cart (DELETE /api/v1/carts)

| Question | Answer |
|----------|--------|
| Should promotion be revalidated? | **No** (cart is empty, no promotion can apply). |
| Should discount be recalculated? | **N/A** (no items). |
| Should gifts be recalculated? | **N/A** (all items deleted). |
| Should totals be recalculated? | **Yes** (total_price = 0). |
| Should promotion metadata change? | **Yes** — all promotion data is deleted with items. |
| Which existing service? | `CartInventoryService::releaseCart()` — already adequate. |
| Can existing architecture be reused? | Yes — no change needed. Current behavior is correct. |

#### 7.1.8 Merge Cart (POST /api/v1/carts/pluck-items)

| Question | Answer |
|----------|--------|
| Should promotion be revalidated? | **Yes.** Same as Add Product, but for multiple items. |
| Should discount be recalculated? | **Yes.** |
| Should gifts be recalculated? | **Yes.** |
| Should totals be recalculated? | **Yes.** |
| Should promotion metadata change? | Only if promotion becomes invalid. |
| Which existing service? | `CartController::pluckItemsToCart()` → existing `storeCart()` loop → `revalidatePromotion()` after all items added. |
| Can existing architecture be reused? | Yes. **Important**: Revalidation should happen ONCE after all items are added, not once per item. |

### 7.2 Integration Point: `CartRepository::revalidatePromotion()`

```php
/**
 * Revalidates the selected promotion after cart modification.
 * 
 * Architecture: This is the single orchestration point for promotion
 * revalidation on all cart modification paths.
 *
 * @param Cart $cart — must have items loaded
 * @return void
 */
public function revalidatePromotion(Cart $cart): void
{
    // 1. Find current promotion_id from any cart item
    //    (all promoted items should have the same promotion_id)
    $promotionId = $cart->items
        ->firstWhere(fn($item) => !is_null($item->promotion_id))
        ?->promotion_id;

    if (!$promotionId) {
        return; // No promotion selected, nothing to revalidate
    }

    // 2. Try to re-apply: if promotion is still valid, discount is recalculated
    //    If promotion is invalid, exception is thrown
    try {
        $this->promotionService->applySelectedPromotion($cart, (int) $promotionId);
    } catch (\InvalidArgumentException) {
        // 3. Promotion no longer valid → clear all promotion data
        $this->promotionService->clearPromotionFromCart($cart);
    }
}
```

**Key design decisions**:
- Uses existing `PromotionService::applySelectedPromotion()` — zero new eligibility logic
- `clearPromotionFromCart()` is a new method (Todo #5)
- Exception handling for invalidation is explicit and safe
- No database queries added beyond what `applySelectedPromotion()` already does

---

## 8. Checkout Validation Plan

### 8.1 Validation Checklist

| Check | Where | Current | Target | Verification Method |
|-------|-------|---------|--------|-------------------|
| Promotion exists in database | `Promotion::find()` | ❌ Uses find() without valid() scope | ✅ Uses `Promotion::valid()` scope | `Promotion::valid()->findOrFail()` |
| Promotion is active (status=true) | `Promotion::valid()` | ❌ Not checked in `getCheckoutTotalsFromCart()` | ✅ Checked via `valid()` scope | Scope filter |
| Promotion is within date range | `Promotion::valid()` | ❌ Not checked | ✅ Checked | Scope filter |
| Usage limit not reached | `Promotion::valid()` | ❌ Not checked | ✅ Checked | Scope filter |
| Cart still meets minimum order | `AbstractPromotionStrategy::eligible()` | ❌ Not checked in order creation | ✅ Checked via full re-validation | `applySelectedPromotion()` |
| Cart still meets quantity threshold | `AbstractPromotionStrategy::eligible()` | ❌ Not checked | ✅ Checked | Strategy eligibility |
| Products still match promotion scope | `PromotionEligibilityResolver::matchedEligibility()` | ❌ Not checked | ✅ Checked | Resolver |
| Gift stock still available | `GiftPromotionStrategy::hasAvailableStock()` | ❌ Not checked | ✅ Checked | Strategy |
| Discount allocation still correct | `PromotionApplicator::applyOutcome()` | ❌ Trusts persisted allocation | ✅ Recalculated | Fresh allocation |
| Cart totals match promotion state | Various | ❌ Trusts persisted totals | ✅ Recalculated | Fresh totals |

### 8.2 Where Each Validation Belongs

| Validation | Layer | Method | Rationale |
|-----------|-------|--------|-----------|
| Promotion existence + validity | Service | `PromotionService::applySelectedPromotion()` | Already has `Promotion::valid()->lockForUpdate()->first()` |
| Eligibility rules | Engine | `PromotionEligibilityResolver::resolve()` | Already the single source of truth |
| Discount allocation | Engine | `PromotionApplicator::applyOutcome()` | Already correct |
| Gift stock | Strategy | `GiftPromotionStrategy::eligible()` | Already handles stock check |
| Final validation before order | Service | `OrderService::validateCheckoutPromotion()` | New method: calls `calculateCheckoutTotals()` with same params, throws if invalid |

### 8.3 Revised Regular Checkout Flow

```
OrderController::checkout()
    ├── Step 1: calcInvoicePrice()
    │   └── calculateCheckoutTotals()          ← UNCHANGED (applies promotion)
    │
    ├── [ time passes — payment gateway ]
    │
    └── Step 2: addItemsInOrder()
        └── DB::transaction
            ├── getCartUser()                  ← UNCHANGED
            ├── Validate coupon                ← UNCHANGED
            ├── validateCheckoutPromotion()    ← NEW: re-validate before reading data
            │   └── calculateCheckoutTotals(
            │           $cart,
            │           $storedPromotionId,
            │           $storedGiftProductId
            │       )
            │   └── If exception → throw, transaction rolls back
            │   └── If valid → returns fresh CheckoutTotals
            │
            ├── OrderCreationService::createOrder($freshCheckoutTotals)
            ├── OrderCreationService::createOrderItems()
            └── OrderCreationService::finalizeOrder()
```

**Key change**: `getCheckoutTotalsFromCart()` is replaced by `calculateCheckoutTotals()` re-validation. The stored `selected_promotion_id` and `selected_gift_product_id` are remembered from Step 1 (currently available as request parameters). Alternatively, they can be read from the cart items' `promotion_id` and `is_gift` fields.

**Alternative**: If request parameters from Step 1 are not available in Step 2 (stateless HTTP — payment gateway callback is a different request), the promotion ID can be extracted from cart items:
```php
$storedPromotionId = $cart->items
    ->firstWhere(fn($item) => !is_null($item->promotion_id))
    ?->promotion_id;
$storedGiftItemId = $cart->items
    ->firstWhere('is_gift', true)
    ?->product_variant_id;
```

### 8.4 Edge Cases

| Edge Case | Behaviour |
|-----------|-----------|
| Promotion deleted between steps | `valid()` scope returns null → exception → transaction rolls back |
| Promotion deactivated between steps | `status = false` caught by `valid()` scope → exception |
| Cart modified between steps | Fresh `calculateCheckoutTotals()` uses current cart items → correct discount |
| Gift product out of stock between steps | `reserveGiftItem()` throws → transaction rolls back |
| Multiple items with different promotion_ids | Guard in `validateCheckoutPromotion()` throws `\LogicException` |
| No promotion selected originally | `$storedPromotionId` is null → `applySelectedPromotion()` returns empty `CheckoutTotals` — correct |

---

## 9. Order Snapshot Plan

### 9.1 Currently Snapshot Fields

#### `orders` Table

| Field | Always Set? | Source |
|-------|-------------|--------|
| `promotion_id` | ✅ Yes (nullable) | `CheckoutTotals::promotionId()` |
| `promotion_code` | ✅ Yes (nullable) | `CheckoutTotals::promotionCode()` |
| `promotion_type` | ✅ Yes (nullable) | `CheckoutTotals::promotionType()` |
| `promotion_discount` | ✅ Always (0 if none) | `CheckoutTotals::promotionDiscount` |

#### `order_products` Table

| Field | Always Set? | Source |
|-------|-------------|--------|
| `promotion_id` | ✅ Yes (nullable) | Cart item attribute |
| `promotion_discount_amount` | ✅ Always (0 if none) | Computed: `(price * qty) - total_price` |
| `is_gift` | ✅ Always (false if not) | Cart item attribute |

### 9.2 Completeness Verification

| What | Stored? | Adequate? |
|------|---------|-----------|
| Which promotion was applied | `orders.promotion_id` | ✅ Adequate |
| Promotion identifier (human-readable) | `orders.promotion_code` | ✅ Adequate |
| Promotion type (percentage/fixed/gift) | `orders.promotion_type` | ✅ Adequate |
| Total monetary discount from promotion | `orders.promotion_discount` | ✅ Adequate |
| Per-item discount breakdown | `order_products.promotion_discount_amount` | ✅ Adequate |
| Which items were gifts | `order_products.is_gift` | ✅ Adequate |
| Which specific gift product variant was selected | Not stored | ⚠️ Can be inferred from gift `order_products` |
| Promotion snapshot at order time | Not stored | ⚠️ If promotion is later modified/deleted, the order can't reconstruct the promotion's original terms |

### 9.3 Are Additional Snapshot Fields Required?

**Recommendation**: No additional snapshot fields are necessary for the current business requirements.

**Rationale**:
1. The `orders` table stores promotion_id, code, type, and total discount — sufficient for financial reconciliation.
2. The `order_products` table stores per-item discount and gift status — sufficient for per-item breakdown.
3. If future requirements demand reconstructing the exact promotion terms (discount percentage, minimum order, etc.), a `promotion_snapshot` JSON column could be added, but this is YAGNI for now.
4. The existing pattern matches the order snapshot pattern used for coupons and other time-sensitive data.

### 9.4 Potential Enhancement (Future)

If business requires reconstructing an order's exact promotion terms:

```
ALTER TABLE orders ADD COLUMN promotion_snapshot JSON NULL AFTER promotion_discount;
```

Content:
```json
{
    "value": 10.00,
    "type_amount": "percentage",
    "max_discount_amount": 50.00,
    "minimum_order_amount": 100.00,
    "apply_to": "all_products"
}
```

**Not implemented in this roadmap.** YAGNI applies unless explicitly required.

---

## 10. API Improvement Plan

### 10.1 Affected APIs

| Endpoint | Current Behaviour | Change Required? | Change Description |
|----------|-----------------|------------------|-------------------|
| `GET /api/v1/carts` | No promotion data | ✅ Yes | Add `has_eligible_promotion` |
| `GET /api/v1/carts/{id}` | No promotion data | ✅ Yes | Add `has_eligible_promotion` |
| `GET /api/v1/general/checkout/promotions` | Lists eligible promotions | ❌ No | Already correct |
| `POST /api/v1/general/checkout` (calcInvoicePrice) | Applies promotion | ❌ No | Internal change only (re-validation) |
| `POST /api/v1/general/checkout` (addItemsInOrder) | Reads persisted data | ✅ Yes | Internal change only (re-validation) |
| `POST /api/v1/general/checkout/fast` | Atomic promotion | ❌ No | Internal change only (gift shipping_method) |
| `GET /api/v1/orders/{id}` | Already has promotion fields | ❌ No | No change |
| Order cancellation | No usage decrement | ✅ Yes | Internal change only |

### 10.2 `has_eligible_promotion` — Architectural Decision

**Question**: Should the Cart response expose `has_eligible_promotion`?

**Answer**: **Yes.**

**Business Justification**: The frontend currently requires **two separate API calls** to determine (a) what's in the cart and (b) whether any promotions apply. Adding `has_eligible_promotion` to the cart response eliminates this round-trip for the common case of "no eligible promotions."

**Architectural Justification**: This follows the same pattern as `ProductPricingService::enrichProductWithPricing()` — pre-serialization enrichment at the controller level. The Resource remains pure (serialization only). The eligibility check reuses `PromotionEligibilityResolver` (single source of truth).

### 10.3 Integration Point for `has_eligible_promotion`

**Architecture Decision**: Controller-level enrichment, NOT in Resource, NOT new endpoint.

**Flow**:
```
CartController::show() / CartController::index()
    ├── Load cart with items (existing)
    ├── Enrich: $cart->has_eligible_promotion = $this->promotionService->hasEligiblePromotion($cart)
    └── CartResource::make($cart)
        └── Serializes: 'has_eligible_promotion' => $this->has_eligible_promotion
```

**Why the Controller?**
- ADR-001 rule #5: "Controllers must only orchestrate requests and responses"
- The controller receives the cart model, calls a service method to enrich it, then passes it to the Resource
- This is the same pattern as `ProductPricingService::enrichProductWithPricing()` which is called before the Resource
- The Resource only reads a pre-set attribute — pure serialization

**Implementation details for `hasEligiblePromotion()`**:

```php
// In PromotionService (new method)
public function hasEligiblePromotion(Cart $cart): bool
{
    // Early exit: empty cart = no eligible promotions
    if ($cart->items->isEmpty()) {
        return false;
    }

    // Compute subtotal (re-use existing method)
    $subtotalCents = (int) round($this->subtotal($cart) * 100);

    // Load valid promotions (re-use existing method, but lightweight load)
    $promotions = Promotion::valid()->get();

    // Early exit: no promotions at all
    if ($promotions->isEmpty()) {
        return false;
    }

    // Use resolver (single source of truth) — early exit on first match
    $eligible = $this->resolver->eligible($cart, $promotions, $subtotalCents);

    return $eligible->isNotEmpty();
}
```

**Key design decisions**:
- `PromotionEligibilityResolver::eligible()` already batch-evaluates all promotions
- No new eligibility logic — reuses existing resolver
- Lightweight early-exit (first match returns true)
- No database writes — read-only evaluation

### 10.4 `CartItemResource` — New Serialization Fields

**Fields to add**:

```php
'promotion_id' => $this->promotion_id,
'discount_amount' => (float) ($this->discount_amount ?? 0),
'is_gift' => (bool) ($this->is_gift ?? false),
```

**Architecture compliance**:
- These fields already exist on the `cart_items` table
- They are already loaded on the model (no lazy load)
- The Resource only reads from `$this->attribute` — pure serialization
- Backward compatible: new fields added, existing fields unchanged

### 10.5 API Contract Changes Summary

| Change | Backward Compatible? | Frontend Impact |
|--------|---------------------|-----------------|
| Add `has_eligible_promotion` to cart response | ✅ Yes (new field) | Can use to show/hide promotion section |
| Add `promotion_id`, `discount_amount`, `is_gift` to CartItemResource | ✅ Yes (new fields) | Can show per-item discount |
| Internal re-validation on checkout | ✅ Yes (same response shape) | No change |
| Internal gift shipping_method fix | ✅ Yes (same response shape) | No change |
| Internal usage decrement on cancellation | ✅ Yes (same response shape) | No change |

---

## 11. Architecture Compliance Review

### 11.1 ADR-001 Compliance Matrix

| Rule | Requirement | All Planned Changes Compliant? | Evidence |
|------|-------------|-------------------------------|----------|
| #1 Single Pricing Authority | All pricing through `ProductPricingService` | ✅ Compliant | Promotion is cart-level, separate domain. No change to product pricing. |
| #2 Pre-Serialization Enrichment | Pricing set before Resource | ✅ Compliant | `has_eligible_promotion` set on model before Resource. Same pattern as `enrichProductWithPricing()`. |
| #3 Resource Purity | Resources serialize only | ✅ Compliant | `CartItemResource` reads from model attributes. `CartResource` reads pre-set `has_eligible_promotion`. No computation. |
| #4 Model Purity | Models are data containers | ✅ Compliant | No new model methods. `Promotion::discountAmount()` unchanged (separate domain). |
| #5 Controller Purity | Controllers orchestrate only | ✅ Compliant | Controllers call services for enrichment. No business logic in controllers. |
| #6 Zero Duplication | No duplicate formulas | ✅ Compliant | `revalidatePromotion()` uses existing `PromotionService::applySelectedPromotion()`. `hasEligiblePromotion()` uses existing `PromotionEligibilityResolver::eligible()`. |
| #7 Lightweight Accessors | No computing in accessors | ✅ Compliant | No new accessors. |
| #8 No Hidden Work | No hidden SQL | ✅ Compliant | All relations explicitly eager loaded. `Promotion::valid()` scope is explicit. |
| #9 Extensibility | Extend existing services | ✅ Compliant | `CartRepository` extended. `PromotionService` extended. `CartInventoryService` extended. No new services. |

### 11.2 Duplication Check

| Logic | Existing Location | New Location | Duplication? |
|-------|------------------|--------------|--------------|
| Eligibility evaluation | `PromotionEligibilityResolver::resolve()` | `revalidatePromotion()` calls `applySelectedPromotion()` which calls `resolve()` | ✅ Reuse |
| Batch eligibility | `PromotionEligibilityResolver::eligible()` | `hasEligiblePromotion()` calls `eligible()` | ✅ Reuse |
| Subtotal calculation | `PromotionService::subtotal()` | `hasEligiblePromotion()` calls `subtotal()` | ✅ Reuse |
| Gift removal | `PromotionService::removeGiftItems()` | `clearPromotionFromCart()` calls `removeGiftItems()` | ✅ Reuse |
| Discount application | `PromotionApplicator::applyOutcome()` | `applySelectedPromotion()` → `applyOutcome()` | ✅ Reuse |
| Cart item promotion clear | New: `clearItemPromotionData()` | Single method, all callers use it | ✅ No duplication |
| Promotion removal | New: `clearPromotionFromCart()` | Single method, called from two places | ✅ No duplication |

### 11.3 Violations — None Introduced

| What | Status | Why |
|------|--------|-----|
| Business logic in Resource | ❌ Not introduced | `CartItemResource` reads attributes. `CartResource` reads pre-set attribute. |
| Business logic in Controller | ❌ Not introduced | Controller calls service method for enrichment. |
| Business logic in Model | ✅ Frozen (existing) | `Promotion::discountAmount()` unchanged. |
| Hidden SQL | ❌ Not introduced | All queries are explicit. `Promotion::valid()` scope is explicit query. |
| Parallel eligibility logic | ❌ Not introduced | Every validation goes through `PromotionEligibilityResolver`. |
| New service creation | ❌ Not introduced | Extending existing services only. |
| New endpoint creation | ❌ Not introduced | All changes are internal or new response fields on existing endpoints. |

### 11.4 `PromotionEligibilityResolver` as Single Source of Truth

Every eligibility check in the planned changes goes through `PromotionEligibilityResolver`:

| Code Path | Calls Resolver? | Method |
|-----------|----------------|--------|
| Cart modification revalidation | ✅ Yes (via `applySelectedPromotion()` → `resolve()`) | `PromotionEligibilityResolver::resolve()` |
| Checkout Step 2 re-validation | ✅ Yes (via `applySelectedPromotion()` → `resolve()`) | `PromotionEligibilityResolver::resolve()` |
| Cart response `has_eligible_promotion` | ✅ Yes | `PromotionEligibilityResolver::eligible()` |
| Existing checkout Step 1 | ✅ Yes (unchanged) | `PromotionEligibilityResolver::resolve()` |
| Existing promotion listing | ✅ Yes (unchanged) | `PromotionEligibilityResolver::eligible()` |

**Zero new eligibility evaluation paths. Zero duplicated eligibility logic.**

---

## 12. Regression Test Plan

### 12.1 Cart Workflows

| Test Scenario | What to Verify | Critical? |
|---------------|---------------|-----------|
| Add item to empty cart | Cart created, no promotion data | ✅ Yes |
| Add item to cart with promotion | Promotion re-validated, discount recalculated | ✅ Yes |
| Add item that makes promotion invalid | Promotion cleared, discount removed | ✅ Yes |
| Add multiple items sequentially | Promotion re-validated after each add | Yes |
| Remove item from cart with promotion | Promotion re-validated, discount recalculated | ✅ Yes |
| Remove item that makes promotion invalid | Promotion cleared | ✅ Yes |
| Increase quantity of promoted item | Promotion re-validated, discount recalculated | ✅ Yes |
| Decrease quantity of promoted item | Promotion re-validated, discount recalculated | Yes |
| Change variant of promoted item | Promotion re-validated | Yes |
| Clear cart with active promotion | All items deleted, promotion data cleared | ✅ Yes |
| Merge cart (pluck items) with active promotion | Promotion re-validated once after all items added | Yes |

### 12.2 Promotion Selection

| Test Scenario | What to Verify | Critical? |
|---------------|---------------|-----------|
| Select eligible promotion | Discount applied, cart totals correct | ✅ Yes |
| Select ineligible promotion | Exception thrown, cart unchanged | ✅ Yes |
| Select percentage promotion | Discount = matched subtotal × percentage | ✅ Yes |
| Select percentage with max cap | Discount capped correctly | ✅ Yes |
| Select fixed rate promotion | Discount = min(matched subtotal, value) | ✅ Yes |
| Select gift promotion | Gift item added at price 0, no discount on items | ✅ Yes |
| Change promotion (switch from A to B) | A's gifts removed, B's discount applied | ✅ Yes |
| Remove promotion (deselect) | All gifts removed, discount cleared, totals reset | ✅ Yes |
| Continue without promotion | No promotion data, cart totals at full price | ✅ Yes |

### 12.3 Promotion Invalidation

| Test Scenario | What to Verify | Critical? |
|---------------|---------------|-----------|
| Promotion expired → cart modified | Promotion auto-cleared | ✅ Yes |
| Promotion deactivated → cart modified | Promotion auto-cleared | ✅ Yes |
| Usage limit reached → cart modified | Promotion auto-cleared | ✅ Yes |
| Minimum order not met after remove item | Promotion auto-cleared | ✅ Yes |
| Quantity threshold not met after decrease | Promotion auto-cleared | Yes |
| Specific product removed that was the only matched item | Promotion auto-cleared | ✅ Yes |
| Gift product out of stock → promotion invalid | Promotion auto-cleared | Yes |

### 12.4 Gift Promotions

| Test Scenario | What to Verify | Critical? |
|---------------|---------------|-----------|
| Gift promotion applied | Gift item created with price=0, total_price=0 | ✅ Yes |
| Gift item shipping_method | SCHEDULED for regular checkout, FAST for fast checkout | ✅ Yes |
| Gift item finalized in regular checkout | Finalized correctly via SCHEDULED path | ✅ Yes |
| Gift item finalized in fast checkout | Finalized correctly via FAST path | ✅ Yes |
| Gift item released on promotion change | Stock released, item deleted | ✅ Yes |
| Gift item released on cart clear | Stock released, item deleted | ✅ Yes |
| Gift item released on cart expiry | Stock released, item deleted | Yes |
| Gift product out of stock | Gift promotion shown as ineligible | ✅ Yes |
| Multiple gift products | Customer can select one, others available | Yes |

### 12.5 Percentage Promotions

| Test Scenario | What to Verify | Critical? |
|---------------|---------------|-----------|
| 10% off, no max cap | Discount = subtotal × 0.10 | ✅ Yes |
| 10% off, max 50 EGP | Discount = min(subtotal × 0.10, 50) | ✅ Yes |
| 0% off | Zero discount | Yes |
| 100% off | Discount = subtotal (free) | Yes |
| Large subtotal, small percentage | Correct allocation across items | Yes |

### 12.6 Fixed Promotions

| Test Scenario | What to Verify | Critical? |
|---------------|---------------|-----------|
| Fixed 75 EGP off, subtotal 1000 | Discount = 75 | ✅ Yes |
| Fixed 75 EGP off, subtotal 50 | Discount = 50 (capped to subtotal) | ✅ Yes |
| Fixed 0 EGP off | Zero discount | Yes |

### 12.7 Checkout

| Test Scenario | What to Verify | Critical? |
|---------------|---------------|-----------|
| Regular checkout with valid promotion | Order created with correct promotion data | ✅ Yes |
| Regular checkout with promotion deactivated mid-flow | Order creation fails, exception thrown | ✅ Yes |
| Regular checkout with cart modified mid-flow | Discount recalculated correctly | ✅ Yes |
| Regular checkout without promotion | Order created with null promotion fields | ✅ Yes |
| Fast checkout with gift promotion | Gift item finalized, inventory updated | ✅ Yes |
| Fast checkout with percentage promotion | Order created with correct discount | ✅ Yes |
| Coupon + promotion together | Both discounts applied, correct final total | ✅ Yes |
| Checkout with promotion that just reached usage limit | Order creation fails | ✅ Yes |

### 12.8 Order Creation

| Test Scenario | What to Verify | Critical? |
|---------------|---------------|-----------|
| Order created with promotion | promotion_id, code, type, discount stored | ✅ Yes |
| Order items with promotion discount | per-item discount_amount stored | ✅ Yes |
| Gift items in order | is_gift=true, price=0, promotion_id set | ✅ Yes |
| Order without promotion | All promotion fields null/0 | ✅ Yes |
| Multiple items with proportional discount | Each item's discount_amount reflects proportional allocation | ✅ Yes |

### 12.9 Order Cancellation

| Test Scenario | What to Verify | Critical? |
|---------------|---------------|-----------|
| Cancel order with promotion | Usage decremented by 1 | Yes |
| Cancel order without promotion | Usage unchanged | Yes |
| Cancel same order twice | Usage decremented only once | Yes |
| Cancel order where usage was already 0 | Usage stays 0 (no negative) | Yes |

### 12.10 Inventory

| Test Scenario | What to Verify | Critical? |
|---------------|---------------|-----------|
| Gift item reserved on promotion apply | Stock reserved correctly | ✅ Yes |
| Gift item released on promotion removal | Stock released | ✅ Yes |
| Gift item finalized on order completion | Stock finalized correctly | ✅ Yes |
| Non-gift item with promotion | Stock unchanged (already reserved at add-to-cart) | Yes |

### 12.11 Usage Counters

| Test Scenario | What to Verify | Critical? |
|---------------|---------------|-----------|
| Usage incremented on order creation | `promotions.usage` incremented | ✅ Yes |
| Usage not incremented on failed checkout | Usage unchanged | ✅ Yes |
| Usage at limit prevents new orders | Checkout throws exception | ✅ Yes |
| Usage decremented on order cancellation | Usage decreased | Yes |
| Concurrent orders respect usage limit | No over-limit usage | ✅ Yes |

### 12.12 API Responses

| Test Scenario | What to Verify | Critical? |
|---------------|---------------|-----------|
| Cart response has `has_eligible_promotion` | Boolean field present, correct value | Yes |
| CartItemResource has promotion fields | `promotion_id`, `discount_amount`, `is_gift` present | Yes |
| CartItemResource backward compatible | Existing fields unchanged | ✅ Yes |
| Promotion listing unchanged | Same response shape | ✅ Yes |
| Checkout response unchanged | Same response shape | ✅ Yes |

### 12.13 Concurrent Requests

| Test Scenario | What to Verify | Critical? |
|---------------|---------------|-----------|
| Two users apply same promotion simultaneously | No duplicate usage | ✅ Yes |
| User modifies cart while checkout in progress | Promotion re-validated on checkout step 2 | ✅ Yes |
| Admin deactivates promotion while customer checks out | Checkout step 2 fails | ✅ Yes |
| Two orders placed simultaneously with same promotion | Both get correct allocations | ✅ Yes |

### 12.14 Edge Cases

| Test Scenario | What to Verify | Critical? |
|---------------|---------------|-----------|
| Empty cart checkout | Appropriate error (cart empty) | Yes |
| Cart with only gift items | Subtotal = 0, no matched items for discount | Yes |
| Promotion applies to 0 items after cart change | Promotion invalid, cleared | ✅ Yes |
| Decimal discount amounts on multiple items | Allocation sums correctly to total discount | ✅ Yes |
| Very large discount amount | No overflow or negative prices | Yes |
| Promotion with `apply_to = all_products` | All non-gift items matched | ✅ Yes |
| Promotion with `apply_to = specific_products` | Only matching products matched | ✅ Yes |
| Cart with items from multiple shops | Only matching products affected | Yes |

---

## 13. Risks

### 13.1 Technical Risks

| Risk | Likelihood | Impact | Mitigation |
|------|-----------|--------|------------|
| Performance regression on cart endpoints (due to promotion re-validation on every cart modification) | Medium | Medium | `revalidatePromotion()` is a no-op if no promotion is selected. For carts with promotion, `applySelectedPromotion()` already runs during checkout — adding it to cart modification adds the same cost to cart writes. Acceptable. |
| Race condition: cart modification triggers revalidation while checkout is also applying promotion | Low | High | `applySelectedPromotion()` uses `lockForUpdate()` on cart + items. `revalidatePromotion()` in cart modification path would also need to lock. If both operations are in separate transactions, the second one waits. Implementation must ensure `revalidatePromotion()` uses the same locking pattern. |
| Infinite loop: `revalidatePromotion()` triggers cart modification which triggers `revalidatePromotion()` | Very Low | Catastrophic | Design constraint: `revalidatePromotion()` does NOT modify cart items directly — it calls `applySelectedPromotion()` which updates existing items' `total_price` but does not add/remove items. No recursive trigger. |
| `has_eligible_promotion` causes N+1 if not eagerly loaded | Medium | Medium | `PromotionService::hasEligiblePromotion()` must accept a `$cart` with loaded items. The controller must ensure items are loaded before calling it. Standard eager loading pattern. |
| Gift shipping_method from "cart context" is ambiguous | Medium | Low | The cart has `normal_items` and `fast_items` collections. The shipping_method for gift items must match the cart's primary shipping context. If cart has mixed shipping methods, easiest safe approach: set gift `shipping_method` to the same as the first non-gift item's shipping_method. |

### 13.2 Business Risks

| Risk | Likelihood | Impact | Mitigation |
|------|-----------|--------|------------|
| Promotion re-validation on every cart modification could be perceived as slow by users | Low | Medium | Revalidation only happens when a promotion is selected (minority of users). The `applySelectedPromotion()` call is sub-100ms. No noticeable latency. |
| `has_eligible_promotion` adds load to every cart response | Low | Low | Early-exit on empty cart. Early-exit on no valid promotions. Lightweight evaluation. |
| Checkout step 2 re-validation could cause order failures for legitimate edge cases | Low | High | This is the INTENDED behaviour — preventing incorrect orders. If a promotion becomes invalid between steps, the order MUST fail. |

### 13.3 Rollback Risks

| Phase | Rollback Complexity | Risk of Partial Rollback |
|-------|---------------------|-------------------------|
| Phase 1 (P1 fixes) | Low — single file changes | Low — each fix is self-contained |
| Phase 2 (Cart sync) | Medium — changes across controller, repository, service | Low — `revalidatePromotion()` is a single added call |
| Phase 3 (Cleanup) | Low — isolated method changes | Low |
| Phase 4 (Usage decrement) | Low — single new method call | Low |
| Phase 5 (API enhancement) | Low — remove enrichment call | Low |
| Phase 6 (Hardening) | Low — performance only | Low |

---

## 14. Open Questions

These questions must be answered before implementation begins. They affect architectural decisions and implementation details.

### 14.1 Business Questions

| # | Question | Impact | Recommended Answer |
|---|----------|--------|-------------------|
| 1 | Should gift items inherit `shipping_method` from the cart, the selected shipping option, or the first non-gift item? | Affects Phase 1 gift shipping fix | Inherit from the first non-gift item in the cart. If multiple shipping methods exist, default to `SCHEDULED` (backward compatible). |
| 2 | Should order cancellation decrement the promotion usage counter if the order was created during a period where usage was at the limit? | Affects Phase 4 usage decrement | Yes. If the order was successfully created, the usage was valid at that time. Cancellation should always decrement (idempotently). |
| 3 | Is there an existing UI pattern for "remove promotion" that the frontend expects? | Affects whether a new endpoint is needed | If frontend sends `selected_promotion_id = null` in the checkout request, the `applySelectedPromotion()` fix (Phase 3) handles it. No new endpoint needed. If frontend expects a separate "remove promotion" step, a new endpoint may be required. |
| 4 | Should `has_eligible_promotion` be part of the cart response or should the frontend continue to call the dedicated promotions endpoint? | Affects Phase 5 API change | Both. `has_eligible_promotion` is a lightweight boolean indicator. The frontend still uses `GET /checkout/promotions` for the full eligible promotion list with details. |

### 14.2 Technical Questions

| # | Question | Impact | Recommended Answer |
|---|----------|--------|-------------------|
| 5 | In `addItemsInOrder()` (Step 2 of regular checkout), how is the original `selected_promotion_id` from Step 1 accessed? | Affects Phase 1 checkout fix | Option A: Store in session. Option B: Read from cart items. Option C: Pass as query param on payment callback. **Recommend**: Read from cart items (first non-null `promotion_id`). No session dependency, no URL param. |
| 6 | Should `revalidatePromotion()` run inside the same transaction as `persistCart()` or in a separate transaction? | Affects Phase 2 transaction safety | Same transaction. If promotion revalidation fails, the cart modification should roll back. This ensures atomicity of "modify + revalidate." |
| 7 | Should `clearItemPromotionData()` be called BEFORE or AFTER `reserveItem()` in `persistCart()`? | Affects Phase 2 data flow | BEFORE. The item should have its promotion data cleared before `reserveItem()` recalculates `total_price`. This prevents a window where `total_price` is recalculated without discount but `discount_amount` is still set. |
| 8 | What is the performance profile of `PromotionEligibilityResolver::eligible()` for a cart with 100+ promotions? | Affects Phase 5 performance | Unknown. If this is slow, `hasEligiblePromotion()` should have a LIMIT 1 optimization (stop after first eligible promotion found). The resolver's `eligible()` method currently evaluates ALL promotions. An early-exit variant may be needed. |

### 14.3 Design Questions

| # | Question | Impact | Recommended Answer |
|---|----------|--------|-------------------|
| 9 | Should `CartRepository::revalidatePromotion()` log when it auto-clears a promotion? | Operational visibility | Yes. Use `Log::info()` with cart_id, user_id, promotion_id, and reason (e.g., "promotion expired", "minimum order not met"). This aids debugging. |
| 10 | Should the system track that a promotion was "auto-cleared" vs "manually removed"? | Future analytics | Optional. If needed, add a `cleared_reason` column or log entry. Not required for MVP. |
| 11 | Should `has_eligible_promotion` also return the count of eligible promotions? | API completeness | No. YAGNI. The frontend calls the dedicated endpoint for details. The boolean is sufficient for showing/hiding the promotion section. |

---

## 15. Final Recommended Implementation Order

### Execution Sequence

```
Phase 1 (P1 Bug Fixes)
    ├── Fix #1: reserveGiftItem() shipping_method
    │       → Files: CartInventoryService, CartInventoryServiceTest
    │       → Deployable: Yes (isolated)
    │
    └── Fix #2: getCheckoutTotalsFromCart() re-validation
            → Files: OrderService, OrderServiceTest, PromotionService
            → Deployable: Yes (but Phase 2 should follow quickly)

Phase 2 (Cart Synchronization)
    ├── Fix #3: clearItemPromotionData() in reserveItem()
    │       → Files: CartInventoryService, CartInventoryServiceTest
    │
    ├── Fix #4: revalidatePromotion() in all cart paths
    │       → Files: CartRepository, CartController (marvel), CartControllerTest
    │       → Requires: Fix #3 (clearItemPromotionData)
    │       → Deployable: After Fix #3
    │
    └── Fix #5: applySelectedPromotion() null cleanup
            → Files: PromotionService, PromotionServiceTest
            → Deployable: Yes (can deploy with Phase 1)

Phase 3 (Lifecycle Cleanup)
    ├── Fix #6: CartItemResource expose promotion fields
    │       → Files: CartItemResource, CartResponseTest
    │       → Deployable: Yes (independent)
    │
    └── Fix #7: decrementUsage() on order cancellation
            → Files: PromotionService, OrderService, OrderCancellationTest
            → Deployable: Yes (independent)

Phase 4 (API Enhancement)
    └── Fix #8: has_eligible_promotion in cart response
            → Files: PromotionService, CartController (marvel), CartResource, CartResponseTest
            → Requires: Phase 2 (stable cart data)
            → Deployable: After Phase 2

Phase 5 (Hardening)
    ├── Fix #9: remove redundant matchedEligibility() call
    │       → Files: PromotionService
    │       → Deployable: Yes (independent)
    │
    └── Fix #10: extract shared subtotal calculation
            → Files: PromotionService, PromotionEligibilityResolver, OrderService
            → Deployable: Yes (independent)
```

### Dependency Graph

```
reserveGiftItem() shipping_method  ──────────────► Phase 1 complete
        │
getCheckoutTotalsFromCart() re-val ──────────────► Phase 1 complete
        │
clearItemPromotionData() ────────────────────────► Phase 2 prerequisite
        │
revalidatePromotion() ─── depends on ────────────► clearItemPromotionData()
        │
applySelectedPromotion() null fix ───────────────► independent (Phase 1/2)
        │
CartItemResource fields ─────────────────────────► independent (Phase 3)
        │
decrementUsage() ────────────────────────────────► independent (Phase 3)
        │
has_eligible_promotion ── depends on ────────────► Phase 2 (stable cart data)
        │
Remove redundant matchedEligibility ─────────────► independent (Phase 5)
        │
Extract subtotal calculation ────────────────────► independent (Phase 5)
```

### Minimum Viable Fix (Deployable First)

The smallest deployable fix set is:

1. **`reserveGiftItem()` shipping_method** — 1 file, 1 line addition. Fixes inventory leak.
2. **`getCheckoutTotalsFromCart()` re-validation** — 2-3 files. Fixes stale promotion in orders.
3. **`applySelectedPromotion()` null cleanup** — 1 file. Fixes incomplete promotion removal.

These three fixes address all P1 and P3 bugs. They can be deployed independently and provide immediate correctness improvements.

### Full Implementation

Full implementation requires all 6 phases. Estimated complexity:

| Phase | Files Changed | Test Files | Effort Estimate |
|-------|--------------|------------|-----------------|
| Phase 1 (P1 fixes) | 3-4 | 2 new + 2 existing | 🟢 Low (1-2 days) |
| Phase 2 (Cart sync) | 5-6 | 2 new + 3 existing | 🟡 Medium (3-5 days) |
| Phase 3 (Cleanup) | 4-5 | 2 new + 2 existing | 🟢 Low (2-3 days) |
| Phase 4 (API) | 3-4 | 1 new + 1 existing | 🟢 Low (1-2 days) |
| Phase 5 (Hardening) | 3-4 | 2 existing | 🟢 Low (1 day) |

**Total**: 12-20 working days (including testing, code review, QA).

---

## Document Metadata

- **Author**: AI Software Architecture — Implementation Planning
- **Date**: 2026-07-14
- **Purpose**: Convert audit findings into implementation-ready roadmap
- **Status**: Planning only — no code was modified
- **Frozen Architecture**: ADR-001 respected throughout
- **Audit References**: promotion-system-analysis.md, promotion-lifecycle-audit.md, promotion-architecture-verification.md
