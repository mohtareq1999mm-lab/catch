# COUPON PRODUCTION READINESS AUDIT

> Independent audit of the CURRENT working tree. The prior remediation report
> (`COUPON_REMEDIATION_FINAL_REPORT.md`) was treated as evidence, not truth —
> every material claim was re-traced in code. READ-ONLY audit: no source, migration,
> config, route, test, or documentation file was modified; nothing was "fixed".
> One new file was created: this report (explicitly required).

# Executive Summary

- The core Coupon Engine remediation is **substantially sound**: fail-closed completion,
  canonical code handling, idempotent payment callbacks, reservation lifecycle, and the
  read-only reconciler were all verified against current code. CP-01/02/04/05/07/08/09/10/11
  fixes hold; CP-03/CP-12 design rationale holds.
- **Verdict: NOT READY** — one P1 is open (F-01: fail-closed exception escapes on the
  second payment-completion path), and final regression is currently BLOCKED by an
  unrelated working-tree breakage (F-15). After F-01 is fixed, F-15 is repaired, and the
  affected suites re-run green, the supportable state is
  **PRODUCTION READY WITH DOCUMENTED OPERATIONAL RISKS** (residual P2/P3 list below).
- A serious GraphQL exposure (F-02) was found but is **currently neutralized** by a broken
  schema build; it becomes a live P0/P1 the moment anyone "fixes" the schema without
  adding field-level auth. This is the highest-risk latent item in the report.
- The working tree is **dirty and being concurrently modified** by another party
  (shipment/tracking feature). All time-sensitive evidence is timestamped; code citations
  are to the tree as read during this audit.

# Scope

Coupon + Claim + Reservation + Usage + Order + Payment + Promotion + Product Pricing +
Queue + Database + Legacy Runtime. In scope: all coupon entry points (storefront REST,
admin REST, GraphQL, callbacks, jobs, commands). Out of scope: fixing anything;
promotion/inventory internals beyond their coupon touchpoints; frontend code.

# Baseline

- Git HEAD: `47ff3d8` ("feat: implement idempotency key for transactions").
- Working tree: **NOT clean**. Remediation changes are uncommitted (24 modified files +
  new files: `CouponCode`, `CouponConsumptionException`, `ReconcileCouponState`,
  `phpunit.mysql.xml`, remediation/concurrency tests, frozen policy docs).
- **Concurrent modification during this audit**: `routes/api.php` (duplicate
  `ShipmentController` import → **fatal on every boot**), `Order.php` (+shipment
  constants/fillable/casts, no coupon columns), new shipment/tracking controllers,
  events, listeners, migrations, lang files. First `git status` in-session did not show
  `routes/api.php`; it appeared mid-audit (file mtime 2026-09-21 15:22 local).
- Test config: sqlite `:memory:` default; `phpunit.mysql.xml` (tracked file, modified for
  harness) targets MySQL 8.4.3 on `127.0.0.1:3307`.
- Entry points (verified via `route:list`, 428 routes, before F-15 broke boots):
  `POST general/coupons/apply`, `POST general/coupons/{id}/claim`,
  `GET general/coupons`, checkout callback + error-callback, COD/cashier completion,
  admin `CouponConfigurationController` (3 routes), assignment CRUD (permission-guarded),
  GraphQL `/graphql` (live route, schema currently fails to build — F-02),
  `coupons:reconcile`, `coupons:expire-reservations/claims`, `orders:cancel-unpaid`.

# Current Architecture

```text
apply/claim/checkout → CouponService/CouponController/FastShippingService/OrderService
    ↓  CouponOrchestrator::validate(ByCode)      (claim gate + assignment branch + POLICY-1)
    ↓  CouponValidator::validate                 (status/dates/limiter/prior-use/product gate)
    ↓  CouponClaimService::claim                 (targeting lock + ACTIVE check + max_claims + eligibility)
    ↓  CouponReservationService::reserve          (coupon lock + idempotent per order + capacity)
    ↓  Order (pending, coupon snapshot) → payment initiation reserves again (idempotent)
    ↓  callback → tx lock + idempotency_key + amount/currency check → commit inventory,
        finalize promotion, changeOrderStatus(completed) → recordCouponUsage (fail-closed)
    ↓  PaymentSucceeded (ShouldDispatchAfterCommit) → MarkCouponClaimRedeemed (queued, idempotent)
    ↓  cancel paths → reservation release (sync, in-tx) ; quota never restored (POLICY 5)
    ↓  coupons:reconcile (read-only detectors, exit 1 on findings; NOT scheduled)
```

