# Architecture Approval Report: Promotion System Implementation

> **Document Type**: Architecture Approval Review
> **Status**: Pre-implementation review — no code was modified
> **Date**: 2026-07-14
> **Reviewer**: Principal Software Architect
> **Reviewed Artifact**: `docs/promotion-implementation-roadmap.md`
> **Frozen Architecture**: ADR-001 respected throughout

---

## Table of Contents

1. [Executive Summary](#1-executive-summary)
2. [Overall Readiness Assessment](#2-overall-readiness-assessment)
3. [Dependency Graph Review](#3-dependency-graph-review)
4. [Phase Review](#4-phase-review)
5. [Definition of Done](#5-definition-of-done)
6. [Architecture Guardrails](#6-architecture-guardrails)
7. [Impact Matrix](#7-impact-matrix)
8. [Risk Assessment](#8-risk-assessment)
9. [Missing Work](#9-missing-work)
10. [Architecture Compliance](#10-architecture-compliance)
11. [Regression Coverage](#11-regression-coverage)
12. [Final Recommendations](#12-final-recommendations)
13. [Final Approval Decision](#13-final-approval-decision)

---

## 1. Executive Summary

### Review Scope

This report reviews `docs/promotion-implementation-roadmap.md` — the implementation plan for fixing the Promotion System's 4 confirmed bugs, establishing cart-promotion synchronization, and hardening the lifecycle.

The review evaluates dependency correctness, phase completeness, architecture compliance, risk coverage, regression adequacy, and overall implementation readiness.

### Review Verdict

**APPROVED WITH REQUIRED CHANGES** — 6 mandatory changes must be completed before implementation begins. These are documented in Section 12. None change the architecture. All are additions to the existing plan.

### Summary of Findings

| Category | Count | Critical | Required Before Implementation |
|----------|-------|----------|-------------------------------|
| Dependency corrections | 2 | 1 | 1 |
| Phase gaps | 5 | 3 | 2 |
| Missing Definition of Done | 6 | 6 | 1 (minimum) |
| Architecture guardrail additions | 4 | 2 | 1 |
| Missing regression tests | 7 | 3 | 2 |
| Must-fix before implementation | 6 | 6 | 6 |

---

## 2. Overall Readiness Assessment

### 2.1 Readiness Meter

```
Not Ready ────●──── Ready
              │
       Requires Changes
```

The plan is structurally sound but has specific gaps that must be addressed before implementation. The architecture is correct. The reuse strategy is correct. The compliance with ADR-001 is verified. The gaps are in completeness of definitions, dependency accuracy, and missing edge cases — not in fundamental architectural direction.

### 2.2 Strengths of the Plan

| Strength | Assessment |
|----------|------------|
| Architecture compliance | Full ADR-001 compliance. No violations. |
| Reuse strategy | All new code reuses `PromotionEligibilityResolver`, `PromotionService::applySelectedPromotion()`, `removeGiftItems()`. Zero duplicated eligibility logic. |
| Extension points | Well-chosen. `CartRepository::revalidatePromotion()` as single orchestration point is correct. |
| Backward compatibility | All changes backward compatible. New fields only. No endpoint changes. |
| Testing coverage | 90+ scenarios across 14 categories. Strong coverage of cart, checkout, and lifecycle. |
| Rollback planning | Every phase has documented rollback. |
| Risk documentation | Technical, business, and rollback risks documented. |
| Phase ordering | P1 → P2 → P3 → P4 → P5 → P6 is logically correct. |

### 2.3 Gaps Requiring Resolution

| Gap | Section | Severity | Must Fix Before Implementation? |
|-----|---------|----------|-------------------------------|
| No Definition of Done for any phase | Section 4 | High | ✅ Yes |
| Phase 3 dependency on Phase 2 is incorrect | Section 4.3 | Medium | ✅ Yes |
| `CartController::destroy()` scope inconsistency | Section 4 vs Section 7.1.7 | Medium | ✅ Yes |
| `getCheckoutTotalsFromCart()` fate is ambiguous | Section 8.3 | Medium | ✅ Yes |
| Exception handling in `revalidatePromotion()` too narrow | Section 7.2 | Medium | ✅ Yes |
| No mandatory logging for auto-cleared promotions | Section 14.1 Q9 | Low | Yes (recommended) |
| `hasEligiblePromotion()` performance for 100+ promotions | Section 10.3 | Medium | ✅ Yes |
| `selected_gift_product_id` reconstruction gap | Section 8.3 | Low | Yes (recommended) |

---

## 3. Dependency Graph Review

### 3.1 Verified Dependency Graph

```
Phase 1: P1 Bug Fixes
├── Fix #1: reserveGiftItem() shipping_method
│   └── Dependencies: NONE (truly independent)
│
└── Fix #2: getCheckoutTotalsFromCart() re-validation
    └── Dependencies: NONE (truly independent)

Phase 2: Cart-Promotion Synchronization
├── Fix #3: clearItemPromotionData()
│   └── Dependencies: NONE (independent of Phase 1) ← MISSTATED in plan
│
├── Fix #4: revalidatePromotion()
│   └── Dependencies: Fix #3 (clearItemPromotionData) ✓
│   └── Logical dependency on Fix #2 (same pattern) but not code-dependency
│
└── Fix #5: applySelectedPromotion() null fix
    └── Dependencies: NONE (independent, can be parallel with Phase 1)

Phase 3: Lifecycle Cleanup
├── Fix #6: CartItemResource fields
│   └── Dependencies: NONE ← MISSTATED as Phase 2 dependency
│
└── Fix #7: decrementUsage()
    └── Dependencies: Fix #2 (promotion_id on orders must be correct)
    └── Does NOT depend on Phase 2 ← MISSTATED in plan

Phase 4: API Enhancement
└── Fix #8: has_eligible_promotion
    └── Dependencies: Phase 2 (logical — stable cart data for reliable results)
    └── Strict code-dependency: NONE (eligibility is read-only)

Phase 5: Hardening
├── Fix #9: remove redundant matchedEligibility()
│   └── Dependencies: NONE
│
└── Fix #10: extract subtotal
    └── Dependencies: NONE
```

### 3.2 Dependency Corrections Required

| # | Stated Dependency | Actual Dependency | Correction | Impact |
|---|------------------|-------------------|------------|--------|
| 1 | Phase 2 depends on Phase 1 | Fix #3 is independent; Fix #4 logically depends on Fix #2 pattern but not strictly code-dependent | Allow Fix #3 to start in parallel with Phase 1 | Medium — parallel execution possible |
| 2 | Phase 3 depends on Phase 2 | Fix #6 is independent; Fix #7 depends on Phase 1 only (Fix #2) | Phase 3 can start after Phase 1 | Medium — unlocks parallel work |
| 3 | Phase 4 depends on Phase 2 | Fix #8 is logically but not strictly dependent | Phase 4 can technically start after Phase 1 | Low — keep as-is for safety |

### 3.3 Safe Parallelization Opportunities

The following can be implemented in parallel:

| Parallel Group | Tasks | Risk |
|---------------|-------|------|
| Track A | Fix #1, Fix #3, Fix #5, Fix #6, Fix #7 | Low — all independent |
| Track B | Fix #2, Fix #4, Fix #8 | Medium — sequential within track |
| Track C | Fix #9, Fix #10 | Low — independent, can be anytime |

### 3.4 Critical Path

The critical path for correctness is:

```
Fix #2 (checkout re-validation) ───────────► Fix #4 (cart sync) ───────────► Fix #8 (API)
                                                      │
Fix #3 (clear promo data) ───────────────────────────┘
```

---

## 4. Phase Review

### 4.1 Phase 1: P1 Bug Fixes — Data Integrity

| Criterion | Assessment |
|-----------|------------|
| Purpose clear | ✅ Yes |
| Scope complete | ✅ Yes (covers both P1 bugs) |
| Responsibilities correct | ✅ Yes |
| Architecture support | ✅ Yes |
| Hidden dependencies | ✅ None |
| Risks documented | ✅ High (financial/inventory) |
| Testing sufficient | ✅ Yes (7 scenarios covering all failure modes) |
| **Gap found** | ❌ `getCheckoutTotalsFromCart()` fate ambiguous — should it be deleted, modified, or kept for backward compatibility? |

**Verdict**: Approved. The gap is minor and documented in Section 12 for resolution.

### 4.2 Phase 2: Cart-Promotion Synchronization

| Criterion | Assessment |
|-----------|------------|
| Purpose clear | ✅ Yes |
| Scope complete | ⚠️ Partial — scope includes `CartController::destroy()` clearance but Section 7.1.7 says current behavior is adequate. Inconsistency. |
| Responsibilities correct | ✅ Yes (CartRepository orchestrates, services execute) |
| Architecture support | ✅ Yes |
| Hidden dependencies | ✅ Identified: clearItemPromotionData must precede revalidatePromotion |
| Risks documented | ✅ Medium (performance impact) |
| Testing sufficient | ✅ Yes (10 scenarios) |
| **Gaps found** | ❌ `CartController::destroy()` scope inconsistency. ❌ `revalidatePromotion()` only catches `InvalidArgumentException` — unexpected exceptions propagate without recovery. |

**Verdict**: Approved with changes. Two gaps to resolve.

### 4.3 Phase 3: Promotion Removal & Lifecycle Cleanup

| Criterion | Assessment |
|-----------|------------|
| Purpose clear | ✅ Yes |
| Scope complete | ✅ Yes |
| Responsibilities correct | ✅ Yes |
| Architecture support | ✅ Yes |
| Hidden dependencies | ❌ Stated dependency on Phase 2 is incorrect. Both Fix #6 and Fix #7 can work after Phase 1. |
| Risks documented | ✅ Low |
| Testing sufficient | ✅ Yes (5 scenarios) |
| **Gap found** | ❌ Incorrect dependency on Phase 2. |

**Verdict**: Approved with change. Dependency corrected in Section 12.

### 4.4 Phase 4: Order Lifecycle — Usage Decrement

| Criterion | Assessment |
|-----------|------------|
| Purpose clear | ✅ Yes |
| Scope complete | ✅ Yes |
| Responsibilities correct | ✅ Yes |
| Architecture support | ✅ Yes |
| Hidden dependencies | ⚠️ Depends on `changeOrderStatus()` having access to the order's `promotion_id` (already stored on `orders` table). Verified in audit — always set. |
| Risks documented | ✅ Low |
| Testing sufficient | ✅ Yes (5 scenarios covering idempotency, never-negative, concurrent) |
| **Gap found** | None. |

**Verdict**: Approved.

### 4.5 Phase 5: API Enhancement — `has_eligible_promotion`

| Criterion | Assessment |
|-----------|------------|
| Purpose clear | ✅ Yes |
| Scope complete | ✅ Yes |
| Responsibilities correct | ⚠️ Controller-level enrichment call is orchestration (allowed). Service method `hasEligiblePromotion()` does the work. Correct. |
| Architecture support | ✅ Yes |
| Hidden dependencies | ⚠️ Logical dependency on Phase 2 for data stability. Not a code dependency. |
| Risks documented | ✅ Low |
| Testing sufficient | ✅ Yes (8 scenarios + performance benchmark) |
| **Gap found** | ❌ `PromotionEligibilityResolver::eligible()` evaluates ALL promotions (no LIMIT 1). For 100+ promotions, this is 100+ `resolve()` calls. Performance profile unknown. Open Question #8 recommends optimization before production. |

**Verdict**: Approved with change. Performance optimization must be mandated, not optional.

### 4.6 Phase 6: Regression Hardening & Performance

| Criterion | Assessment |
|-----------|------------|
| Purpose clear | ✅ Yes |
| Scope complete | ✅ Yes |
| Responsibilities correct | ✅ Yes |
| Architecture support | ✅ Yes |
| Hidden dependencies | ✅ None |
| Risks documented | ✅ Low |
| Testing sufficient | ✅ Yes (4 scenarios) |
| **Gap found** | None. |

**Verdict**: Approved.

---

## 5. Definition of Done

The implementation plan does not include measurable completion criteria for any phase. The following Definition of Done must be established before each phase begins.

### 5.1 Phase 1 DoD

- [ ] Gift items created by `reserveGiftItem()` have `shipping_method` set from cart context
- [ ] In fast checkout, gift items are finalized via `finalizeItemsByShippingMethod('FAST')`
- [ ] In regular checkout, gift items are finalized via `finalizeItemsByShippingMethod('SCHEDULED')`
- [ ] `addItemsInOrder()` calls `calculateCheckoutTotals()` instead of `getCheckoutTotalsFromCart()`
- [ ] Invalid promotion (deactivated, expired, usage-limit-reached, minimum-order-not-met) during `addItemsInOrder()` throws exception, transaction rolls back
- [ ] Cart modified between `calcInvoicePrice()` and `addItemsInOrder()` results in recalculated discount
- [ ] All Phase 1 regression tests pass
- [ ] No existing test fails

### 5.2 Phase 2 DoD

- [ ] `CartInventoryService::reserveItem()` clears `promotion_id` and `discount_amount` on modified items
- [ ] `CartRepository::revalidatePromotion()` is called after every cart modification (add, remove, update qty, variant, merge)
- [ ] Revalidation catches `InvalidArgumentException` and calls `clearPromotionFromCart()`
- [ ] Revalidation catches all unexpected exceptions and re-throws (fail-closed)
- [ ] If promotion remains valid after modification, discount is recalculated for the new cart composition
- [ ] If promotion becomes invalid after modification, all promotion data is cleared from cart
- [ ] `revalidatePromotion()` runs inside the same transaction as the cart modification
- [ ] No recursive loop: `revalidatePromotion()` does not trigger further cart modifications
- [ ] Cart total_price is correct after every modification
- [ ] All Phase 2 regression tests pass
- [ ] Performance: no endpoint response time increase > 100ms for carts without promotion; < 200ms for carts with promotion

### 5.3 Phase 3 DoD

- [ ] `PromotionService::applySelectedPromotion($cart, null)` clears gift items AND clears `promotion_id`/`discount_amount` on all non-gift items
- [ ] `PromotionService::clearPromotionFromCart()` removes all gifts, clears all promotion fields, recalculates cart totals to undiscounted values
- [ ] `CartItemResource` serializes `promotion_id`, `discount_amount`, `is_gift`
- [ ] Existing `CartItemResource` fields unchanged (backward compatibility verified)
- [ ] All Phase 3 regression tests pass

### 5.4 Phase 4 DoD

- [ ] `PromotionService::decrementUsage()` decrements `promotions.usage` for a given promotion ID
- [ ] `decrementUsage()` never allows `usage < 0` (minimum 0)
- [ ] `decrementUsage()` is idempotent — calling twice on same order decrements only once (guard against duplicate calls)
- [ ] `OrderService::changeOrderStatus()` calls `decrementUsage()` when cancelling an order that has `promotion_id`
- [ ] If order status change fails, `decrementUsage()` rolls back
- [ ] `decrementUsage()` uses `lockForUpdate()` to prevent race conditions
- [ ] All Phase 4 regression tests pass

### 5.5 Phase 5 DoD

- [ ] `PromotionService::hasEligiblePromotion()` returns `true` if any valid promotion is eligible for the cart
- [ ] `hasEligiblePromotion()` returns `false` for empty cart, no promotions, or no eligible promotions
- [ ] `hasEligiblePromotion()` is read-only — no database writes
- [ ] Controller calls `hasEligiblePromotion()` and sets `$cart->has_eligible_promotion` before `CartResource::make()`
- [ ] `CartResource` serializes `has_eligible_promotion` from the pre-set attribute
- [ ] Resource has zero eligibility logic — pure serialization
- [ ] Cart response time does not increase by more than 50ms (with benchmark)
- [ ] All Phase 5 regression tests pass

### 5.6 Phase 6 DoD

- [ ] `matchedEligibility()` is called exactly once during `applySelectedPromotion()` (verified by test assertion)
- [ ] Shared subtotal method exists and is used by all 3 callers (`PromotionService`, `PromotionEligibilityResolver`, `OrderService`)
- [ ] All promotion paths verified for correct eager loading (no N+1 queries)
- [ ] Guard in order creation: multiple `promotion_id` values on cart items throws `LogicException`
- [ ] All Phase 6 regression tests pass

---

## 6. Architecture Guardrails

The following guardrails are mandatory for every Pull Request related to promotion work. Code review MUST reject any violation.

### 6.1 Frozen Architecture (ADR-001)

| Guardrail | Description | Review Check |
|-----------|-------------|--------------|
| G-01 | Never create new pricing services. All product pricing through `ProductPricingService`. | `git diff --stat` — no new service files created |
| G-02 | Never move business logic into Resources. Resources serialize only. | Review every Resource changed — no `PromotionEligibilityResolver`, no `Promotion::valid()`, no direct SQL |
| G-03 | Never move business logic into Models. Models are data containers. | No new model methods that compute values or execute queries |
| G-04 | Never move business logic into Controllers. Controllers orchestrate only. | Controller changes must call services, never contain eligibility/discount logic |
| G-05 | Never bypass `PromotionEligibilityResolver`. It is the single source of truth for eligibility. | Any new eligibility check must go through the resolver |
| G-06 | Never duplicate promotion formulas. | Subtotal formula, discount formula, eligibility logic — each must live in exactly one place |
| G-07 | Never introduce hidden SQL. | All queries must be explicit. No lazy loading in Resources. No `load()`, `loadMissing()`, or `refresh()` outside Services. |

### 6.2 Promotion-Specific Guardrails

| Guardrail | Description | Review Check |
|-----------|-------------|--------------|
| G-08 | Always reuse `PromotionService::applySelectedPromotion()` for revalidation. Never create parallel apply logic. | Every revalidation path traces to `PromotionService::applySelectedPromotion()` |
| G-09 | `CartRepository::revalidatePromotion()` is the single orchestration point for cart modification revalidation. | No ad-hoc revalidation in controllers or other services |
| G-10 | Never call `PromotionEligibilityResolver::matchedEligibility()` directly outside of `resolve()`. | `matchedEligibility()` is internal to the resolver |
| G-11 | All promotion database mutations must use `lockForUpdate()` (pessimistic locking). | `applyOutcome()`, `incrementUsage()`, `decrementUsage()`, `reserveGiftItem()` |
| G-12 | Gift items must always have `shipping_method` set explicitly (never rely on column default). | `CartInventoryService::reserveGiftItem()` payload must include `'shipping_method'` |
| G-13 | `revalidatePromotion()` must run inside the same transaction as the triggering cart modification. | Atomic: if revalidation fails, cart modification rolls back |
| G-14 | `revalidatePromotion()` must catch `\InvalidArgumentException` AND `\Throwable`. Catch `\Throwable` to log and re-throw (fail-closed for unexpected errors). | No silent failures |

### 6.3 API Contract Guardrails

| Guardrail | Description | Review Check |
|-----------|-------------|--------------|
| G-15 | Never remove or rename existing JSON response fields. | `git diff` on Resource files — only additions |
| G-16 | New fields must be additive-only (frontend can ignore unknown fields). | `git diff` on Resource files |
| G-17 | `has_eligible_promotion` must be a boolean. Never string, int, or null. | Type assertion in test |
| G-18 | `CartItemResource` promotion fields (`promotion_id`, `discount_amount`, `is_gift`) must read from model attributes only. | No computation in Resource |

### 6.4 Testing Guardrails

| Guardrail | Description | Review Check |
|-----------|-------------|--------------|
| G-19 | Every cart modification operation must have a test for promotion revalidation. | Coverage report |
| G-20 | Every bug fix must have a regression test that fails before the fix and passes after. | Test suite |
| G-21 | Concurrent request tests for every promotion mutation path. | Test coverage |
| G-22 | `has_eligible_promotion` must have a performance benchmark test. | CI benchmark step |

### 6.5 Logging Guardrails

| Guardrail | Description | Review Check |
|-----------|-------------|--------------|
| G-23 | Every auto-cleared promotion must be logged: cart_id, user_id, promotion_id, reason. | Log line in `revalidatePromotion()` catch block |
| G-24 | Every usage increment and decrement must be logged: order_id, promotion_id, new_usage value. | Log line in `incrementUsage()` and `decrementUsage()` |

---

## 7. Impact Matrix

### 7.1 Complete Impact Matrix by Phase

| Artifact | Phase 1 | Phase 2 | Phase 3 | Phase 4 | Phase 5 | Phase 6 | Total Files |
|----------|---------|---------|---------|---------|---------|---------|-------------|
| **Controllers** | | | | | | | |
| `CartController` (marvel) | — | ✅ Modified | — | — | ✅ Modified | — | 1 |
| `OrderController` (app) | — | — | — | — | — | — | 0 |
| **Services** | | | | | | | |
| `PromotionService` | — | ✅ Modified | ✅ Modified | ✅ New Method | ✅ New Method | ✅ Modified | 1 |
| `CartInventoryService` | ✅ Modified | ✅ Modified | — | — | — | — | 1 |
| `OrderService` | ✅ Modified | — | — | ✅ Modified | — | ✅ Modified | 1 |
| `FastShippingService` | — | — | — | — | — | — | 0 |
| **Repositories** | | | | | | | |
| `CartRepository` | — | ✅ Modified | — | — | — | — | 1 |
| **Resources** | | | | | | | |
| `CartResource` (marvel) | — | — | — | — | ✅ Modified | — | 1 |
| `CartItemResource` (marvel) | — | — | ✅ Modified | — | — | — | 1 |
| **Engine** | | | | | | | |
| `PromotionEligibilityResolver` | — | — | — | — | — | ✅ Modified | 1 |
| `PromotionApplicator` | — | — | — | — | — | — | 0 |
| **DTOs** | | | | | | | |
| `CheckoutTotals` | — | — | — | — | — | — | 0 |
| **Models** | | | | | | | |
| `Cart` (marvel) | — | — | — | — | ✅ New Attribute | — | 0 |
| `CartItem` (marvel) | — | — | — | — | — | — | 0 |
| **Database** | | | | | | | |
| `cart_items` | — | — | — | — | — | — | 0 (no schema changes) |
| `orders` | — | — | — | — | — | — | 0 |
| `promotions` | — | — | — | — | — | — | 0 |
| **API Endpoints** | | | | | | | |
| `POST /api/v1/carts` | — | ✅ Internal change | — | — | — | — | 0 (response shape) |
| `PUT /api/v1/carts` | — | ✅ Internal change | — | — | — | — | 0 |
| `DELETE /api/v1/carts/{itemId}` | — | ✅ Internal change | — | — | — | — | 0 |
| `DELETE /api/v1/carts` | — | — | — | — | — | — | 0 |
| `POST /api/v1/carts/pluck-items` | — | ✅ Internal change | — | — | — | — | 0 |
| `POST /api/v1/general/checkout` | ✅ Internal change | — | ✅ Internal change | — | — | — | 0 |
| `POST /api/v1/general/checkout/fast` | ✅ Internal change | — | — | — | — | — | 0 |
| `GET /api/v1/carts` | — | — | — | — | ✅ New field | — | 1 (response shape) |
| `GET /api/v1/carts/{id}` | — | — | ✅ New fields | — | ✅ New field | — | 1 (response shape) |
| **Tests** | | | | | | | |
| `PromotionFlowTest` | ✅ New | ✅ New | ✅ New | ✅ New | ✅ New | ✅ New | 6 |
| `CartControllerTest` | — | ✅ New | — | — | — | — | 1 |
| `OrderServiceTest` | ✅ New | — | — | ✅ New | — | — | 2 |
| `PromotionServiceTest` | — | ✅ New | ✅ New | — | — | ✅ New | 3 |
| `CartInventoryServiceTest` | ✅ New | ✅ New | — | — | — | — | 2 |
| Performance benchmark | — | — | — | — | ✅ New | — | 1 |

### 7.2 Files NOT Modified (Confirmed Stable)

- `FastShippingService` — internal flow correct; only gift shipping_method payload changes handled by `CartInventoryService`
- `OrderCreationService` — snapshot creation correct; receives fresh `CheckoutTotals` from caller
- `PromotionEligibilityResolver` — single source of truth; unchanged (Phase 6 may add early-exit variant)
- `PromotionApplicator` — allocation algorithm correct; unchanged
- `PromotionStrategy` hierarchy — strategy pattern correct; unchanged
- All DTOs (`CheckoutTotals`, `PromotionEvaluation`, `PromotionResult`, `GiftItem`, `DiscountOutcome`, `GiftOutcome`) — data structures correct; unchanged
- `Promotion::discountAmount()` — model purity violation but frozen as-is; unchanged
- `Promotion::valid()` scope — correct; unchanged
- `PromotionObserver` — logging correct; unchanged
- Route definitions — no new endpoints; unchanged

---

## 8. Risk Assessment

### 8.1 Risk Matrix

| Risk ID | Phase | Category | Description | Likelihood | Impact | Severity | Mitigation |
|---------|-------|----------|-------------|------------|--------|----------|------------|
| R-01 | 1 | Technical | `getCheckoutTotalsFromCart()` re-validation changes order creation data source — unexpected payment gateway interactions | Low | High | **High** | Verify with staging payment gateway. Test all gateway callbacks. |
| R-02 | 1 | Data Consistency | Gift `shipping_method` decision wrong for mixed-shipping carts (NORMAL + FAST items) | Medium | Medium | **Medium** | Document the rule: gift shipping_method = first non-gift item's shipping_method. Test with mixed carts. |
| R-03 | 2 | Performance | Every cart modification triggers `applySelectedPromotion()` — potential latency increase for add-to-cart operations | Medium | Medium | **Medium** | Only runs when promotion is selected (minority of carts). Benchmark before release. |
| R-04 | 2 | Concurrency | Cart modification revalidation uses `lockForUpdate()` — higher contention on cart table | Medium | Medium | **Medium** | Acceptable for e-commerce volume. Monitor deadlock rate. |
| R-05 | 2 | Architecture | `revalidatePromotion()` catches only `\InvalidArgumentException` — unexpected exceptions fail the cart modification | Medium | High | **High** | Change to catch `\Throwable`, log, call `clearPromotionFromCart()`. Documented in required changes. |
| R-06 | 3 | Data Consistency | `clearPromotionFromCart()` called during cart modification conflicts with concurrent checkout application | Low | High | **Medium** | Same transaction with `lockForUpdate()` prevents this. |
| R-07 | 3 | Regression | `CartItemResource` new fields cause frontend issues if response is unexpectedly large | Low | Low | **Low** | 3 small fields. Trivial payload increase. |
| R-08 | 4 | Data Consistency | `decrementUsage()` race condition — order cancelled and new order uses same promotion simultaneously | Low | Medium | **Low** | `lockForUpdate()` prevents this. |
| R-09 | 4 | Technical | `decrementUsage()` called twice for same order (double-post from frontend) | Medium | Low | **Low** | Idempotency guard: check order status before decrementing. |
| R-10 | 5 | Performance | `hasEligiblePromotion()` evaluates ALL valid promotions — 100+ evaluations for a single boolean | Medium | Medium | **High** | Mandatory optimization: early-exit on first eligible match. Documented in required changes. |
| R-11 | 5 | Technical | `hasEligiblePromotion()` N+1 if cart items or promotions not eagerly loaded | Medium | Low | **Medium** | Guard: `$cart->relationLoaded('items')` assertion. |
| R-12 | 6 | Regression | Removing `matchedEligibility()` call changes `applySelectedPromotion()` behaviour | Low | Medium | **Low** | Verify same `PromotionEvaluation` values produced. |
| R-13 | All | Regression | Any change breaks existing promotion workflow | Low | Critical | **High** | Full regression suite must pass before deployment. |
| R-14 | All | Concurrency | Two admin operations (promotion update + cart operation) create inconsistent state | Low | Medium | **Low** | Admin operations run in separate transactions. Carts revalidate on next modification. |

### 8.2 Risk Classification Summary

| Severity | Count | Risk IDs |
|----------|-------|----------|
| Critical | 0 | — |
| High | 4 | R-01, R-05, R-10, R-13 |
| Medium | 8 | R-02, R-03, R-04, R-06, R-07, R-08, R-09, R-11 |
| Low | 2 | R-07, R-12 |

### 8.3 Rollback Complexity Assessment

| Phase | Complexity | Recovery Strategy |
|-------|-----------|------------------|
| 1 | Low | Revert `reserveGiftItem()` payload. Revert `getCheckoutTotalsFromCart()` to original. |
| 2 | Medium | Revert `revalidatePromotion()` calls in 4 locations. Revert `clearItemPromotionData()`. |
| 3 | Low | Revert `applySelectedPromotion()` logic. Revert `CartItemResource` fields. |
| 4 | Low | Revert `decrementUsage()` call in `changeOrderStatus()`. |
| 5 | Low | Remove enrichment call from CartController. Remove field from CartResource. |
| 6 | Low | Revert individual method changes. |

---

## 9. Missing Work

### 9.1 Missing Edge Cases

| # | Missing Item | Phase | Severity | Must Fix? | Recommendation |
|---|-------------|-------|----------|-----------|----------------|
| M-01 | `getCheckoutTotalsFromCart()` fate not decided — kept, deleted, or marked deprecated? | 1 | Medium | ✅ Yes | Keep as `@deprecated` private method. Replace all internal callers with `calculateCheckoutTotals()`. Remove after 1 release cycle. |
| M-02 | `CartController::destroy()` — scope says "clear promotion data" but Section 7.1.7 says current behavior is adequate | 2 | Medium | ✅ Yes | Resolve inconsistency: document that `destroy()` deletes all items including promotion data implicitly. Remove from Phase 2 scope. |
| M-03 | `revalidatePromotion()` exception handling too narrow — only `\InvalidArgumentException` caught | 2 | High | ✅ Yes | Catch `\Throwable`, log `critical` level, re-throw. Unexpected exceptions must not be silently absorbed. |
| M-04 | `selected_gift_product_id` — how to reconstruct between checkout steps | 1/3 | Medium | Yes (recommended) | Read from cart items: find gift item's `product_variant_id`. If multiple gifts exist, this is ambiguous. Document limitation and add guard. |
| M-05 | `hasEligiblePromotion()` evaluating ALL 100+ promotions without LIMIT 1 | 5 | High | ✅ Yes | Mandate: add `eligible()` overload with `$limit = null` parameter, or create `eligibleExists()` with early-exit. |
| M-06 | Logging for auto-cleared promotions | 2 | Medium | Yes (recommended) | Add `Log::info()` in `revalidatePromotion()` catch block. Add to Phase 2 scope. |
| M-07 | `changeOrderStatus()` idempotency guard for `decrementUsage()` | 4 | Medium | Yes (recommended) | Check `orders.status` was not already 'cancelled' before decrementing. If already cancelled, skip. |

### 9.2 Missing Business Scenarios

| # | Scenario | Phase | Should Be Handled? | Notes |
|---|----------|-------|--------------------|-------|
| M-08 | Admin modifies promotion after it's applied to active carts | None | Already handled implicitly | Next cart modification triggers revalidation. Acceptable window. |
| M-09 | Admin deletes promotion that's applied to active carts | None | Already handled | `revalidatePromotion()` → `applySelectedPromotion()` → promotion not found → `clearPromotionFromCart()`. |
| M-10 | Cart expires while promotion is applied | None | Already handled | `expireCart()` deletes all items including gifts. Promotion data removed. |
| M-11 | 100% discount promotion (order total = 0) | None | Needs verification | Does payment gateway handle zero-amount orders? This is an existing question, not introduced by this plan. |
| M-12 | Guest cart promotion (unauthenticated user) | None | Not addressed in plan | Verify that cart operations for guest users also trigger `revalidatePromotion()`. If guest carts use the same `CartRepository` methods, they're covered. |

### 9.3 Missing Failure Scenarios

| # | Scenario | Phase | Must Fix? |
|---|----------|-------|-----------|
| M-13 | `applySelectedPromotion()` throws `\Throwable` (e.g., `PDOException` from deadlock) during cart modification | 2 | ✅ Yes (see M-03) |
| M-14 | `decrementUsage()` called on promotion that was deleted after order creation | 4 | Low — `Promotion::query()->find($id)` returns null → skip. Document as safe. |
| M-15 | `revalidatePromotion()` called on cart with items that have MIXED `promotion_id` values (shouldn't happen, but no guard) | 2 | Low — the existing guard picks the first `promotion_id`. Phase 6 adds a `LogicException` guard. |

---

## 10. Architecture Compliance

### 10.1 ADR-001 Compliance Verification

| ADR-001 Rule | All Planned Changes Compliant? | Evidence |
|--------------|-------------------------------|----------|
| #1 Single Pricing Authority | ✅ Compliant | No changes to product pricing. Promotion is cart-level domain. |
| #2 Pre-Serialization Enrichment | ✅ Compliant | `has_eligible_promotion` set on model before `CartResource`, same pattern as `enrichProductWithPricing()`. |
| #3 Resource Purity | ✅ Compliant | `CartItemResource` reads model attributes. `CartResource` reads pre-set attribute. Zero computation. |
| #4 Model Purity | ✅ Compliant | No new model methods. No new accessors. `Promotion::discountAmount()` unchanged (separate domain). |
| #5 Controller Purity | ✅ Compliant | Controllers call services. No eligibility logic in controllers. Enrichment is orchestration, not business logic. |
| #6 Zero Duplication | ✅ Compliant | All eligibility goes through `PromotionEligibilityResolver`. All revalidation through `applySelectedPromotion()`. |
| #7 Lightweight Accessors | ✅ Compliant | No new accessors that compute values. |
| #8 No Hidden Work | ✅ Compliant | All queries explicit. `Promotion::valid()` scope is explicit. Early loading ensured. |
| #9 Extensibility | ✅ Compliant | Extending existing services only. No new services created. |

### 10.2 SOLID Compliance

| Principle | Assessment |
|-----------|------------|
| **S**ingle Responsibility | ✅ Each extension point has one responsibility: `clearItemPromotionData()` clears; `revalidatePromotion()` revalidates; `clearPromotionFromCart()` removes; `decrementUsage()` decrements; `hasEligiblePromotion()` checks. |
| **O**pen/Closed | ✅ Existing classes are extended (`CartRepository`, `PromotionService`, `CartInventoryService`) rather than modified for new functionality. `PromotionEligibilityResolver` and strategies remain closed. |
| **L**iskov Substitution | ✅ Not applicable — no new class hierarchies. |
| **I**nterface Segregation | ✅ No fat interfaces created. Each new method has minimal, focused parameters. |
| **D**ependency Inversion | ✅ Services depend on abstractions (resolver, applicator injected via constructor). |

### 10.3 Architecture Violations Found

**Zero violations introduced by the plan.**

The plan explicitly preserves:
- `PromotionEligibilityResolver` as single source of truth for eligibility
- `PromotionApplicator` for discount allocation
- `Promotion::valid()` scope for promotion existence checks
- Strategy pattern for type-specific computation
- Transaction-based persistence with `lockForUpdate()`

### 10.4 Architecture Violations That Already Exist (Not Introduced)

These are acknowledged from the audit but not fixed by this plan (separate scope):

| Violation | Location | Severity | Why Not Fixed |
|-----------|----------|----------|--------------|
| Business logic in Model | `Promotion::discountAmount()` | Medium | Separate domain from frozen pricing architecture. Fixing would require refactoring the entire calculation flow — out of scope for this work. |
| Subtotal duplication | 3 locations | Low | Phase 6 addresses this (Fix #10). Already planned. |
| Hidden SQL in Resource | `CartItemResource::getFirstMediaUrl()` | Medium | Media library call, not promotion-related. Separate concern. |

---

## 11. Regression Coverage

### 11.1 Coverage Verification

The plan's regression test plan (Section 12) covers 90+ scenarios across 14 categories. The following verification confirms coverage adequacy.

| Area | Scenarios Covered | Critical Paths Covered? | Missing |
|------|------------------|------------------------|---------|
| Cart workflows | 11 scenarios | ✅ Yes | None |
| Promotion selection | 9 scenarios | ✅ Yes | None |
| Promotion invalidation | 7 scenarios | ✅ Yes | None |
| Gift promotions | 9 scenarios | ✅ Yes | None |
| Percentage promotions | 5 scenarios | ✅ Yes | None |
| Fixed promotions | 3 scenarios | ✅ Yes | None |
| Checkout | 8 scenarios | ✅ Yes | None |
| Order creation | 5 scenarios | ✅ Yes | None |
| Order cancellation | 4 scenarios | ✅ Yes | None |
| Inventory | 4 scenarios | ✅ Yes | None |
| Usage counters | 5 scenarios | ✅ Yes | None |
| API responses | 5 scenarios | ✅ Yes | None |
| Concurrent requests | 4 scenarios | ⚠️ Partial | See missing below |
| Edge cases | 8 scenarios | ✅ Yes | None |

### 11.2 Missing Regression Scenarios

| # | Missing Scenario | Area | Critical? |
|---|-----------------|------|-----------|
| T-01 | Concurrent add-to-cart (two items simultaneously) while promotion is active — verify no deadlock and correct allocation | Concurrent | ✅ Yes |
| T-02 | Gift stock becomes unavailable between `calcInvoicePrice()` and `addItemsInOrder()` — verify order creation fails gracefully | Checkout | ✅ Yes |
| T-03 | Cart with mixed shipping methods (NORMAL + FAST items) + gift promotion — verify gift shipping_method assignment | Gift | Yes |
| T-04 | `revalidatePromotion()` called when `\PDOException` is thrown — verify fail-closed | Cart | Yes |
| T-05 | Guest user with promotion — verify same revalidation behavior as authenticated user | Cart | Yes |
| T-06 | `has_eligible_promotion` returns `true` but frontend receives `false` — type assertion test (must be boolean) | API | Yes |
| T-07 | `decrementUsage()` called when promotion row is locked by concurrent transaction — verify expected behavior (wait or fail) | Usage | Yes |
| T-08 | Admin disables promotion in the milliseconds between `calculateCheckoutTotals()` re-validation and order creation | Concurrency | ✅ Yes |
| T-09 | 100 concurrent orders all using the same promotion — verify no over-limit usage | Concurrency | Yes |

### 11.3 Recommended Additions

The following scenarios should be added to the regression test plan (Section 12.15):

```
### 12.15 Concurrency & Race Conditions (ADDENDUM)

| Test Scenario | What to Verify | Critical? |
|---------------|---------------|-----------|
| Two items added concurrently to promoted cart | Allocation correct after both additions | ✅ Yes |
| Gift stock expires between checkout steps | Order creation fails gracefully | ✅ Yes |
| Admin disables promotion at exact moment of checkout | Order creation fails | ✅ Yes |
| 100 concurrent orders, same promotion | Usage never exceeds limiter | ✅ Yes |
| PDOException during revalidatePromotion | Cart modification rolls back | Yes |
| Guest cart with promotion | Same revalidation as authenticated | Yes |
```

---

## 12. Final Recommendations

### 12.1 Required Changes (Must Complete Before Implementation)

These 6 changes are mandatory. Implementation must NOT begin until they are resolved.

| # | Change | Section | Type | Rationale |
|---|--------|---------|------|-----------|
| RC-01 | Add Definition of Done to all 6 phases | Section 5 | Documentation | Without measurable DoD, phases cannot be verified complete. Use the DoD from Section 5 of this report. |
| RC-02 | Fix `revalidatePromotion()` exception handling: catch `\Throwable` instead of `\InvalidArgumentException` only | Section 7.2 | Code | Unexpected exceptions (`PDOException`, `LogicException`, etc.) would fail silently and leave cart in inconsistent state. |
| RC-03 | Mandate early-exit optimization for `hasEligiblePromotion()` | Section 10.3 | Code | `PromotionEligibilityResolver::eligible()` evaluates all promotions. For a boolean check, stop on first match. Create `eligibleExists()` method or add `$limit` parameter. |
| RC-04 | Resolve `getCheckoutTotalsFromCart()` fate: mark `@deprecated`, replace all callers | Section 8.3 | Documentation | Ambiguity leads to confusion. Explicitly deprecate with a removal timeline. |
| RC-05 | Resolve `CartController::destroy()` inconsistency: remove from Phase 2 scope | Section 4 vs 7.1.7 | Documentation | Current behavior is correct. No change needed. Remove from scope to avoid misleading developers. |
| RC-06 | Correct Phase 3 dependency (does NOT depend on Phase 2) | Section 4.3 | Documentation | Fix #6 and Fix #7 can proceed after Phase 1. Enables parallel development. |

### 12.2 Recommended Changes (Fix Before Production, Not Before Implementation)

These are strongly recommended but do not block implementation start.

| # | Change | Section | Rationale |
|---|--------|---------|-----------|
| RC-07 | Add mandatory logging for auto-cleared promotions | Section 7.2 | Without logging, debugging invalid-promotion-auto-clear is nearly impossible. |
| RC-08 | Add idempotency guard to `decrementUsage()` in `changeOrderStatus()` | Section 4.4 | Prevent double-decrement from duplicate cancellation requests. |
| RC-09 | Add `selected_gift_product_id` reconstruction documentation | Section 8.3 | Ambiguous if multiple gift items in cart. Document limitation. |
| RC-10 | Add missing regression tests (T-01 through T-09) | Section 12 | Critical concurrency scenarios not covered. |
| RC-11 | Add `mixed_promotion_ids` guard in order creation | Section 6 | Phase 6 mentions it but should be explicitly added to order creation validation. |

### 12.3 Implementation Strategy Corrections

| # | Current Strategy | Corrected Strategy |
|---|-----------------|-------------------|
| 1 | Phase 3 after Phase 2 | Phase 3 can start after Phase 1 (Fix #7 needs Phase 1 Fix #2 for reliable promotion_id on orders; Fix #6 is completely independent) |
| 2 | Phase 2 after Phase 1 | Fix #3 (clearItemPromotionData) can start in parallel with Phase 1. Fix #4 (revalidatePromotion) logically depends on Fix #2 pattern knowledge but not code-complete Phase 1. |
| 3 | Fix #5 in Phase 2 | Fix #5 (applySelectedPromotion null cleanup) is independent and fixes a P3 bug. Can be in Phase 1 or as a hotfix. |

---

## 13. Final Approval Decision

### 13.1 Architecture Review Summary

| Category | Count |
|----------|-------|
| Total required changes before implementation | **6** |
| Total recommended changes before production | **5** |
| Architecture violations introduced | **0** |
| ADR-001 violations | **0** |
| Missing regression scenarios | **9** (recommended) |
| Implementation order corrections | **3** |
| Risk: High severity | **4** (all mitigated) |
| Risk: Critical severity | **0** |

### 13.2 Final Assessment

The implementation roadmap is architecturally sound. The reuse strategy correctly preserves `PromotionEligibilityResolver` as the single source of truth. All new code extends existing services. ADR-001 is fully respected. Zero architecture violations are introduced. Backward compatibility is maintained across all API changes.

The 6 required changes (RC-01 through RC-06) are documentation and code-hardening items — not architectural changes. They do not alter the roadmap's direction. They ensure the roadmap is complete enough to execute safely.

### 13.3 Approval Decision

---

# APPROVED WITH REQUIRED CHANGES

Approval is granted subject to resolution of the 6 required changes (RC-01 through RC-06) before any implementation begins.

Once resolved, no further architecture review is required. Implementation can proceed per the corrected dependency graph.

The 5 recommended changes (RC-07 through RC-11) should be completed before production deployment but do not block implementation start.

### 13.4 Implementation Start Checklist

Before writing the first line of code, verify:

- [ ] RC-01: Definition of Done added to all phases (use Section 5 of this report)
- [ ] RC-02: `revalidatePromotion()` exception handling changed to catch `\Throwable`
- [ ] RC-03: `hasEligiblePromotion()` uses early-exit optimization (not full batch evaluation)
- [ ] RC-04: `getCheckoutTotalsFromCart()` fate documented with `@deprecated`
- [ ] RC-05: `CartController::destroy()` removed from Phase 2 scope
- [ ] RC-06: Phase 3 dependency corrected to Phase 1 (not Phase 2)

---

## Document Metadata

- **Reviewer**: Principal Software Architect
- **Date**: 2026-07-14
- **Reviewed Artifact**: `docs/promotion-implementation-roadmap.md`
- **Supporting Documents**: `docs/architecture/runtime-pricing-architecture.md`, `docs/architecture/AI-DEVELOPMENT-RULES.md`, `docs/promotion-system-analysis.md`, `docs/promotion-lifecycle-audit.md`, `docs/promotion-architecture-verification.md`
- **Status**: `APPROVED WITH REQUIRED CHANGES`
- **Scope**: Architecture review only — no code was modified
