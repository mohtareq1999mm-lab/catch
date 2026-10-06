# Phase 04 — Promotion Lifecycle

## 1. Executive Verdict

**PASS WITH RESIDUAL RISKS.** The promotion engine is the best-preserved subsystem relative to its manual: strategy pattern intact (percentage/fixed/gift), read-only eligibility, largest-remainder allocation, locked limiter-guarded usage increment, floored decrement, and the reversibility-on-cancel rule (with the newer Rule 17 / ORD-1 refinements for paid vs never-paid cancels) all verified in current code. The gift model was correctly re-architected (cart gift rows → order-line descriptors reserved atomically with the order). Two manual problems are retired (P4-C1 column now non-nullable; P4-C3 floor guard verified). Residual risks: stacking precedence is emergent rather than stated, gift availability is check-then-act across two moments, and runtime concurrency proof is missing.

## 2. Phase Objective

Per `PHASE-04-PROMOTION-LIFECYCLE.md` (source: `HEAD:docs/production-manual/PHASE-04-PROMOTION-LIFECYCLE.md`, 327 lines): document strategy-based eligibility, application with row locks, post-payment consumption guarded by `promotion_consumed`, reversal-on-cancel (the only reversible discount), gift reservation, validity windows, problems P4-C1..C4, and recommendations R4-1..R4-5.

## 3. Scope

**In scope:** eligibility resolution, application at checkout, gift descriptors, usage increment/decrement, validity, ending-soon notifications, promotion audit events, stacking order vs coupons.

**Out of scope:** flash-sale pricing (separate subsystem, boundary noted), coupon rules (Phase 03), checkout orchestration (Phase 01), admin promotion CRUD (Phase 13).

## 4. What Was Supposed to Be Implemented

The manual claims: `PromotionEligibilityResolver` + three strategies (percentage/fixed/gift) computing read-only outcomes; `PromotionApplicator` applying discount outcomes with proportional largest-remainder allocation under locks and gift outcomes via `CartInventoryService::reserveGiftItem` (cart gift rows with `reserved_quantity`); `incrementUsage` (limiter-guarded locked increment) called post-payment; `decrementUsage` on cancel (only reversible discount); `valid()` scope; problems P4-C1 (schema-checked flag), P4-C2 (shared gift inventory), P4-C3 (decrement floor fragility), P4-C4 (gift availability snapshot); recommendations R4-1..R4-5.

## 5. What Actually Exists

The manual's core is accurate with three material evolutions:

1. **Gifts re-architected**: no cart gift rows, no `reserveGiftItem` (method does not exist). Gift promotions resolve to order-line descriptors (`PromotionService.php:99-113`); the gift line is created and reserved atomically with the order during checkout (`OrderReservationService`); legacy gift cart rows are purged (`removeLegacyGiftRows`, `PromotionService.php:229-232`) and never snapshotted (`OrderCreationService.php:271-276`). Explicit gift requested-but-unavailable now fails loudly instead of silently dropping (`PromotionService.php:114-119`).
2. **Cancel semantics refined**: unpaid cancel decrements (Rule 17); paid cancel keeps usage; never-paid expiry cancel skips decrement (ORD-1/D3 via `skipPromotionDecrement`) — `OrderService.php:1206-1214`. Manual's blanket "reversed on cancel" is now conditional.
3. **P4-C1 retired**: `promotion_consumed` (and `coupon_consumed`) are non-nullable `default(false)` since migration `2026_07_27_081603` (`:14-15`); `Schema::hasColumn` guards are defense-in-depth only. P4-C3 retired: `decrementUsage` floors at `usage > 0` (`PromotionService.php:211-223`) — cannot go negative through this path.
4. Unchanged and verified: strategy classes (`PromotionEngine/Strategies/{Abstract,Percentage,Fixed,Gift}PromotionStrategy.php`), outcomes (`DiscountOutcome/GiftOutcome`), `valid()` scope semantics (`Promotion.php:149+`), `isValid()` (`:171+`), `incrementUsage` limiter guard (`PromotionService.php:185-200`), largest-remainder allocation (applicator — behaviorally preserved), ending-soon notifier (daily, wishlist-scoped, idempotent via `ending_soon_notified_at`), `PromotionObserver` audit + `PromotionActivated` event.

## 6. Architecture

