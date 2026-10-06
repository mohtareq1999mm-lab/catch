# Phase 02 — Cart Lifecycle

## 1. Executive Verdict

**PASS.** All four residual code findings (F-03–F-06) were fixed, runtime-proven on MySQL (19 new + 173 regression tests green), and the phase manual was rewritten around the actual selection-container architecture (F-01/F-02 closed as documentation resolutions). The cart subsystem is coherent: reservation-free container, locked mutations, ownership-safe APIs, bounded quantities, DB-backed single-cart invariant with race-safe creation, at-most-once abandonment reminders, and no cart-ID oracle. Remaining limitations are environmental and honestly labelled: true multi-process parallelism was not executed (deterministic proxies proven instead), and `onOneServer` needs a centralized cache driver to constrain multi-server schedulers (the per-cart atomic claim holds regardless).

*Post-fix update 2026-10-05. Original verdict was PASS WITH RESIDUAL RISKS; every residual item from that verdict is now RESOLVED or reclassified below with executed evidence.*

## 2. Phase Objective

Per `PHASE-02-CART-LIFECYCLE.md` (source: `HEAD:docs/production-manual/PHASE-02-CART-LIFECYCLE.md`): trace the cart from creation through item adds (with inventory reservation), coupon/promotion application, checkout, finalization, and expiration — and verify each stage's correctness.

## 3. Scope

**In scope:** cart CRUD APIs, item add/update/remove, pricing snapshots, coupon attachment to cart, promotion revalidation on edit, cart reuse after checkout, abandoned-cart reminder, throttle/ownership, cart↔checkout handoff (slice semantics).

**Out of scope:** checkout internals (Phase 01), coupon validation rules (Phase 03), promotion engine (Phase 04), inventory reservation mechanics (covered as consumer-only here).

## 4. What Was Supposed to Be Implemented

The manual claims: cart created on first add; `reserveItem()` locks cart + inventory row and increments `reserved_quantity` with 3-day TTL; coupon applied without reservation; promotion pre-applied to lines; `ensureCartReservation` re-syncs at checkout; `finalizeItemsByShippingMethod`/`deductStockForOrder` finalize on payment (cart → `checked_out`, items deleted); `expireCarts()` (unregistered) expires stale carts (→ `expired`, stock released); problems: no cleanup command (MEDIUM), no concurrent-add locking (MEDIUM), stale prices (LOW).

## 5. What Actually Exists

An inverted architecture, documented in code as intentional:

- `CartInventoryService` docblock (`app/Services/General/CartInventoryService.php:16-27`): "The cart is ONLY the user's current shopping selection… It NEVER touches inventory counters… inventory reservation belongs to the Order via `OrderReservationService`."
- `incrementItem` explicitly skips stock checks by design (`:33-37`); `ensureCartReservation`, `reserveItem`, `syncCartItemReservation`, `finalizeItemsByShippingMethod`, `deductStockForOrder`, `expireCarts` **do not exist anywhere** (verified by repository-wide search).
- No terminal statuses: `clearCheckedOutSlice` returns the cart to `active` in all cases (`:143-160`); the cart row survives checkout as a reusable container (pinned by `CheckoutPendingOrderRedesignTest::test_cart_is_reusable_with_same_row_after_checkout`). No code writes `checked_out`/`expired` cart statuses (verified by search — all matches are coupon-claim related).
- 3-day TTL survives only as an abandoned-cart **activity window** (`touchActivity`, `:184-193`; `CART_ACTIVITY_TTL_DAYS = 3`, `:31`).
- Cart APIs live entirely in Marvel: `CartController` (`packages/marvel/src/Http/Controllers/CartController.php`) + `CartRepository::persistCart/syncItems` + routes under `auth:sanctum` + `throttle:cart` (`packages/marvel/src/Rest/Routes.php:383-390`); `RateLimiter::for('cart')` exists (`app/Providers/RouteServiceProvider.php:111`) — the historical 429 bug stays fixed.
- Abandoned-cart reminder exists and is scheduled hourly with single-server protection and an atomic per-cart claim (`NotifyAbandonedCarts`, `Kernel.php:39` + F-05 fix).
- Per-line maximum quantity enforced at every mutation boundary (`config/cart.php`, F-03 fix).
- Single-cart invariant is DB-backed (`UNIQUE(carts.user_id)` in the Marvel `carts` migration) with duplicate-race-safe creation (F-04 fix).
- `GET /cart/{id}` scopes to the caller's own carts before resolving — foreign and nonexistent IDs both 404 (F-06 fix).

## 6. Architecture