Canonical pipeline verified in code: Promotion → Coupon → Tax → Shipping → Snapshot
(`calculateCheckoutTotals` + `withTaxes`; coupon allocated per line pre-tax, shipping
never taxable). Coupon operates on post-promotion net. Single coupon per order.

# Coupon ↔ Order Audit

- Identity: order carries `coupon` (code string snapshot, never rewritten) + `coupon_consumed`
  flag + `coupon_discount`. Resolution at completion is by canonical code
  (`Coupon::byCode()->lockForUpdate()`), then assignment/user/usage rows. Correct.
- `Order::orderItems()` relation exists (used by M7 gate). `Coupon::orders()` exact-match
  relation misses legacy lowercase snapshots (known, previously declared NOT VERIFIED;
  no runtime caller found depending on it for correctness).
- Locks: order row locked first in `changeOrderStatus`, then coupon/assignment rows in
  `recordCouponUsage`. Same-order completions serialize on the order lock; different-order
  same-assignment completions serialize on the assignment lock with the quota check under
  lock. No lock-order cycle found (claim path locks Targeting only; reserve locks Coupon
  then reservations; nothing acquires Order after Coupon). The INV-14 doc lock order
  (Order last) is **inaccurate** — F-13.
- `coupon_usages` UNIQUE(coupon_id,user_id) and `coupon_assignment_usages`
  UNIQUE(assignment,order) verified in migrations — INV-02 DB backing is real for usages
  (but NOT for claims — F-05).
- Repeat checkout: `coupon_consumed` fast path + unique-keyed rows → no double consume.
  Repeat callback: `idempotency_key` (primary) + order-status (secondary) → verified.
- Cancel/retry: every cancel path releases the reservation synchronously in-tx; quota never
  restored; retry re-reserves. Consistent with POLICY 4/5.

# Coupon ↔ Payment Audit

- Consumption happens exactly once, inside the completion transaction, AFTER inventory
  commit + promotion finalize (all atomic; any failure rolls everything back). Verified in
  both callback paths' code.
- Case A (fail): tx→failed, order stays pending, reservation NOT consumed (row still live
  until TTL/cancel), no usage. Correct.
- Case B (success): usage + counters + reservation consume + claim redeem (queued,
  idempotent) exactly once. Correct.
- Case C (success + coupon failure): **checkoutCallback handled (M1)** — tx→failed,
  PaymentFailed emitted, reconciler detector covers it. **checkoutErrorCallback NOT
  handled (F-01)** — 500 escapes, no marker, detector blind.
- Case D (duplicate callback): idempotency_key early return; verified.
- Case E (gateway retry/timeout): token set before business logic; concurrent duplicate
  blocks on row lock then sees token. After a coupon-blocked rollback the token is
  released by design (retry reprocesses and fails visibly). Traced, not live-fired:
  PARTIALLY VERIFIED.
- COD/cashier: `markCodAsPaid`/`markCashierPaid` funnel into canonical
  `changeOrderStatus` (single path, verified). Pre-existing `mark_cod` test failures are
  unrelated (missing tables in hand-rolled schemas).

# Coupon ↔ Promotion Audit

- Ordering enforced in `calculateCheckoutTotals` (promotion first, coupon on net).
  FAST and SCHEDULED both go through it (FAST revalidates coupon under cart lock first).
- Coupon cannot rewrite promotion snapshots; promotion finalize is idempotent and rolls
  back with coupon failure (same tx).
- `CouponCalculator` is the single discount authority for cart/checkout/verify paths
  (CouponService, CheckoutRepository legacy verify, ProductPricingService all delegate).
- Legacy `CheckoutRepository::verify()` computes coupon discount with NO validation, but is
  reachable only via GQL `verifyCheckout` (no REST route) whose return type exposes no
  coupon fields — quote-only divergence, currently unreachable (broken schema). F-12 (P3).