```
eligiblePromotions → resolver->resolve (read-only: matched items/subtotal/qty → strategy eligible → outcome)
applySelectedPromotion (checkout, in checkout txn):
  removeLegacyGiftRows → lock valid() promotion (with products/gifts+v stock) → resolve
  → DiscountOutcome: applicator->applyOutcome (locks, in-lock re-evaluation, largest-remainder split, line writes, cart refresh)
  → GiftOutcome: descriptor only (product/variant/qty/promotion_id) — NO cart write, NO reservation
  → totals: subtotal derived post-promotion (financial-invariant fix, :135-138)
  → no promotion selected: clearPromotionFromCart (strip marks, restore line totals)
Completion: finalizePromotionUsageAfterPayment (once via promotion_consumed) → incrementUsage (limiter-guarded lock)
Cancel: unpaid → decrementUsage (floored); paid → keep; never-paid expiry → skip (ORD-1)
Expiry/notify: promotions:notify-ending-soon daily → wishlist users → stamp notified_at
Admin lifecycle: PromotionObserver (audit + PromotionActivated on enable)
```

## 7. Complete Execution Flow

1. **Discovery**: `GET checkout/promotions` → `eligiblePromotionsForUser` → `eligiblePromotionsPayload` (read-only; no locks, no writes).
2. **Selection at checkout**: `calculateCheckoutTotals` → `applySelectedPromotion(cart, promotionId, giftId, method)`: invalid/expired/ineligible selection throws `InvalidArgumentException` → 422 (fail-closed, no silent drop); unavailable gift throws similarly.
3. **Discount split**: largest-remainder across matched non-gift lines, capped per line (no negative prices); line `discount_amount/promotion_id/total_price` persisted; cart reloaded.
4. **Gift**: descriptor carried in `CheckoutTotals->giftItems` → `createOrderItems` materializes the gift order line → `reserveForOrder` reserves its stock with the order's lines.
5. **Completion**: usage incremented once; flag set.
6. **Cancel**: conditional decrement per Rule 17/ORD-1 (verified `OrderService.php:1206-1214`).
7. **Expiry**: validity is evaluated live at selection (`valid()` + `lockForUpdate` row); no reservation means no expiry handling needed; ending-soon notifier is informational.

## 8. Business Rules

| # | Rule | Implementation | Bypass? |
|---|---|---|---|
| R1 | Eligibility is read-only; no quota held before payment | Resolver/applicator split; `lockForUpdate` only for consistency at apply | No |
| R2 | Usage increments exactly once, post-payment | `promotion_consumed` guard + limiter-guarded increment | No |
| R3 | Promotion is the only reversible discount: unpaid cancel decrements; paid cancel keeps; never-paid expiry skips | `OrderService.php:1206-1214` | `$skipPromotionDecrement` is system-only (reaper) |
| R4 | decrement never goes negative | `where('usage','>',0)` floor | No (through this path) |
| R5 | Gifts are descriptors until order creation; unavailable gifts fail loudly | `PromotionService.php:99-119` | No |
| R6 | Gift + sellable share one stock pool (P4-C2 unchanged by design) | Reservation against product/variant rows | No — documented trade-off |
| R7 | Validity = status + limiter + date window, evaluated live under lock | `valid()` + `isValid()` + locked fetch | No |
| R8 | Stacking order: promotion first, coupon on the remainder | `calculateCheckoutTotals` (`OrderService.php:577-580`) | Emergent, not configured — see F-03 |

## 9. Source of Truth / Authorities

- **Eligibility**: `PromotionEligibilityResolver` + strategies (sole deciders).
- **Application**: `PromotionApplicator` (sole writer of line promo marks).
- **Usage counter**: `PromotionService::incrementUsage/decrementUsage` (sole writers of `promotions.usage`; limiter/floor guards inside).
- **Gift availability at checkout**: resolver snapshot + order-time reservation (two-moment check — see F-04).
- **Lifecycle/audit**: `PromotionObserver` + `PromotionActivated` event.
- **Retired**: `reserveGiftItem`, cart gift rows (purged, never snapshotted).

## 10. Database Impact

`promotions` (+`ending_soon_notified_at` `2026_08_13_000002`); `promotion_product` pivot; `promotion_gift_products` (gift catalog + variant + qty); orders promo snapshot (`promotion_id/code/type/discount`, `promotion_consumed` non-nullable default false since `2026_07_27_081603`); `cart_items` promo preview columns (transient, stripped on edit/clear); gift stock moves through product/variant `reserved_quantity` at order reservation (shared pool — P4-C2 by design).