```
Marvel routes (auth:sanctum + throttle:cart + lang)
  GET cart → CartController::index (own carts, paginated)
  GET cart/{id} → show (scoped to own carts FIRST → 200 owner / 404 otherwise; F-06)
  POST cart → store → CartRepository::storeCart → persistCart('add')
  PUT cart/update-item → update → persistCart('set')
  DELETE cart/delete-item/{id} → deleteItemFromCart (own cart only)
  DELETE cart/delete-items → destroy (coupon warning unless confirm)
  POST cart/bulk-items → pluckItemsToCart
       └─ persistCart: cart lockForUpdate (create if missing; duplicate-key
            race resolves to winner's row — F-04) → syncItems
             ├─ product must be active(); FAST eligibility; variable needs variant
             ├─ quantity 1..max_item_quantity at every boundary (F-03)
             ├─ NO stock check (by design)
             └─ CartInventoryService::incrementItem/decrementItem
                  (cart lock → item lock → upsertItem: min/max guard, price
                   snapshot, promo reset, touch activity)
       └─ controller → revalidatePromotion (strip stale promo marks)

Checkout handoff (Phase 01): addItemsInOrder / createFastOrder read the
SCHEDULED/FAST slice → order owns reservation → clearCheckedOutSlice deletes
the ordered slice (+ legacy gifts), resets or re-totals the surviving cart.

Scheduler: cart:notify-abandoned hourly (24h inactivity + reminder_sent_at null),
withoutOverlapping + onOneServer + atomic per-cart claim (F-05).
NO expiry reaper exists or is needed (nothing to release).
```

## 7. Complete Execution Flow

1. **Add** (`POST cart`, `CartCreateRequest`: item.product_id exists, `1 ≤ quantity ≤ cart.max_item_quantity` (F-03), shipping_method required allowlist): `persistCart` locks/creates the user's cart (`CartRepository.php:79-124`; duplicate-key race resolves to the winner's row — F-04), `syncItems` validates product/variant/FAST-eligibility, `incrementItem` merges duplicates by product+variant+method under cart+item locks, `upsertItem` enforces min/max on the resulting quantity and snapshots current price (`CartInventoryService.php:199-240`), totals re-summed.
2. **Set/dec** (`PUT cart/update-item`): same funnel with `mode='set'` (keeps existing shipping_method when omitted); max mirrored in `CartUpdateRequest`.
3. **Remove one** (`DELETE cart/delete-item/{id}`): scoped to own cart, `releaseItem($item, true)` deletes under lock, `tidyCartAfterRemoval` clears coupon when the last line is gone (`CartInventoryService.php:252-259`), promotion revalidated, totals re-summed.
4. **Clear** (`DELETE cart/delete-items`): coupon present without `confirm` → warning response (no-op); else `releaseCart` deletes lines, resets to active, clears coupon/totals.
5. **View** (`GET cart`, `GET cart/{id}`): `show` scopes the query to the caller's `user_id` BEFORE `findOrFail` — owner → 200; foreign → 404; nonexistent → 404 (F-06; no oracle).
6. **Checkout**: slice read → order reservation → slice deleted; cart row reused.
7. **Abandoned**: 24h-inactive active carts with live activity window and no reminder → atomic claim (single UPDATE flipping `reminder_sent_at` NULL → timestamp; exactly one worker wins) → eligible customer notified once (F-05).

## 8. Business Rules

| # | Rule | Implementation | Bypass? |
|---|---|---|---|
| R1 | One cart per user; row survives everything and is reused | `UNIQUE(carts.user_id)` + `persistCart` find-or-create with duplicate-key race resolve; `clearCheckedOutSlice` never deletes the row | No — PROVEN (duplicate insert → 1062; repeated creation → same row) |
| R2 | Cart holds NO inventory; stock checked only at checkout | Class contract + `CartExpirationTest` new-contract docblock | No |
| R3 | Prices are snapshots at add-time; refreshed only at checkout | `upsertItem` (`:208-226`); `refreshCartItemPrices` at checkout (Phase 01) | No (by design; manual decision 3 preserved) |
| R4 | Coupon cleared when the last line is removed | `tidyCartAfterRemoval` (`:252-259`); `releaseCart`/`clearCheckedOutSlice` clear it | No |
| R5 | Promotion marks are preview-only; any cart edit strips stale marks | `upsertItem` resets `promotion_id/discount_amount` (`:224-225`) + `revalidatePromotion` after store/update/delete | No |
| R6 | Only `active()` products, FAST-eligible products on FAST lines, variants required for variable products | `syncItems` (`CartRepository.php:125+`) | No |
| R7 | Clearing a coupon-bearing cart requires explicit confirm | `destroy` (`CartController.php:118-138`) | `confirm=true` (intended) |
| R8 | Abandoned reminder at most once per cart, customers only | Scheduler `onOneServer` + atomic NULL→timestamp claim + `UserType::USER` filter (`NotifyAbandonedCarts.php`) | No — PROVEN (repeat runs → 1 notification) |
| R9 | Slice isolation: SCHEDULED and FAST lines checkout independently; gifts always leave with any slice | `clearCheckedOutSlice` (`:132-138`) | No |
| R10 | Per-line quantity bounded: `1 ≤ qty ≤ cart.max_item_quantity` (default 100) | `CartCreateRequest`/`CartUpdateRequest`/bulk inline `max` (422) + `upsertItem` choke point (400) | No — PROVEN (at-max 201; over-max 422/400; cumulative over-max 400) |
| R11 | No cart-ID existence oracle | `show` scopes by caller `user_id` before `findOrFail` (404/404) | No — PROVEN |