- Product gate is linkage-only (coupon↔product intersect), all-or-nothing per POLICY 7.
  Product active-status is enforced at checkout (`assertCartProductsActive` on both
  shipping paths) but NOT re-checked at completion (F-10).

# Claim/Reservation State Machine

Actual states in code (no `reserved/failed/created/released/cancelled` claim states exist —
the template's state names do not apply; verified against model, enum, services):

```text
Claim (coupon_claims.status enum ACTIVE/EXPIRED/REDEEMED):
  ACTIVE ──→ REDEEMED  (markRedeemed under lock; terminal)
  ACTIVE ──→ EXPIRED   (sweeper; terminal; releases capacity; allows re-claim)
  ACTIVE ──→ ACTIVE    (re-claim blocked while unexpired ACTIVE exists)
  REDEEMED ──→ (re-claim allowed → new ACTIVE row; counts 2 slots — F-16)
  EXPIRED  ──→ (re-claim allowed → new ACTIVE row)
  No transition out of REDEEMED/EXPIRED. Duplicate ACTIVE prevented by app lock only.

Reservation (coupon_reservations row existence = RESERVED):
  RESERVED ──→ CONSUMED (delete at completion; terminal)
  RESERVED ──→ RELEASED (delete on cancel/failure; terminal; idempotent by delete-by-order)
  RESERVED ──→ EXPIRED  (sweeper deletes; completion reacquires per POLICY 4)
  UNIQUE(order_id). Missing/expired at completion → revalidate + reacquire or fail-closed.

Usage: coupon_usages row (unique coupon+user, lifetime) / coupon_assignment_usages row
  (unique assignment+order). No status column; existence IS the state. Counters
  (coupons.used, assignments.used) are increment-only caches.
```

- No impossible transition found: every writer is state-checked or unique-keyed.
- Stale/orphan coverage: sweepers + reconciler detectors exist for all classes.
- Redeemed-then-released and consumed-then-consumed are structurally impossible
  (no code path).

# Concurrency Audit

- Scenario 1 (10 × max_claims=1): prior MySQL proof (PROOF_OK, 1×201/9×409) accepted as
  reported evidence; serialization mechanism re-verified in code (Targeting FOR UPDATE +
  ACTIVE check + count under same lock + bounded retry). True-DB-constraint absent by
  design (re-claims are valid) — detector `duplicate_active_claims` covers escapes.
- Scenario 2 (same redemption concurrently): serialized on order lock; second is
  idempotent no-op via `coupon_consumed`/usage-row checks. Verified in code.
- Scenario 3 (concurrent callbacks): token + row locks; verified in code.
- Scenario 4 (cancel vs redeem): cancel releases reservation only (never touches ACTIVE
  claim→REDEEMED); redeem only touches claim. Completion holds order lock; cancel holds
  order lock. Serialized. Verified in code.
- Scenario 5 (release vs redeem): release deletes by order_id; consume deletes by
  order_id; both idempotent; completion consumes after usage commit in same tx.
- Scenario 6/7 (queue retry / two workers): listener re-locks claim row and re-checks;
  lost race = no-op; transient = rethrow (worker tries=5). Verified in code.
- Deadlock retries (3) on claim/reserve are safe (full rollback + state-checked writes).
- Residual: firstOrCreate unique-race raises QueryException, not the domain exception
  (F-08, safe direction).

# Production Data Audit

No production access available. NOT VERIFIED. Exact read-only queries to run pre/post-deploy:

```sql
-- config anomalies
SELECT * FROM coupons WHERE discount < 0 OR (discount_type='percentage' AND discount > 100);
SELECT * FROM coupons WHERE discount_type NOT IN ('percentage','fixed_rate','free_shipping');
SELECT * FROM coupons WHERE end_date < start_date OR limiter < 0 OR max_discount_amount < 0 OR max_uses < 0;
SELECT * FROM coupons WHERE status = 1 AND end_date < CURDATE();
SELECT code, COUNT(*) FROM coupons GROUP BY code HAVING COUNT(*) > 1;
SELECT * FROM coupons WHERE code <> UPPER(TRIM(code));
-- claim anomalies
SELECT coupon_id, user_id, COUNT(*) FROM coupon_claims WHERE status='active' AND (expires_at IS NULL OR expires_at > NOW()) GROUP BY 1,2 HAVING COUNT(*)>1;
SELECT c.* FROM coupon_claims c LEFT JOIN coupons k ON k.id=c.coupon_id LEFT JOIN users u ON u.id=c.user_id WHERE k.id IS NULL OR u.id IS NULL;
SELECT c.* FROM coupon_claims c LEFT JOIN coupon_usages u ON u.coupon_id=c.coupon_id AND u.user_id=c.user_id WHERE c.status='redeemed' AND u.id IS NULL LIMIT 100;
-- usage anomalies
SELECT * FROM coupon_usages WHERE order_id IS NULL;
SELECT au.* FROM coupon_assignment_usages au LEFT JOIN orders o ON o.id=au.order_id WHERE o.id IS NULL;
SELECT o.id FROM orders o JOIN coupon_usages u ON u.order_id=o.id WHERE o.status='completed' AND (o.coupon IS NULL);
-- order anomalies
SELECT o.id FROM orders o LEFT JOIN coupon_usages u ON u.order_id=o.id LEFT JOIN coupon_assignment_usages a ON a.order_id=o.id WHERE o.status='completed' AND o.coupon IS NOT NULL AND u.id IS NULL AND a.id IS NULL;
SELECT o.id FROM orders o JOIN transactions t ON t.order_id=o.id AND t.status='failed' WHERE o.status='pending' AND o.coupon IS NOT NULL;
SELECT r.* FROM coupon_reservations r JOIN orders o ON o.id=r.order_id WHERE o.status <> 'pending';
SELECT r.* FROM coupon_reservations r LEFT JOIN orders o ON o.id=r.order_id WHERE o.id IS NULL OR r.expires_at < NOW() - INTERVAL 2 HOUR;
-- counter drift
SELECT c.id, c.used, (SELECT COUNT(*) FROM coupon_usages u WHERE u.coupon_id=c.id) + (SELECT COUNT(*) FROM coupon_assignment_usages au JOIN coupon_assignments a ON a.id=au.coupon_assignment_id WHERE a.coupon_id=c.id) AS actual FROM coupons c;
```

# Reconciliation Audit

`coupons:reconcile` — 9 detectors, all read-only (SELECTs only, verified), exit 1 on
findings (verified), safe to repeat, schedulable — **but NOT scheduled** (Kernel has no
entry; F-06). Per-detector: completedWithoutUsage (INV-03; false positives = pre-fail-closed
history, documented; tiny false-negative nuance if a wrong-coupon usage shares the
order); blockedCompletion (M1; intentionally broad — includes genuine declines,
triaged via error_message); redeemedWithoutUsage (lifetime-level, documented S5);
usageWithoutOrder; orphanReservations; couponCounterMismatch (N+1 query pattern —
fine at current scale, P3 note); assignmentCounterMismatch; duplicateActiveClaims;
orphanClaims. Output prints up to 20 rows per detector — actionable. No repair mode
(by design); runbook for blocked completions is sound (retry after re-grant, else
refund; quota stays consumed per POLICY 5).

# Queue/Retry Audit

- Previously unverified "queued non-sync dispatch timing" is now VERIFIED:
  `PaymentSucceeded implements ShouldDispatchAfterCommit` (code), so in-tx dispatches
  defer to commit; `AssignedCouponConsumed` uses explicit `DB::afterCommit`.
- Workers (deploy/supervisor): database driver, high queue `--tries=5 --timeout=1300`,
  `retry_after=1800` in config — contract timeout < retry_after holds (no duplicate
  release). Scheduler via cron in prod image.
- `MarkCouponClaimRedeemed` (high queue): no `$tries`/`$backoff` → worker default 5
  attempts; idempotent body makes at-least-once safe. Retry-exhaustion lands in
  `failed_jobs`; claim stays ACTIVE and the reconciler flags it (documented, sound).
- `OrderStatusChanged`/notifications fire pre-commit (not coupon-critical; out of scope).
- Sweepers (`expire-reservations` 5min, `expire-claims` hourly, `cancel-unpaid` 5min) are
  scheduled with `withoutOverlapping`. Reservation sweeper deletes only expired rows;
  POLICY-4 path covers in-flight expiry.

# Legacy Audit

Searched REST routes (428), GraphQL schema, repositories, services, models, resources,
commands, jobs, listeners, events, observers, seeders, factories for coupon mutation paths.

- Marvel `OrderRepository::recordCouponUsage/validateCouponUsage`: **no callers** in
  app/marvel src (verified) — DEAD. `OrderManagementTrait::changeOrderStatus` similarly
  unreferenced by runtime (canonical is app `OrderService`). Classification holds.
- `Coupon::isValid()/calcPrice()` deprecated: **zero source callers** (verified with
  recursive search; earlier empty result was re-done after fixing a non-recursive
  search bug — this audit caught its own tooling error).
- `Coupon::create/update*`: no source callers outside repository + seeders — CP-11 holds,
  minus F-04.
- **GraphQL (the gap in the prior classification)**: `/graphql` route live; schema
  imports coupon.graphql; `coupons`/`coupon(code:)` expose `code` (CP-02 bypass),
  `deleteCoupon @delete` has no auth, `createCoupon` reaches controller via `Shop::call`
  which bypasses constructor permission middleware — BUT the schema currently fails
  server-side build (`@restore` on non-SoftDeletes Coupon; proven by
  `lighthouse:validate-schema` DefinitionException), so the whole endpoint errors and
  all GQL paths are unreachable. F-02. `verifyCoupon`/`updateCoupon` resolvers point to
  nonexistent controller methods (dead even if schema is fixed).
- REST admin coupon CRUD has no routes (only config/assignment endpoints, guarded).
- No observers/migrations mutate coupon state at runtime. Seeders write coupons/usages
  directly (seeder-only, acceptable; counters reconciled).

# Security Audit

- Enumeration: REST public shape verified clean (no `code`); generic apply errors;
  GQL enumeration exists but unreachable (F-02). Claim 409 leaks `failed_rules` (F-11, P3).
- Authorization: claim binds `findOrFail(id)` + authenticated user; redeem path resolves
  coupon/user/claim from the order snapshot (no client-supplied IDs trusted);
  assignment REST guarded by per-action permissions; approve/disapprove have in-method
  super-admin checks (hold even via `App::call`).
- Mass assignment: repositories whitelisted; `used` still fillable at model level (F-04).
- Input: apply `required|string|max:191`; callback paymentId regex-bounded; mismatch
  messages sanitized (`strip_tags` + length cap) before storage/response. No SQLi/XSS
  vectors found in coupon paths (`whereRaw('UPPER(code) = ?')` is bound).
- Unicode/overlong codes: normalized (UPPER+trim, blank→match-nothing guard); admin
  cannot set arbitrary codes (server-generated); ASCII invariant documented.

# Database/Performance Audit

- `UPPER(code)` full scan on every lookup; no expression index exists (verified across
  both migration trees). Acceptable while `coupons` is small (hundreds); revisit trigger:
  measured slow-query evidence or table growth past low-thousands. F-07 (P3).
- `coupons.code` DB unique exists (MySQL ci-collation ⇒ case-insensitive at DB level;
  SQLite binary ⇒ M3 app guard covers). Concurrent admin duplicate-create race remains
  theoretically (admin-only, low risk).
- Locking queries are keyed (`whereKey`/`where(order_id)`/parent targeting row) — no
  table scans under lock observed. Counter `increment()` runs under row locks.
- Reconciler counter check is N+1 (fine for ops cadence at current scale).
- Repair migrations are driver-guarded (sqlite-only DDL, MySQL no-op) with dedup
  pre-flight — verified; full-chain `migrate:fresh` previously proven on MySQL 8.4.

# Regression Audit

- In-session (before F-15 broke boots): `SecurityRemediationTest` 30/30 PASS,
  `tests/Unit` 350/350 PASS (includes EligibilityEngine 13/13). `php -l` clean on all 15
  remediation-touched files (this session, post-break — syntax unaffected).
- Prior-session reported evidence (accepted as reported, NOT re-executed): Assigned 49,
  Remediation 15, CouponSystem 21, ClaimLifecycle 18, ConcurrencyProof 4, MySQL suite
  passes, parallel-claim PROOF_OK.
- **Phase 14 re-run now BLOCKED by F-15** (`routes/api.php` fatal breaks every boot;
  even single-file runs die). No coupon test could be (re-)executed in the current tree.
- Pre-existing failures (unchanged, out of scope, proven unrelated in prior session via
  stash A/B): hardening-suite checkout tests (missing `categories` table), gift-promotion
  test, 2 mark-paid + 1 reaper test. The concurrent shipment work may add new failures —
  unknown until F-15 is repaired and suites re-run.

# End-to-End Scenarios

A. Normal checkout+pay: reserve (init) → callback locks → token → verify → commit
   inventory/promotion → usage+counters+consume → completed → PaymentSucceeded →
   claim REDEEMED. Verified in code. ✓
B. Reserve+fail+retry: tx failed, order pending, reservation live → retry re-locks,
   token released by rollback, reprocesses cleanly. ✓ (success callback)
C. Success+duplicate: token early-return; order-status secondary. ✓
D. Success+coupon failure: success callback → visible failure + detector row (M1 ✓);
   **error callback → unhandled 500, no marker (F-01)**. ✗
E. max_claims race: serialized on targeting lock; MySQL PROOF_OK (reported). ✓*
F. Cancel: sync in-tx reservation release on every path; quota kept. ✓
G. Expiry: sweeper deletes; completion reacquires or fails closed. ✓
H. Promotion+coupon: fixed pipeline, idempotent finalize, rollback-safe. ✓
I. Ineligible product at completion: linkage gate re-checked (M7 ✓); active-status NOT
   re-checked (F-10). △
J. Queue retry after transient DB failure: rethrow → worker retries (×5) → idempotent
   body; exhaustion → failed_jobs + reconciler flag. ✓
K. Two workers, same completion: order-lock serialization + idempotent guards. ✓
L. Reserved coupon invalidated pre-payment: revalidate at completion → fail-closed
   (success callback) / 500 (error callback, F-01). △

# Accepted Risks Review

- CP-12 (never-restore): still valid, documented, covered by cancel/refund tests. HOLD.
- CP-03 (no DB claim unique): still valid; mechanism re-verified; detector covers escape.
  HOLD. (Docblock overclaim flagged separately as F-05.)
- Money-captured/order-pending after fail-closed: documented with runbook + detector for
  the success-callback path; the error-callback path has neither (F-01) — risk NOT
  fully mitigated. ESCALATE via F-01.

# Previously Unverified Items

- Production-data audit → NOT VERIFIED (no access; queries provided).
- Queued non-sync dispatch timing → VERIFIED (ShouldDispatchAfterCommit + afterCommit + worker contract).
- Gateway retry behavior post-M1 → PARTIALLY VERIFIED (traced; not live-fired).
- UPPER() cost measurement → NOT VERIFIED (no measurement infra; scale argument only).
- Legacy lowercase snapshot linkage (`orders()` join) → NOT VERIFIED (no dependent caller; unchanged).
- Suites outside coupon/payment/unit scope → NOT VERIFIED, now additionally BLOCKED (F-15).

# Findings

## F-01 — P1 MUST FIX — fail-closed exception escapes on error-callback completion path
Component: `app/Http/Controllers/Api/General/OrderController.php::checkoutErrorCallback`
Evidence: lines 610–656 call `changeOrderStatus(... 'completed' ...)` inside
`DB::transaction` with no `catch (CouponConsumptionException)`; contrast
`checkoutCallback` lines 457–504 (M1 handling). Current behavior: 500 to user/gateway,
tx stays `pending` (not marked failed), no `PaymentFailed` event. Expected: mirror M1
(fail visibly, mark tx, emit event, detector row). Impact: paid-at-gateway orders stuck
invisibly pending; gateway retries loop on 500s; reconciler `blocked_completion_*`
detector (tx failed + order pending) misses these rows entirely. Reproduction: coupon
order whose quota exhausts between reservation and completion, completed via the error
callback. Verification status: VERIFIED by code comparison of the two paths.

## F-02 — P2 SHOULD FIX (latent P0/P1 if schema repaired without auth) — GraphQL coupon fields unauthenticated
Component: `packages/marvel/src/GraphQL/Schema/models/coupon.graphql`, `/graphql` route
Evidence: `coupons`/`coupon(code:)` expose `code` with no `@guard/@can`;
`deleteCoupon @delete` has no auth; `createCoupon` → `Shop::call` bypasses controller
permission middleware (`App::call` never runs router middleware; FormRequest
`authorize()` returns true). Route middleware is AcceptJson + AttemptAuthentication
only (lighthouse.php:31–36). Current behavior: schema fails server-side build
(`@restore` on non-SoftDeletes Coupon — proven via `lighthouse:validate-schema`
DefinitionException), so the endpoint errors and all GQL paths are unreachable.
Expected: add `@guard`/`@can` (or remove dangerous fields), fix resolver references,
and only then repair the schema. Impact if activated as-is: code enumeration (CP-02
bypass), unauthenticated coupon deletion (cascades wipe usage history), potential
coupon self-issuance via multipart Upload. Verification status: VERIFIED (schema text +
route config + failed schema build).

## F-03 — P3 HARDENING — Orchestrator fail-open catch swallows claim-gate errors
Component: `app/Services/Coupon/CouponOrchestrator.php:51-54`. Any exception in the
targeting/claim check silently continues validation without the claim gate. Narrow to
missing-table case and log, or rethrow. All current callers run in transactions (real DB
errors roll back), so practical impact is minimal. VERIFIED.

## F-04 — P2 SHOULD FIX — `used` still mass-assignable at model level
Component: `packages/marvel/src/Database/Models/Coupon.php:24-38` (`$fillable`
contains `used`, `code`, `status`, `limiter`). Repository whitelisting holds and no
other `Coupon::create/update` callers exist in app/marvel src (verified recursive
search), but any future writer bypassing the repository inherits the hole. Remove
system-managed fields from `$fillable`. VERIFIED.

## F-05 — P3 — ClaimService docblock asserts a nonexistent DB constraint
`CouponClaimService.php:26` claims `UNIQUE(coupon_id, user_id) WHERE status='active'
(MySQL production)`. Migration `2026_09_14_000001` dropped the plain unique and added
only `idx_claim_lookup` (verified). Correct the comment so nobody relies on it. VERIFIED.

## F-06 — P3 — reconciler not scheduled
`app/Console/Kernel.php` (verified lines 28–67) schedules expiry/cancel sweepers but not
`coupons:reconcile`. Wire it (e.g. hourly) with alerting on exit 1, or document manual
cadence. VERIFIED.

## F-07 — P3 — UPPER(code) full scans, no expression index
No functional issue at current scale; revisit on slow-query evidence or table growth.
Do NOT add schema in this audit (per rules); recommended action is observability first.
VERIFIED (migration trees searched).

## F-08 — P3 — unique-race surfaces raw QueryException instead of domain exception
Concurrent double-completion on the public path can raise a DB unique violation rather
than `CouponConsumptionException` (safe direction — no double consume — but a 500
instead of the handled failure path; the callback's coupon catch misses it). Map to the
domain exception. Reasoned from code + constraints; not live-fired.

## F-09 — P3 — error-callback lacks token idempotency defense
Safe by order-status check + row locks (traced both interleavings), but asymmetric with
the success path. Consider setting/checking `idempotency_key` there too. VERIFIED.

## F-10 — P3 — completion gate skips product active-status
M7 revalidation checks coupon↔product linkage, not active/stock state. Deactivation
between checkout and payment can complete with coupon consumed (order-level concern;
needs product/inventory-team confirmation whether inventory commit blocks it). VERIFIED.

## F-11 — P3 — claim 409 context leaks eligibility internals
`CouponController::claim` returns `failed_rules` (both app + Marvel controllers).
Authenticated self-oracle; consider generic reason only. VERIFIED.

## F-12 — P3 — legacy verify() computes unvalidated coupon discounts
`CheckoutRepository::verify` has no validation call; reachable only via GQL
`verifyCheckout` (unreachable while schema broken) whose return type exposes no coupon
fields. Confirm dead on REST (verified: no route) and harden or remove when touching GQL. VERIFIED.

## F-13 — P3 — documented lock order contradicts code
INV-14 doc says Order last; `changeOrderStatus` locks Order first. No cycle found, but
fix the doc to prevent future inversions. VERIFIED.

## F-14 — P3 — possible null-order PaymentSucceeded from error callback
Line 666 dispatches with `$order` possibly null (when only the second tx lookup hit);
queued notification listeners would crash on null. Guard or pass `$lockedOrder`. Reasoned;
rare path. VERIFIED by reading.

## F-15 — BLOCKED (environment, not coupon) — concurrent tree breakage kills all boots
`routes/api.php` (working-tree, mid-audit modification by another party) adds a
duplicate `ShipmentController` import → PHP fatal on every boot; no HTTP, no tests, no
artisan route commands run in the current tree. NOT a coupon defect; must be repaired
by its owner (not in this audit's remit). Phase 14 re-runs blocked. VERIFIED (fatal
reproduced on single- and multi-file test runs + route:list).

## F-16 — P3 — re-claim after REDEEMED double-occupies capacity slots
`claim()` permits a new ACTIVE claim when the prior claim is REDEEMED (by design, for
re-claim flows), but REDEEMED rows permanently count toward `max_claims`, so one user
can occupy 2+ slots while `coupon_usages` uniqueness blocks any second consumption.
Decide: intended (document) or disallow ACTIVE re-claim while a REDEEMED claim exists.
VERIFIED in code.

# Required Actions

1. F-01: mirror M1 `CouponConsumptionException` handling into `checkoutErrorCallback`
   (mark tx failed, emit `PaymentFailed`, return failure UX, keep rollback).
2. F-15 owner: fix `routes/api.php` duplicate import; then re-run Assigned (49),
   Remediation (15), CouponSystem (21), Security (30), Unit (350) + reconciler.
3. F-02: before any schema repair, add `@guard`/`@can` to coupon GQL fields (or delete
   dangerous ones), fix `verify`/`updateCoupon` resolver references, then repair
   `@restore`; re-validate schema in CI.
4. F-04/F-03/F-05/F-13: small hardening + doc corrections (fillable, catch scope,
   docblock, lock-order doc).
5. Schedule `coupons:reconcile` (F-06) with alerting; run the Phase-8 query pack against
   production (read-only) before declaring readiness.
6. Decide F-10 (product-state at completion) and F-16 (redeemed re-claim slots) with
   product; record in policy if behavior stays.

# Final Verification Matrix

| Area | Status | Evidence |
|---|---|---|
| Fail-closed completion (success cb) | VERIFIED | code: throw→rollback→M1 marker |
| Fail-closed completion (error cb) | FAILED (F-01) | code: no catch |
| Idempotent callbacks | VERIFIED | code: token + status + locks |
| Claim/usage once-only | VERIFIED | code + constraints + prior proofs* |
| Concurrency (10×claim) | PARTIALLY (*reported PROOF_OK, not re-run) | prior evidence + mechanism re-verified |
| Promotion pipeline | VERIFIED | code |
| Product gate | VERIFIED with gap (F-10) | code |
| Reconciler | VERIFIED (unscheduled F-06) | code + prior clean run* |
| Queue timing/retries | VERIFIED | code + supervisor/config |
| Legacy bypasses | VERIFIED except latent GQL (F-02) | routes + schema + build failure |
| Security (REST) | VERIFIED with notes (F-04, F-11) | code |
| Performance | NOT VERIFIED by measurement | scale argument only |
| Regression suites | BLOCKED (F-15); earlier greens timestamped | test output (pre-break) + php -l now |
| Production data | NOT VERIFIED | queries provided |

\* accepted as reported prior-session evidence; independently re-tracing done, re-execution blocked.

# Final Readiness State

**NOT READY.**

A P1 (F-01) is open on a payment-completion path, and final regression cannot execute
in the current tree (F-15). Nothing in this audit contradicts the remediation's core
correctness, and no P0 is currently reachable — but "not reachable" for F-02 depends
on a broken schema, which is not a control. After Required Actions 1–2 (+ green
re-runs) and a decision/record on F-02, the supportable state is PRODUCTION READY
WITH DOCUMENTED OPERATIONAL RISKS (F-03–F-14, F-16 as residual hardening).