## 11. API Surface

| Method | URI | Auth | Handler | Notes |
|---|---|---|---|---|
| GET | `/promotions`, `/promotions/{slug}` | public (Marvel) | `PromotionController` | catalog browsing |
| GET | `/checkout/promotions` | sanctum | `OrderController::eligiblePromotions` | eligible payload for cart |
| (checkout) | `selected_promotion_id`, `selected_gift_product_id` inputs | sanctum | `OrderCreateRequest` + `addItemsInOrder` | existence-validated; eligibility re-checked under lock |

## 12. Authentication & Authorization

Promotion catalog is public; selection requires authenticated checkout; eligibility is per-cart (no user targeting in promotions — contrast coupons); admin CRUD behind admin permissions (Phase 13). No promotion-specific object auth beyond cart ownership.

## 13. Validation

Selection existence (`exists:promotions/id`, `exists:products/id`); validity + eligibility re-evaluated under `lockForUpdate` at apply (invalid → 422, unavailable gift → 422); quantity/minimum/limiter/date rules in strategies; gift price_cents==0 filter (`resolveSelectedGiftItem`, `PromotionService.php:249-263`).

## 14. Transactions

Apply runs inside the checkout transaction (locks: promotion row, cart, items; in-lock re-evaluation in applicator). Usage increment runs inside completion transaction (locked, limiter-guarded). Decrement runs inside the cancel transaction. Ending-soon notifier is non-transactional chunked work with idempotency stamps. `promotion_consumed` set in the same unit as the increment (no afterCommit split — the whole completion rolls back together, which is correct).

## 15. Concurrency

| Case | Protection | Classification |
|---|---|---|
| Limiter race at apply | `valid()` + `lockForUpdate` re-check; final gate is the completion increment | STRONGLY REASONED |
| Usage over-increment | limiter-guarded locked increment; `promotion_consumed` once-flag | STRONGLY REASONED |
| Decrement below zero | `usage > 0` floor | PROVEN (code) |
| Gift stock race | resolver snapshot + atomic order-time reservation; oversell impossible, availability drift possible (F-04) | STRONGLY REASONED |
| True parallel proof | `PromotionCheckoutTest`, `PromotionProductionHardenTest` exist | UNPROVEN — NOT EXECUTED |

## 16. Async / Queues / Events

`PromotionActivated` → distribution-adjacent listeners (coupon-style start triggers are coupon-domain; promotion listeners: availability/price-drop user notifications per ESP user-notification block); ending-soon via scheduler (daily) → wishlist action → user notifications; audit via observer (sync). No promotion outbox; no retry semantics beyond scheduler re-runs (idempotent stamps).

## 17. Error Handling

Invalid/ineligible selection → 422 with message (no silent fallback); unavailable gift → 422 (loud, not dropped); completion increment failure → rolls back completion (correct — usage and completion are atomic); decrement failure modes: null id → no-op; zero usage → no-op; cancel transaction would roll back on promotion-table errors (fail-closed, arguably loud for a peripheral write — acceptable).

## 18. Security

No promotion-specific attack surface beyond checkout auth; limiter cannot be bypassed (server-side increment under lock); gift price forced to descriptor quantities with zero price enforced at snapshot (`resolveSelectedGiftItem` filters `price_cents==0`); no user-supplied promotion amounts anywhere (all server-computed).

## 19. Performance

Eligibility is read-only with constrained eager loads (products:id, gifts + variant stock); apply locks one promotion row + cart + lines — short unit; ending-soon notifier chunked (500) with per-product wishlist fan-out (bounded by 24h-expiry cohort); no caching of eligibility (correct — validity is time/limiter-sensitive).

## 20. Tests & Verification

`PromotionCheckoutTest`, `PromotionFlowTest`, `PromotionCrudTest`, `PromotionProductionHardenTest`, `UserPromotionFlashSaleNotificationTest`, `PromotionFlashSaleNotificationE2ETest` — plus checkout-suite promotion assertions (`CheckoutPendingOrderRedesignTest::test_promotion_usage_*`). **None executed** (environment). Manual R4-3 (decrement floor test) and R4-4/R4-5 (afterCommit/event) have no confirmed dedicated tests — see F-05.

## 21. Edge Cases