## 9. Source of Truth / Authorities

- **Cart contents**: `CartRepository::persistCart` + `CartInventoryService` (sole writers of `cart_items` outside checkout slice-deletion).
- **Inventory**: order-owned; cart code provably never writes `reserved_quantity` (only reader of product rows for pricing).
- **Coupon-on-cart**: written by apply paths (Phase 03), cleared by tidy/release/slice logic here.
- **Totals**: `cart.total_price` is a cached sum, recomputed after every mutation; checkout recomputes authoritatively from lines.
- **Dead authorities**: `expireCarts`, `ensureCartReservation`, `finalizeItemsByShippingMethod` — removed, no callers, no schedule entries.

## 10. Database Impact

`carts` (user container; `coupon`, cached `total_price`, `status` effectively always `active`, `reserved_at`/`expires_at` activity window, `reminder_sent_at` via migration `2026_08_13_000001`); `cart_items` (product/variant, quantity, price snapshot, `reserved_quantity` column retained but cart no longer writes it, promotion preview columns, `shipping_method`, `is_gift`, attributes JSON). Single-cart invariant is DB-enforced: `UNIQUE(user_id)` in the Marvel `carts` migration (`2020_06_02_051901_create_marvel_tables.php:296`; no later migration drops it — verified by search), with application-level duplicate-race resolution in `persistCart` (F-04). The manual's `checked_out`/`expired` terminal states have no writers (only read-side analytics labels + seeder fixtures + the legacy schema-enum vestige, which is harmless).

## 11. API Surface

| Method | URI | Auth | Controller | Validation | Responses |
|---|---|---|---|---|---|
| GET | `/cart` | sanctum + throttle:cart | `CartController::index` | limit | 200 paginated own carts |
| POST | `/cart` | sanctum + throttle:cart | `CartController::store` | `CartCreateRequest` | 201; 400 |
| GET | `/cart/{id}` | sanctum + throttle:cart | `CartController::show` | numeric id | 200 owner; 404 foreign; 404 missing |
| PUT | `/cart/update-item` | sanctum + throttle:cart | `CartController::update` | `CartUpdateRequest` | 200; 400 |
| DELETE | `/cart/delete-item/{itemId}` | sanctum + throttle:cart | `CartController::deleteItemFromCart` | own-cart scope | 200; 400 |
| DELETE | `/cart/delete-items` | sanctum + throttle:cart | `CartController::destroy` | confirm when coupon | 200 (+warning variant); 404 |
| POST | `/cart/bulk-items` | sanctum + throttle:cart | `CartController::pluckItemsToCart` | items array | bulk merge |

## 12. Authentication & Authorization

All cart routes require `auth:sanctum` (Marvel group, `Routes.php:383`); no guest carts. Ownership enforced per action (index scopes by user; show/update/delete/destroy resolve via the authenticated user's own cart or explicit `user_id` comparison). `persistCart` throws 401 without a user. Coupon-bearing clear requires explicit confirm (anti-footgun, not a security boundary).

## 13. Validation

`CartCreateRequest`: item required; `product_id` exists; `1 ≤ quantity ≤ cart.max_item_quantity` (F-03; 422 `max` rule); `product_variant_id` exists; `shipping_method` required allowlist (case-insensitive scheduled/fast). `CartUpdateRequest` mirrors the max. `pluckItemsToCart` enforces the same inline (`items.*.quantity` `min:1|max:`). Service-layer: product must be `active()`; FAST lines require `is_fast_shipping_available`; variable products require a variant; desired quantity <1 rejected (`QUANTITY_MINIMUM`); desired quantity above max rejected via `QUANTITY_MAXIMUM` → 400 through the `persistCart` mapping (`CartInventoryService::maxItemQuantity()` reads `config/cart.php`, default 100, `CART_MAX_ITEM_QUANTITY` override). Invalid product/variant → 400 `INVALID_ITEM_DATA`. `failedValidation` returns 422 JSON.

## 14. Transactions

`persistCart` manual begin/commit with HttpException mapping (401 auth, 400 domain); nested `DB::transaction` closures in `incrementItem/decrementItem/releaseItem/releaseCart/clearCheckedOutSlice/upsertItem` become savepoints inside it — single atomic unit per cart mutation. Lock order is consistent (cart → item). Slice cleanup at checkout runs inside the checkout transaction (Phase 01).

## 15. Concurrency

| Case | Protection | Classification |
|---|---|---|
| Concurrent adds, same line | cart `lockForUpdate` + item `lockForUpdate` merge (`incrementItem:43-48`, `findCartItemForLock:261-280`) | STRONGLY REASONED |
| Oversell via concurrent add | Impossible by construction — no reservation at add; atomic check at checkout (Phase 01) | STRONGLY REASONED (manual's MEDIUM concern retired by design) |
| Concurrent clear vs add | Same cart lock serializes | STRONGLY REASONED |
| Duplicate carts per user | `UNIQUE(carts.user_id)` (Marvel migration) + find-or-create under `lockForUpdate` + duplicate-key race resolve in `persistCart` | PROVEN (deterministic): duplicate insert → MySQL 1062; repeated creation → same row; two users → separate rows. True multi-process race NOT EXECUTED — STRONGLY REASONED (see F-04) |
| Over-maximum quantities | `max` rules at all 3 HTTP boundaries + `upsertItem` choke point | PROVEN (at-max 201; over-max 422/400 incl. cumulative) |
| True parallel proof | — | UNPROVEN — NOT EXECUTED |

## 16. Async / Queues / Events

No cart-scoped events/listeners. Scheduler: `cart:notify-abandoned` hourly (`Kernel.php:47`, `withoutOverlapping` + `onOneServer`) with an atomic per-cart claim (single UPDATE flipping `reminder_sent_at` NULL → timestamp; no transaction held across the notification) — F-05. Cart mutations are fully synchronous (no queue), which is correct for an interactive container with no external side effects.

## 17. Error Handling

`persistCart` maps auth→401, domain→400 with message constants (quantity over-maximum → 400 `QUANTITY_MAXIMUM` with the configured bound interpolated); controller `store/update` catch-all → 400; `deleteItemFromCart`/`destroy` return typed 400/404 constants (`DELETE_CART_ITEM_FAILED`, `CART_NOT_FOUND`, `COUPON_DELETE_CART_WARNING`). Quantity <1 → `QUANTITY_MINIMUM`. Duplicate-cart race → resolved to the winner's row (only non-duplicate DB errors propagate). `show` foreign/missing → 404 via `ModelNotFoundException` → `{'message','status':false}` envelope (Handler). No silent failures: every mutation returns the reloaded cart resource or an error.

## 18. Security

- IDOR: ownership checks on all item/cart-scoped actions — verified. `show` scopes the query by caller `user_id` before `findOrFail`: foreign and nonexistent IDs both 404 with no payload (F-06 RESOLVED, proven).
- `throttle:cart` + limiter defined (historical 429-class bug stays fixed).
- Mass assignment: `Cart` fillable is narrow; `reminder_sent_at` is set via direct assignment in the console command (fillable-exempt by design — no user input reaches it; tests must assign it directly, not via `create()`).
- Coupon-clear confirm prevents accidental coupon loss (UX, not auth).
- No guest cart surface; no admin cart-mutation bypass found.

## 19. Performance

Cart mutations lock one cart row + one item row — minimal contention scope. Eager loads on read paths (`items.product`, variant attributes) prevent N+1 on cart views. `upsertItem` prices each line via `ProductPricingService` (per-line pricing calls on bulk adds — acceptable at cart scale). Abandoned notifier uses `chunkById(500)` with `with('user')` eager load (the former per-candidate N+1 is fixed as part of F-05).

## 20. Tests & Verification

EXECUTED 2026-10-05 on MySQL 8.4.3 (`DB_DATABASE=phase2_verify` / `phase2_verify2`, PHP 8.2, `--no-coverage`):

- `tests/Feature/Cart/CartPhase02FixesTest.php` (NEW, 19 tests / 49 assertions) — **19/19 PASS** (also re-run fully green on the second isolated DB):
  - F-03: at-max 201 · over-max add 422 · over-max update 422 · cumulative over-max 400 with quantity preserved · direct-service over-max throws · bulk over-max 422 · min (0) still 422.
  - F-04: repeated creation → same row · duplicate `Cart::create` → MySQL 1062 · two users → separate carts.
  - F-05: eligible → exactly 1 notification + stamped · repeat runs → still 1 · already-reminded/non-customer/inactive skipped · schedule has `withoutOverlapping` + `onOneServer`.
  - F-06: owner 200 · nonexistent 404 · foreign 404 with `status:false` and no ID leak.
- `tests/Feature/CartApiTest.php` — **79/80 PASS** (3 tests updated to the new contracts). 1 pre-existing environmental error, unrelated to this diff: `add_regular_item_does_not_overwrite_gift_item` writes `promotion_id=999` directly and violates the full-schema FK on warmed DBs (fails at the test's own Eloquent update, before/around any touched code; gift/promotion paths untouched per §8).
- `tests/Feature/CartExpirationTest.php` — 4/4 PASS (new-contract pins hold).
- `tests/Feature/CartOrderLifecycleTest.php` — 38/38 PASS (checkout handoff unchanged).
- `tests/Feature/Digital/DigitalCartCheckoutTest.php` — 9/9 PASS.
- `tests/Feature/CheckoutApiTest.php` — 13/13 PASS.
- `tests/Feature/Coupon/CouponCheckoutRevalidationTest.php` — 15/15 PASS (coupon/cart interaction unchanged).
- `tests/Feature/OrderStatusLifecycleTest.php` — 15/15 PASS.
- `php -l` clean on all touched production files.
- Pre-existing harness limitation (NOT fixed — out of scope): `CreatesTestTables::createAllTestTables` creates `cities` (FK → `governorates`) before `governorates` exists, so `CartApiTest`-style suites cannot bootstrap a virgin DB (proven: single-test run on empty DB fails at trait line 32 with 1824). Suites must run on a warmed DB (or use `RefreshDatabase` with real migrations, as the new test file does).

## 21. Edge Cases

Covered: empty cart checkout → 400 (Phase 01); removing last line clears coupon; clearing coupon-bearing cart warns; FAST-ineligible product rejected; variable product without variant rejected; inactive product rejected at add AND re-asserted at checkout (`assertCartProductsActive`); gift lines excluded from merge matching (`is_gift=false` scopes) and always removed with any checkout slice; partial-slice checkout (FAST remains) re-totals and extends the activity window; abandoned reminder suppressed after send; non-customer (admin/vendor) carts never notified.

## 22. Potential Bugs

### Finding F-01
- Severity: MEDIUM
- Type: DOCUMENTATION
- Status: RESOLVED (2026-10-05)
#### Finding
The manual's foundational premise — cart-owned inventory reservation with `reserveItem`/`ensureCartReservation`/`finalizeStock`/`expireCarts` and terminal `checked_out`/`expired` statuses — described a system that no longer exists. None of those methods exist; no code writes those statuses; the cart never touches `reserved_quantity`.
#### Evidence
`CartInventoryService.php:16-27` (class contract), `:33-37` (no stock check by design); repository-wide search for `reserveItem|ensureCartReservation|syncCartItemReservation|finalizeItemsByShippingMethod|deductStockForOrder|expireCarts` returns only historical references; `clearCheckedOutSlice:143-160` always restores `active`.
#### Why it matters
Operators debugging stock discrepancies via this manual will investigate a reservation layer that does not exist and miss the order-owned authority.
#### Resolution
The production manual was rewritten around the actual architecture (`docs/production-manual/PHASE-02-CART-LIFECYCLE.md`, restored + rewritten 2026-10-05): cart = selection container; no inventory reservation at cart level; stock validated/reserved atomically during checkout; cart survives checkout with slice removal; SCHEDULED/FAST slices independent; the 3-day value is an activity/reminder window, NOT a reservation TTL; retired problems closed as "retired by redesign". The document is internally consistent; no reservation/TTL/expiry language remains except explicitly marked historical notes.

### Finding F-02
- Severity: INFO
- Type: DOCUMENTATION
- Status: RESOLVED (2026-10-05)
#### Finding
Manual §6 problems table is obsolete: "no cart cleanup command" is moot (nothing to clean — `CartExpirationTest` docblock states "There is no reaper"); "no concurrent-add locking" is moot (locks exist AND oversell is structurally impossible).
#### Evidence
`tests/Feature/CartExpirationTest.php:22-28` (new-contract docblock); `CartInventoryService.php:43,261-280` (locks).
#### Why it matters
Stale problem list invites fixing non-problems.
#### Resolution
Rewritten manual §6 "Retired Problems" closes each item as retired-by-redesign/moot/accepted-by-design with references. No code action (none needed).

### Finding F-03
- Severity: LOW
- Type: TEST GAP (validation gap)
- Status: RESOLVED (2026-10-05)
#### Finding
No maximum quantity is enforced anywhere: `CartCreateRequest` requires `quantity ≥ 1` with no upper bound, and `upsertItem` accepts any `desiredQuantity ≥ 1`. A client can add 999,999 units of a product to the cart.
#### Evidence
`CartCreateRequest` rules (`item.quantity: required, integer, min:1`); `CartInventoryService.php:204-206` (only `<1` rejected). Manual §7 recommendation 3 (max quantity validation) was never implemented.
#### Why it matters
Financial impact is nil (checkout atomically fails on insufficient stock), but absurd carts pollute analytics, totals rendering, and abandoned-cart notifications.
#### Resolution
Centralized bound `cart.max_item_quantity` (new `config/cart.php`, default 100, `CART_MAX_ITEM_QUANTITY` override; single reader `CartInventoryService::maxItemQuantity()`), enforced at all four mutation boundaries: `CartCreateRequest` + `CartUpdateRequest` `max` rules (422), `pluckItemsToCart` inline `max` (422), and the `upsertItem` choke point on the resulting quantity (400 `QUANTITY_MAXIMUM`, new constant + en/ar messages with `:max` interpolation). Over-maximum cumulative adds (existing 99 + delta 2) are rejected with the line preserved. Runtime proof: 7 new tests + 2 updated `CartApiTest` tests, all green (see §20).

### Finding F-04
- Severity: LOW
- Type: CONCURRENCY
- Status: RESOLVED (2026-10-05; deterministic parts PROVEN, true multi-process race STRONGLY REASONED / NOT EXECUTED)
#### Finding
The one-cart-per-user invariant is application-enforced (find-or-create under `lockForUpdate`) with no DB unique guard on `(user_id, status)`. Two app instances racing first-cart creation could insert duplicate active carts.
#### Evidence
`CartRepository.php:79-124` (find → create without unique constraint); `carts` migration set has no such unique index (migration inventory verified).
#### Correction during implementation
The migration inventory was wrong: the production Marvel migration (`2020_06_02_051901_create_marvel_tables.php:296`) already declares `$table->unique('user_id')`, and no later migration drops it (verified by search). The DB half of the invariant therefore already existed; what was missing was application handling of the duplicate-key loser. No new migration was needed (adding one would collide with the existing index).
#### Why it matters
Duplicate active carts would split the user's selection across rows; checkout reads one (`first()`), silently ignoring the other — items "vanish" from checkout.
#### Resolution
`persistCart` now catches duplicate-key violations (MySQL 1062 / SQLSTATE 23000 only; all other DB errors propagate) on the create path and re-resolves the winner's row under lock, so the race converges instead of 500/400ing. Runtime proof: duplicate `Cart::create` → MySQL 1062 with exactly one row surviving; repeated `storeCart` → same row; two users → separate rows (all green, see §20). True parallel multi-connection racing was NOT executed — honestly labelled STRONGLY REASONED: the UNIQUE backstop makes silent duplicates impossible, and the catch path is straight-line reviewed code.

### Finding F-05
- Severity: LOW
- Type: ASYNC
- Status: RESOLVED (2026-10-05)
#### Finding
`cart:notify-abandoned` is scheduled `hourly()->withoutOverlapping()` but without `onOneServer` (unlike coupon/payment jobs), and stamps `reminder_sent_at` without a row lock. On multi-server schedulers, overlapping runs could double-notify.
#### Evidence
`app/Console/Kernel.php:39` (no `onOneServer`); `NotifyAbandonedCarts.php:29-56` (no lock, check-then-notify).
#### Why it matters
Duplicate "you left items" pushes erode trust; low frequency (hourly, 24h-cohort) bounds the blast radius.
#### Resolution
Two layers, matching repo conventions: (1) scheduler now `hourly()->withoutOverlapping()->onOneServer()` (same as coupon/payment jobs); (2) the command performs an atomic per-cart claim — a single `UPDATE ... WHERE id AND reminder_sent_at IS NULL`, no transaction held across the notification — so concurrent workers sharing one database can never double-notify (exactly one wins the NULL→timestamp flip; losers skip). Eligibility rule unchanged (24h inactivity + active + live window + unreminded + customer); content unchanged; `with('user')` eager load removes the per-candidate N+1 noted in §19. Trade-off (documented, accepted): claim-first gives at-most-once notification; a dispatch failure after the claim surfaces as a command exception (visible, non-silent) while queue durability covers delivery — strictly better than the old stamp-after-notify, which could double-send. Runtime proof: eligible → exactly 1 notification + stamped; back-to-back runs → still 1; already-reminded/non-customer/inactive skipped; schedule flags asserted (all green, see §20). Infrastructure note: `onOneServer` needs a centralized cache driver to constrain multi-server schedulers (production `CACHE_DRIVER=file` is single-host); the DB claim holds regardless.

### Finding F-06
- Severity: LOW
- Type: SECURITY
- Status: RESOLVED (2026-10-05)
#### Finding
`CartController::show` runs `findOrFail($id)` before the ownership check, so cart-ID existence is oracle-able (404 unknown vs 403 foreign).
#### Evidence
`packages/marvel/src/Http/Controllers/CartController.php:70-80`.
#### Why it matters
Sequential cart IDs + existence oracle enable enumeration of cart-ID space (no data exposed, but reconnaissance).
#### Resolution
`show` now queries `Cart::where('user_id', $request->user()?->id)->with([...])->findOrFail($id)` — ownership scoping happens BEFORE resolution, so foreign and nonexistent IDs both 404 with the standard `{'message','status':false}` envelope and no payload (unused `AuthorizationException` import removed). Response conventions preserved (owner still 200 + `CartResource`). Runtime proof: owner 200 · nonexistent 404 · foreign 404 with no ID in the body; existing `CartApiTest` foreign-cart expectation updated 403→404 (all green, see §20).

## 23. Potential Failure Scenarios

- **Checkout races cart edits**: checkout locks the cart row; concurrent add serializes behind it; post-checkout adds land on the reused row cleanly.
- **Coupon removed while promotion preview stale**: any cart edit strips promo marks; totals recomputed at checkout — no stale-discount checkout.
- **Abandoned reminder for checked-out cart**: slice cleanup nulls the activity window when empty (`:143-150`), suppressing notices; non-empty remainder extends it (`:155-160`) — correct.
- **Cart deleted mid-checkout**: checkout re-reads under lock and fails clean `CartEmptyException` → 400.
- **Bulk add with mixed methods**: per-item shipping_method persisted; slices checkout independently.

## 24. Documentation Drift

RESOLVED 2026-10-05. The manual (`docs/production-manual/PHASE-02-CART-LIFECYCLE.md`, restored + rewritten) now describes the actual architecture: selection container, order-owned reservation, slice checkout with row reuse, activity-window (not TTL) semantics, atomic-claim reminder, quantity bounds, no-oracle lookup, and a "Retired Problems" table closing the old §6 items. Preserved and still accurate (as before): single cart per user (decision 1), prices not live (decision 3), coupon/promotion not reserved (decisions 4–5), coupon-cleared-on-empty, checkout-time refresh. Previously drifted sections (cart-owned reservations, `ensureCartReservation`, finalize-via-`finalizeStock`, terminal statuses, expiry-with-release, decisions 2/6/7, timeline cart stages) were rewritten, not patched.

## 25. Dependencies

- **Depends on**: Phase 03 (coupon attach/validate), Phase 04 (promotion preview/revalidate), product/pricing services, Marvel product/variant models.
- **Consumed by**: Phase 01 (checkout reads slices; slice cleanup), Phase 12 (abandoned-cart notification), Phase 15 (timeline cart stages).
- **Shared tables**: `carts`, `cart_items` (with checkout slice logic).
- **Shared services**: `CartInventoryService`, `CartRepository`, `ProductPricingService`.

## 26. Out of Scope

Wishlist, guest/anonymous carts (do not exist), multi-cart, cart sharing, cart-level discounts beyond coupon/promotion preview, inventory mechanics (order-owned), checkout pricing (Phase 01).

## 27. Residual Risks

1. True multi-process parallelism NOT EXECUTED (environment limitation): F-04's race-convergence path and F-05's cross-worker claim are proven deterministically (UNIQUE backstop + repeat-run idempotency) and strongly reasoned, not parallel-proven.
2. `onOneServer` requires a centralized cache driver to constrain multi-server schedulers; with file/array cache it is single-host only. The per-cart atomic claim (F-05) holds on any shared database regardless.
3. `CreatesTestTables` cannot bootstrap a virgin DB (`cities` FK references `governorates` before it is created) — pre-existing harness bug, out of scope, documented in §20. Cart suites need a warmed DB or `RefreshDatabase`.
4. Minor i18n quirk (pre-existing, out of scope, observed while fixing F-03): the `QUANTITY_MINIMUM` constant is a bare `cart.inventory.*` key with no lang-file group, so `__()` renders the raw key instead of the en/ar text. The new `QUANTITY_MAXIMUM` constant carries the `message.` group and renders correctly; the minimum path was deliberately left byte-identical.
5. `carts.status` enum still lists legacy `expired`/`checked_out` values (Marvel migration vestige — no writers; harmless; no migration warranted).

## 28. Evidence / Source Files

Manual: `docs/production-manual/PHASE-02-CART-LIFECYCLE.md` (restored + rewritten 2026-10-05 around the actual architecture). Code: `app/Services/General/CartInventoryService.php` (`maxItemQuantity`, `upsertItem` min/max guard); `packages/marvel/src/Database/Repositories/CartRepository.php` (`persistCart` duplicate-race resolve, `isDuplicateKeyError`); `packages/marvel/src/Http/Controllers/CartController.php` (`show` ownership-scoped, bulk inline `max`); `packages/marvel/src/Http/Requests/CartCreateRequest.php` + `CartUpdateRequest.php` (`max` rules); `packages/marvel/src/Database/Models/Cart.php`; `packages/marvel/src/Rest/Routes.php:383-390`; `app/Providers/RouteServiceProvider.php:111` (cart limiter); `app/Console/Commands/NotifyAbandonedCarts.php` (atomic claim); `app/Console/Kernel.php:47` (hourly + withoutOverlapping + onOneServer); `config/cart.php` (NEW); `packages/marvel/config/constants.php` (`QUANTITY_MAXIMUM`); `resources/lang/{en,ar}/message.php` (`cart.inventory.quantity_maximum`); `packages/marvel/src/Database/Repositories/CouponRepository.php:143+` (dead-path coupon attach). Tests (EXECUTED 2026-10-05, MySQL 8.4.3): `tests/Feature/Cart/CartPhase02FixesTest.php` (NEW, 19/19 incl. second-DB rerun), `CartApiTest` (79/80 — 1 pre-existing env error), `CartExpirationTest` (4/4), `CartOrderLifecycleTest` (38/38), `Digital/DigitalCartCheckoutTest` (9/9), `CheckoutApiTest` (13/13), `Coupon/CouponCheckoutRevalidationTest` (15/15), `OrderStatusLifecycleTest` (15/15). Migration evidence: `2020_06_02_051901_create_marvel_tables.php:296` (`unique('user_id')`), `2026_08_13_000001_add_reminder_sent_at_to_carts_table.php`.

## 29. Final Assessment

**Verdict: PASS.**

The cart subsystem is coherent in its redesigned form — reservation-free container, locked mutations, ownership-safe APIs, bounded quantities, DB-backed single-cart invariant with race-safe creation, at-most-once reminders, no existence oracle — with every residual finding from the original audit now resolved and runtime-proven (192 tests green across 8 suites, plus `php -l` clean and a legacy-term re-audit confirming no reintroduced cart-owned inventory architecture). The phase manual matches the implementation. It reaches PASS (not merely PASS WITH RESIDUAL RISKS) because no open defect remains; the items in §27 are environmental limitations and accepted vestiges, not defects.

## 30. Final Verification Matrix (post-fix, 2026-10-05)

| Finding | Before | Action | After | Runtime Proof |
|---|---|---|---|---|
| F-03 | Open (no max) | `config/cart.php` + max at 3 HTTP boundaries + `upsertItem` choke point + en/ar messages | RESOLVED | 7 new + 2 updated tests green (at-max 201; over-max 422/400; cumulative; bulk; min unchanged) |
| F-04 | Open (app-only guard) | Discovered pre-existing `UNIQUE(user_id)`; added duplicate-key race resolve in `persistCart` | RESOLVED (deterministic PROVEN; parallel NOT EXECUTED) | Duplicate insert → 1062; repeat creation → same row; 2 users → 2 carts |
| F-05 | Open (no multi-server protection) | `onOneServer` + atomic NULL→timestamp claim + eligibility/content unchanged | RESOLVED | Eligible → 1 notice + stamped; repeat runs → 1; skips proven; flags asserted |
| F-06 | Open (404/403 oracle) | Ownership-scoped lookup before `findOrFail` | RESOLVED | Owner 200; foreign 404; missing 404; no leak |
| F-01 | Documentation drift | Manual rewritten around actual architecture | RESOLVED | N/A (doc) |
| F-02 | Obsolete findings | Closed as retired-by-redesign in manual §6 | RESOLVED | N/A (doc) |

```text
Production files changed: 8 (config/cart.php NEW; CartInventoryService.php;
  CartRepository.php; CartController.php; CartCreateRequest.php;
  CartUpdateRequest.php; NotifyAbandonedCarts.php; Kernel.php;
  constants.php; resources/lang/en+ar/message.php)
Tests changed: 2 (CartPhase02FixesTest.php NEW 19 tests; CartApiTest.php 3 tests
  updated to new contracts)
Migrations changed: 0 (UNIQUE(user_id) already exists; no schema change needed)
Documentation changed: 2 (production manual restored+rewritten; this audit updated)
Tests executed: 193 (19+80+4+38+9+13+15+15 across 8 suites, MySQL 8.4.3)
Tests passed: 192
Tests failed: 0 (1 pre-existing environmental error in gift/promotion fixture,
  unrelated to this diff — see §20)
Tests blocked: 0 (true multi-process parallelism NOT EXECUTED by design;
  deterministic proxies proven instead)
```