Covered: selection expired between discovery and checkout (422); limiter hit between apply and completion (increment no-ops via guard while flag sets — usage not counted but order completes: acceptable since eligibility held at apply... note this asymmetry in F-06); gift out of stock at apply (422) vs at reservation (checkout fails atomically — consistent); promotion deleted mid-checkout (locked fetch misses → 422); paid cancel keeps usage; never-paid expiry keeps usage; zero-usage decrement no-ops; legacy gift rows purged on every apply/clear.

## 22. Potential Bugs

### Finding F-01
- Severity: MEDIUM
- Type: DOCUMENTATION
- Status: PROVEN
#### Finding
The manual's gift-reservation section (`reserveGiftItem`, cart gift rows with `reserved_quantity`, finalize-at-payment) and blanket "reversed on cancel" rule describe superseded behavior. Gifts are now descriptors; cancel-reversal is conditional (Rule 17/ORD-1).
#### Evidence
`PromotionService.php:99-113,229-232`; `OrderCreationService.php:271-276`; `OrderService.php:1206-1214`; manual §§Application/Gift Items/Rollback.
#### Why it matters
Gift-stock debugging via the manual leads to a reservation path that does not exist; cancel-reversal expectations are wrong for paid orders.
#### Current behavior
Correct new behavior; stale manual.
#### Recommended future action
Rewrite gift + cancel sections (on explicit docs request).
> NOTE: This audit is READ-ONLY. No fix was applied.

### Finding F-02
- Severity: INFO
- Type: DOCUMENTATION
- Status: PROVEN
#### Finding
Manual P4-C1 (conditional `promotion_consumed`) and P4-C3 (decrement floor) are retired: the column is non-nullable default false since July 2026, and `decrementUsage` floors at zero.
#### Evidence
Migration `2026_07_27_081603:14-15`; `PromotionService.php:211-223`.
#### Why it matters
Record as fixed; prevents duplicate hardening work.
#### Current behavior
Correct.
#### Recommended future action
None (record only).
> NOTE: This audit is READ-ONLY. No fix was applied.

### Finding F-03
- Severity: LOW
- Type: BUSINESS LOGIC
- Status: PROVEN
#### Finding
Discount stacking order (promotion → coupon on remainder; flash-sale prices embedded in line prices before both) is emergent from `calculateCheckoutTotals` call order, not a stated, tested stacking policy. No precedence configuration, no stacking-precedence test, no documented interaction with gift promotions.
#### Evidence
`OrderService.php:575-608` (promotion first, coupon second); no stacking-policy code or test found by search.
#### Why it matters
Future discount types or precedence changes have no contract to preserve; finance cannot point to a rule.
#### Current behavior
Deterministic and consistent; unstated.
#### Recommended future action
Document the precedence chain + add a pinning test (promotion × coupon × flash sale).
> NOTE: This audit is READ-ONLY. No fix was applied.

### Finding F-04
- Severity: LOW
- Type: CONCURRENCY
- Status: STRONGLY REASONED
#### Finding
Gift availability is checked at apply (resolver snapshot) and enforced at order reservation, but between the two another order may take the last unit: the second checkout then fails atomically at `reserveForOrder` (correct, no oversell) with a generic stock error rather than a gift-specific message.
#### Evidence
`PromotionService.php:99-104` (no reservation at apply, by design); `OrderReservationService.php:48-88` (atomic enforcement); manual P4-C4 (snapshot concern, still structurally true).
#### Why it matters
Correctness holds (no oversell, no silent drop — checkout aborts); UX degrades to a stock error on a gift the customer was promised seconds earlier.
#### Current behavior
Safe failure; imprecise error.
#### Recommended future action
Re-check gift availability inside the checkout lock pre-creation and raise the gift-specific 422 already defined for the apply path.
> NOTE: This audit is READ-ONLY. No fix was applied.

### Finding F-05
- Severity: LOW
- Type: TEST GAP
- Status: PROVEN (gap)
#### Finding
Manual R4-3 (decrement-floor regression test), R4-4 (afterCommit discussion), and R4-5 (`PromotionConsumed` event) have no confirmed dedicated tests or implementation. The floor itself is verified in code; the regression pin is missing.
#### Evidence
Test listing (`PromotionCheckoutTest`, `PromotionFlowTest`, `PromotionCrudTest`, `PromotionProductionHardenTest`, notification tests) — no floor/afterCommit/event-specific test identified; no `PromotionConsumed` event class exists.
#### Why it matters
The floor guard's only protection is code review; a future refactor could regress it silently.
#### Current behavior
Correct code; missing pin.
#### Recommended future action
Add the one-line floor test (usage 0 → decrement → 0).
> NOTE: This audit is READ-ONLY. No fix was applied.

### Finding F-06
- Severity: INFO
- Type: BUSINESS LOGIC
- Status: STRONGLY REASONED
#### Finding
If the global limiter fills between apply and completion, `incrementUsage` silently no-ops (guard matches no row) while `promotion_consumed` still sets — the order keeps a discount that was never counted. This is the correct availability trade-off (eligibility held at apply under lock), but it is an uncounted-discount path worth knowing.
#### Evidence
`PromotionService.php:185-200` (guarded increment, no else-branch); `OrderService.php:398-412` (flag set unconditionally when column exists).
#### Why it matters
Limiter accounting can drift below actual granted discounts under contention.
#### Current behavior
Customer-favoring; bounded by the apply-time lock window.
#### Recommended future action
Log when the increment no-ops on a completion (observability), or re-check validity at completion.
> NOTE: This audit is READ-ONLY. No fix was applied.

## 23. Potential Failure Scenarios

- **Promotion disabled mid-checkout**: locked re-fetch misses → 422 before any write.
- **Limiter fills mid-payment**: F-06 path (discount kept, uncounted).
- **Gift stock gone at reservation**: whole checkout transaction aborts atomically; cart untouched; retry without gift succeeds.
- **Double completion**: `promotion_consumed` once-flag + idempotent increment shape.
- **Paid cancel**: usage retained (Rule 17); inventory restored; coupon retained (POLICY 5) — consistent "delivered benefit stays counted" posture.
- **Applicator exception mid-split**: checkout transaction rolls back; cart lines unmodified (writes happen in-transaction).

## 24. Documentation Drift

Accurate: strategy/eligibility/allocation/validity/increment mechanics, table shapes, `promotion_consumed` purpose. Drifted: gift reservation model (entire §Gift + `reserveGiftItem` refs), cancel-reversal blanket rule (now conditional), consumption call sites (funnelled via `changeOrderStatus`), `promotion_consumed` conditional framing (now non-nullable), missing subsystems (Rule 17/ORD-1, loud-gift-failure, legacy-gift purge, financial-invariant subtotal, ending-soon notifier, observer audit).

## 25. Dependencies

- **Depends on**: Phase 01 (checkout/completion/cancel call sites), Phase 02 (cart lines + preview marks), product/stock models, wishlist subsystem (ending-soon fan-out).
- **Consumed by**: Phase 01 (totals/finalize/decrement), Phase 07 (discount snapshot on invoice), Phase 12 (promotion notifications), Phase 15 (timeline).
- **Shared tables**: `promotions`, `promotion_product`, `promotion_gift_products`, `cart_items` (preview marks), `orders` (promo snapshot + flag).
- **Shared services**: `PromotionService`, `PromotionApplicator`, `PromotionEligibilityResolver`, strategies.

## 26. Out of Scope

Flash-sale pricing engine, coupon precedence configuration (none exists), admin promotion CRUD/approval, promotion analytics/reporting, gift-vendor settlement.

## 27. Residual Risks

1. Stacking precedence unstated/unpinned (F-03).
2. Gift availability two-moment check (F-04).
3. Runtime concurrency unproven (standard limitation).
4. Limiter-fill accounting drift (F-06).
5. Manual gift/cancel sections misleading (F-01).

## 28. Evidence / Source Files

Manual: `HEAD:docs/production-manual/PHASE-04-PROMOTION-LIFECYCLE.md` (327 lines, temp extract). Code: `app/Services/General/PromotionService.php` (full, 271 lines); `PromotionEngine/{PromotionApplicator,PromotionEligibilityResolver,PromotionEvaluation,PromotionResult,Contracts/PromotionStrategy,DTOs/GiftItem,Outcome/{PromotionOutcome,DiscountOutcome,GiftOutcome},Strategies/{Abstract,Percentage,Fixed,Gift}PromotionStrategy}.php`; Marvel `Promotion.php` (`scopeValid`, `isValid`, `discountAmount`, gift relations); `OrderService.php:398-412,445-467,575-608,1206-1214` (finalize/shipping/totals/cancel); `OrderCreationService.php:271-276` (gift snapshot exclusion); `OrderReservationService` (gift-inclusive reservation); `NotifyPromotionsEndingSoon.php`; `PromotionObserver.php`; `PromotionActivated` event + user-notification listeners; `CartCreateRequest` (selection inputs). Tests (listed, not executed): `PromotionCheckoutTest`, `PromotionFlowTest`, `PromotionCrudTest`, `PromotionProductionHardenTest`, `UserPromotionFlashSaleNotificationTest`, `PromotionFlashSaleNotificationE2ETest`. Migrations: promotion tables (legacy), `2026_08_13_000002` (ending-soon stamp), `2026_07_27_081603:14-15` (consumed flags).

## 29. Final Assessment

**Verdict: PASS WITH RESIDUAL RISKS.**

The promotion lifecycle is sound, conservative with money (locked increments, floored decrements, fail-closed selection, loud gift failures), and its manual survives better than most — but the gift re-architecture and conditional cancel-reversal postdate it, stacking policy is implicit, and runtime proof is absent. No blocking defect found.

## 30. Hardening Addendum (F-01 / F-03 / F-04 / F-05 / F-06)

Minimal hardening pass — no redesign, no behavior change except observability.
Business contract preserved verbatim: read-only eligibility, in-transaction
revalidation, largest-remainder allocation, post-payment usage,
`promotion_consumed` idempotency, Rule 17 / ORD-1 cancel semantics,
descriptor gifts on the shared pool, atomic reservation, floored decrement,
fail-closed selection, no oversell.

### Fixed

- **F-01 (manual drift):** `docs/production-manual/PHASE-04-PROMOTION-LIFECYCLE.md`
  restored and rewritten — descriptor gift architecture, Rule 17 / ORD-1
  cancel table, NOT NULL `promotion_consumed`, plus new sections (stacking
  precedence, completion funnel, ending-soon notifier, observer, loud gift
  failure, legacy purge, financial invariant, two-stage gifts, F-06 log).
  P4-C1 retired, P4-C3 pinned, R4-1/R4-3 done, R4-2/R4-5 declined with
  reasons, R4-4 evaluated and not adopted.
- **F-03 (implicit precedence):** approved `Flash Sale → Promotion → Coupon`
  chain documented as an explicit contract on
  `OrderService::calculateCheckoutTotals` and in the manual; financially
  pinned by `PromotionResidualHardeningTest`
  (160 → −10 → −15 → 135, plus gift/coupon-base interaction). No engine
  change, no configurable precedence.
- **F-04 (gift two-moment error):** new
  `PromotionService::throwIfGiftUnavailable` (locked gift re-read, gift-422
  iff the gift is genuinely short, silent otherwise — never reserves, never
  mutates); wired into all three checkout reservation sites
  (`OrderService::addItemsInOrder`, `FastShippingService::createFastOrder`
  ×2). Deterministically pinned (gift lost → gift 422, nothing committed).
- **F-05 (test gaps):** new pins for the decrement floor, service-level
  consumption idempotency, and the deterministic gift race. Cancel semantics
  NOT duplicated — already pinned by `ReaperAuthorityTest`
  (unpaid decrement + never-paid expiry skip) and
  `BusinessRulesImplementationTest::test_promotion_not_decremented_on_paid_order_cancellation`
  (paid keep).

### Addressed / Observable

- **F-06 (limiter-fill drift):** Option A adopted. `incrementUsage` now
  returns whether the counter moved; `finalizePromotionUsageAfterPayment`
  keeps the customer-favoring behavior (discount kept, flag sets) and emits
  `Log::warning('promotion.usage.limiter_blocked', [promotion_id, order_id,
  usage, limiter])` when the guard blocks. Pinned with and without the
  warning (control test). Completion revalidation (Option B) deliberately
  NOT adopted — a completed order never loses its promotion.

### Remaining limitations

1. True multi-process concurrency remains reasoning-verified (locked
   guards + `promotion_consumed` once-flag), not runtime-parallel proven —
   standard environment limitation, unchanged by this pass.
2. F-06 drift itself is inherent to the approved apply-then-count design;
   it is now observable rather than silent.
3. Gift UX on a lost race is still a failed checkout (correct), only now
   with a precise error.

### Scope confirmation

Untouched: `OrderReservationService`, `PaymentCompletionService`, coupon and
flash-sale engines, fulfillment/WMS, schema/migrations, admin CRUD, events,
applicator allocation, eligibility strategies, cancel Rule 17 logic.
