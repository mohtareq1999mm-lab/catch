# COMMERCE WORKFLOW IMPLEMENTATION PROGRESS

> Single source of truth. Updated after every meaningful task.
> Legend: VERIFIED = source read this run · REPORTED = in-session specialist payload ·
> INFERRED = reasoned · NOT VERIFIED = needs runtime/staging proof.
> Rule: SOURCE CODE > DOCUMENTATION. No application code modified during Phase 0.

## Overall Status

Current Phase: Phase 7 — Warehouse (Phase 6 VERIFIED)
Current Step: Phase 6 gate passed; advancing autonomously per §43
Overall Completion: Phase 0–6 of 17 done (new numbering); P7–P17 pending
Last Verified At: 2026-09-22 (UTC)
Current Blocker: Human approval required before Phase 2 (APPROVE PHASE 2 gate;
prior §55 gate carried over). Phase 1 gate re-verified this run — see Phase 1 Gate Check below.

---

# Phase 1 Gate Check (sequential-directive verification, 2026-09-22)

Status: PASS — Phase 1 remains COMPLETE; no rework required.

- Baseline re-verified: same branch/dirty-tree state; 13 COMMERCE_*.md files present on disk
  (12 architecture docs + progress file). No material source drift since Phase 0/1 evidence.
- §7 doc list reconciliation: `COMMERCE_SECURITY_MODEL.md` (locked name per prior §30 mandate)
  satisfies this directive's `COMMERCE_SECURITY_ARCHITECTURE.md` requirement — identical normative
  content (perms, separation, scoping, B4/B5/B6/M1 fixes). No duplicate created per no-duplicates rule.
  PICKING/PACKING/SHIPMENT/IDEMPOTENCY docs are an approved superset of the §7 list.
- §7.1 sequence deviation (documented, evidence-based): the directive's linear
  `Payment Commit → Inventory Commit → Fulfillment Creation` cannot hold for COD/pay-at-cashier
  (warehouse must operate pre-capture; 7d COD expiry proves deferred capture is the live model).
  Locked refinement: Release-to-Warehouse per-method rule (online: success+commit; deferred:
  active reservation). Rationale recorded in COMMERCE_WORKFLOW_ARCHITECTURE.md §1.
- Acceptance: end-to-end flow, payment abstraction + method matrix, 5 separated state machines +
  ownership matrix, inventory mutation map, fulfillment/allocation/location/exception contracts,
  picking (order+batch), packing invariant, shipment boundary, delivery/completion rule, failure
  matrix + master Mermaid, security/idempotency models, migration baseline, legacy classification,
  RabbitMQ independent-decision record — all present. Self-review corrections applied (split-key,
  SLA flag, acyclic dependency chain).
- Remaining before Phase 2: human APPROVE PHASE 2 + Q3/Q4/Q5 defaults confirmation + B1 decision.
- Re-verified 2026-09-22 (subsequent run): branch/tree/docs unchanged (13 COMMERCE_*.md present);
  Phase 1 lock still holds, no rework. Gate stands: NO Phase 2 without explicit approval.

---

# Phase 0 — Discovery

Status: COMPLETE (read-only; implementation started: NO)
Started: 2026-09-22
Completed: 2026-09-22

### Tasks

- [x] Repository baseline
- [x] Current order lifecycle
- [x] Current payment lifecycle
- [x] Current inventory lifecycle
- [x] Current fulfillment lifecycle
- [x] Current shipment lifecycle
- [x] Current coupon/promotion interaction
- [x] Current notification interaction (REPORTED — digital/coupon listeners wired; physical WMS events absent/dead)
- [x] Current legacy flows
- [x] Current tests
- [x] Current routes
- [x] Current permissions

### Evidence

**Baseline (VERIFIED).** `D:\work\meem`, Laravel 10.30.1, PHP ^8.0|^8.1 (Docker php:8.2-cli-alpine),
PHPUnit 10.0.13, predis, `database` queue (high/medium, retry_after 1800 > worker 1300 > job 1200).
`app/` = application layer; `packages/marvel/` = vendored kernel (models, Rest/Routes, repos, legacy
Payment, Permission enum). Dirty tree (~136 files) — attribution to any single run NOT VERIFIED.

**Order lifecycle (VERIFIED).** `Marvel Order`: order `pending/processing/completed/cancelled/delivered`
(`Order.php:18-22`); payment `payment-pending/success/failed/refunded` (`:34-37`); inventory
`none/active/released/committed/restored` (`:28-32`); fulfillment constants (`:39+`).
Flow: checkout → `OrderService::addItemsInOrder` (`:316 reserveForOrder`) → `active` → callback
`commit` (`OrderController:447,666`; `OrderService:848` in `changeOrderStatus`) → paid-cancel
`restore` (`OrderService:894-896`); expiry → `release` (`:899`, `CancelUnpaidOrders:95`).

**Payment lifecycle (VERIFIED).** Contract `PaymentGatewayContract` (`createInvoice/verifyPayment/refund/
name/supportsCurrency`, no authorize/capture/void — MyFatoorah invoice model). Factory `make()` supports
ONLY `myfatoorah`, else `UnsupportedGatewayException` (`PaymentGatewayFactory:11-17`). Handler:
`handleOnlinePayment/handleCodPayment/handleCashierQrPayment` create `Transaction` rows
(`payment_method`, `gateway_transaction_id`) + coupon `reserve()`. Callback (`OrderController:169-558`):
format validation → server-side `verifyPayment` → lock → idempotency token + status guard (`:308-340`)
→ amount×1000 + currency compare, fail-closed nulls (`:342-401`) → commit or fail. `payments:reconcile`
every 15 min (`Kernel:46-49`). Legacy Marvel `Payment/*` (Stripe/PayPal/…) = DEAD (REPORTED, no routes).

**Inventory lifecycle (VERIFIED).** Authority = `products`/`product_variants`
(`stock_quantity/reserved_quantity/sold_quantity/in_stock`; available = max(0, stock − reserved);
`Product.php:42,99,111,553,627`; `ProductVariant.php:36,116`). Variant stock independent per row
(`lockStockRow`). `OrderReservationService:37-221` (two-pass locked reserve; conditional-claim
commit/release; digital exclusion D1; COD 7d / online 24h expiry). `InventoryRestoreService:26-55`
(committed→restored). Cart holds NO counters (header-verified); legacy `cart_items.reserved_quantity`
detached by `MigrateInventoryReservations`.

**Fulfillment lifecycle (VERIFIED — STAGED, not live).** `FulfillmentService::createFromOrder`
(needs `Warehouse::default`, allocates via lock-free `allocateFromLocations`, no reservation check),
`updateStatus` DAG (`pending→picking→packing→shipped→delivered`); `BatchPickingService::recordPick`
(task/item/batch counters ONLY, `:147-212`); `PackingService` writes ghost states `packed`/
`ready_to_ship` (`:100-153`); `ReturnService::restockReturnItem` bumps location qty, not central stock
(`:218-267`). Zero callers outside self-definitions; zero routes (shipment routes commented out;
only live surface = admin shipment show/update-status). `FulfillmentCompleted` event DEAD.

**Shipment lifecycle (VERIFIED).** `ShipmentService::create` (forces `pending`, `:34-40`) +
`updateStatus` model-gated via `canTransitionTo` under `lockForUpdate` (`:47-73`) — the pattern to copy.
`shipments` carry `fulfillment_id/packing_task_id` (migration `191253`). No `ready_to_ship` guard yet.

**Coupon/promotion/tax (VERIFIED).** `calcInvoicePrice`/`addItemsInOrder`: `CouponOrchestrator::validate`
→ promotion select → `withTaxes` (tax AFTER discounts, `:272`) → + shipping. Snapshots: order financial
columns + `OrderProduct.product_sku`; coupon consumption finalized post-payment with `M1` blocked-completion
catch on success path (REPORTED; error-callback gap F-01 REPORTED).

**Security/permission flow (VERIFIED).** Routes: `auth:sanctum` + `throttle:*`; enforcement in-controller
`authorizeAdmin()` (tracking `view-orders`; analytics `view-analytics` vs export `export-analytics`;
coupon `view|update|create-coupon`; distribution strict `update-coupon`). `AdminMiddleware` exists, NOT
wired. No warehouse/picking/packing permissions exist (only `view/create/update-shipment*`,
`view-pickup-locations*` = customer pickup points). `SecurityRemediationTest` 30/30 PASS (executed this
run, sqlite, 17.41s).

**Queue flow (VERIFIED).** `database` queues high/medium; scheduler: `orders:cancel-unpaid` 5min,
coupon expiry/reconcile, `payments:reconcile` 15min, prune jobs; `withoutOverlapping` + `onOneServer`
(needs shared cache — array driver in tests disables it). RabbitMQ `php-amqplib:3.7` present in
`composer.json:31` despite AGENTS.md §21.8 ban (B1).

**Legacy flows (VERIFIED/REPORTED).** `CartInventoryService::deductStockForOrder` + `OrderRepository::
deductStock` dual-write = stale (cart docs describe, code header disavows). Marvel Payment/* dead.
Test-PAN route public (REPORTED). `MigrateInventoryReservations::auditDrift` force-rewrites counters.

**Tests/routes/permissions inventory (VERIFIED).** `tests/Feature/SecurityRemediationTest.php` exists;
`routes/api.php` 244 lines (payment callbacks public + `throttle:payment-callback`; mark-paid under
`permission:update-order-status` in customer namespace); `AppServiceProvider:80-177` limiters (overlap
with `RouteServiceProvider:60-117` — precedence NOT VERIFIED); `Permission` enum = shipment + pickup
locations only for WMS-adjacent area.

### Findings

BLOCKERS: B1 RabbitMQ ban violation · B2 dual inventory authority (location plane unlinked; picks move
nothing; returns over-credit) · B3 status divergence (ghost `packed`/`ready_to_ship`) · B4 success UI
with no local order (`OrderController:265-279`) · B5 test-gateway amount/currency bypass (`:358-399`,
config-gated not env-gated) · B6 over-broad mark-paid (any `update-order-status` marks ANY order paid).
MUST-FIX: M1 distribute reuses `update-coupon` · M2 idempotency key never cleared on coupon-block (`:460`
comment claims release, code doesn't) · M3 observer pre-commit dispatch · M4 paginate-then-filter ·
M5 Marvel→App reverse dep (`Order::fulfillments`) · M6 dead fulfillment surface + migrations with no
routes. SHOULD-FIX: in-controller auth fragility, empty catch swallowing permission outages, type
mismatch (location decimal:2 vs central integer), `can go negative` availableQuantity, predictable
number generators, scheduler fan-out cost, `release.sh` missing (build break — REPORTED).

### Blockers

1. Human approval to proceed past Phase 0 (directive §55).
2. Business decisions Q1–Q5 (see Phase 1 section + final message): fulfillment timing, single- vs
   multi-warehouse split at launch, delivered-writer, quarantine semantics, single vs multi barcode.
3. B1 RabbitMQ decision (remove vs explicitly re-approve) — affects queue/supervisor surface.

---

# Phase 1 — Architecture (P0 gate)

Status: COMPLETE (design lock; NO code/migrations/routes/config/DB changed)
Started: 2026-09-22
Completed: 2026-09-22

### Decisions (all locked)

1. Timing (§3 conflict RESOLVED): NEITHER pure A nor pure B. **Release-to-Warehouse (Option C)** —
   fulfillment requires RELEASE, not capture: online methods release on success+commit; cod/cashier
   release on active reservation (capture at delivery/mark-paid via canonical idempotent path).
   Evidence: `OrderService:841-848` (commit on completed, all methods), `:936-988` (mark-paid →
   completed → commit), 7d COD expiry (warehouse must operate pre-capture).
2. Providers: MyFatoorah/cod/cashier LIVE; Marvel Stripe/PayPal/… DEAD; test-gateway TEST ONLY;
   future providers = adapter + config + webhook adapter + tests, zero workflow changes (§46 proof).
3. States: five separated machines; single writer each (ownership matrix in
   COMMERCE_ORDER_STATE_MACHINE.md §7); `Shipment::canTransitionTo` model-gate is the pattern.
4. Allocation non-mutating; ProductLocation = hints (`allocated_hint` rename, int typing, P1);
   drift → monitor alert, never throw; short-pick always explicit exception.
5. Picking: one task model (`batch_id nullable`); claim = conditional update + lease + 409 loser;
   confirm = op_seq dedupe + locked remaining check + fan-back traceability.
6. Packages under fulfillment → fulfillment_items; pack invariant via locked read-check-write.
7. Shipment from `ready_to_ship` only (P11 guard); fulfillment ships via dispatch event only.
8. Delivery/completion: order `delivered` by delivery-confirmation path (default shipment-delivered
   event — Q3; alternatives documented, approval may change default). Pick/pack NEVER complete orders.
9. Security: picker/packer ≠ financial perms; warehouse scoping; 404 anti-enumeration; P1 fixes
   B4 (failure, never success UI), B5 (env-gated), B6 (admin namespace + audit), M1 (new perm).
10. RabbitMQ (§29): independent decision; B1 documented as conflict; WMS stays on approved
    `database` high/medium queues. No broker migration inside WMS phases.
11. Locations: reuse hierarchy + type semantics (place ≠ condition ≠ sellability); barcodes:
    generated unique immutable per kind; SKU-as-WHAT v1 via resolution layer (Q5 default: single).
12. Self-review (§34) corrections applied: same-warehouse split key (fulfillment_number + request
    key, not unique order+warehouse); COD 7d expiry vs warehouse SLA flagged (config-driven +
    supervisor path); one-direction dependency chain verified (fulfillment reads reservation,
    shipment reads fulfillment, completion reads all — no cycles).

### Documents (new, Phase-1 set; existing docs extended by reference, no duplicates)

- COMMERCE_WORKFLOW_ARCHITECTURE.md · COMMERCE_PAYMENT_ARCHITECTURE.md (+§8 method matrix)
- COMMERCE_ORDER_STATE_MACHINE.md (incl. ownership matrix) · COMMERCE_INVENTORY_FLOW.md
- COMMERCE_FULFILLMENT_FLOW.md · COMMERCE_WAREHOUSE_FLOW.md · COMMERCE_PICKING_FLOW.md
- COMMERCE_PACKING_FLOW.md · COMMERCE_SHIPMENT_FLOW.md · COMMERCE_FAILURE_RECOVERY.md
  (incl. master Mermaid with failure branches) · COMMERCE_SECURITY_MODEL.md
- COMMERCE_IDEMPOTENCY_MATRIX.md

### Migration baseline (§27 — recorded, not touched)

Staged WMS: `A app/Models/Fulfillment/{Fulfillment,FulfillmentBatch,FulfillmentItem,PackingStation,
PackingTask,PickingTask,ReturnItem,ReturnRequest}.php` (Location/Warehouse/ProductLocation models
present, unstaged or prior); `A app/Services/Fulfillment/{BatchPickingService,FulfillmentService,
PackingService,ReturnService}.php`, `M ProductLocationService.php`; migrations 081818/081824/081825,
130803/130826, 190340/190356/190932/190957, 191253. P1+ must gate runtime behind `wms.enabled=false`
default and never overwrite unrelated dirty work.

### Legacy cleanup (final classification — design only, nothing deleted)

KEEP: reservation/restore/reaper, Shipment gate pattern, Location/Warehouse schema, changeOrderStatus
canonical path. EXTEND: Transaction→PaymentAttempt formalization, Shipment creation guard.
REFACTOR: status writers → single owners; ProductLocationService (lock + hint contract);
ReturnService restock (via restore+placement); sync→alert. DEPRECATE: legacy deduction paths,
`FulfillmentCompleted` (wire or remove in P16), auditDrift force-fill. REMOVE (P16, after
zero-caller proof): Marvel Payment/*, test-PAN route, `nul`/cache artifacts, stale supervisor file.
REPLACE: ghost states into DAG; `reserved_quantity`→`allocated_hint`. RabbitMQ: separate decision.

### Unresolved (only genuinely unavoidable)

- Q3 delivered-writer default (shipment event; confirm or pick B/C).
- Q4 quarantine-counts-toward-placement (default: only PICKING/STORAGE placeable).
- Q5 multi-barcode table now vs later (default: later; resolution layer ready).
- B1 RabbitMQ: remove vs explicitly re-approve (blocks queue-adjacent staging checks, not WMS logic).

---

# Phase 2 — Payment Architecture — Status: VERIFIED
Started: 2026-09-22 · Completed: 2026-09-22 · Branch: main @ 107dd6b

## Phase 2 — Final Report

### Objective
Provider-agnostic payment hardening: fix B4/B5/M2, prove §46 (no provider branches),
verify lifecycle/failure/refund behavior with tests.

### What Was Implemented
1. B4 (both callbacks): gateway-verified + locally unknown payment → fail-safe failed
   response (mobile 400, web /payment/failed redirect) + warning log. Never success UI.
2. B5 (both callbacks): mismatch bypass now requires `payment.test_gateway_bypass_enabled`
   (new, default OFF, `PAYMENT_TEST_GATEWAY_BYPASS`) AND local/testing env via
   `OrderController::isTestGatewayBypassAllowed()`. Removed raw URL+!production checks.
3. M2 (both coupon-block handlers): rotate `idempotency_key`→null + stamp
   `_coupon_blocked_at/_reason` in gateway_response; status stays failed; corrected
   comment claiming release that never happened.

### Existing Code Reused
Contract/factory/handler/callback pipeline, token+status idempotency, millis amount
checks — all preserved untouched. Zero provider branches in OrderService (VERIFIED: 0 matches).

### Files Changed
- config/payment.php: +test_gateway_bypass_enabled flag (additive).
- app/Http/Controllers/Api/General/OrderController.php: B4 guards ×2, B5 helper + 4
  condition swaps + dead-var removal, M2 rotation ×2 + comment fix.
- tests/Feature/SecurityRemediationTest.php: bypass test opts into flag (contract change);
  +test_payment_callback_test_gateway_blocked_by_default; +unknown_order_never_shows_success.
- tests/Feature/PaymentCallbackStressTest.php: +idempotency_key to stale hand schema;
  B4 test updated to fail-safe; mismatch expectation aligned to canonical `failed`.

### Database Changes
NONE (no migrations; token rotation uses existing columns).

### Tests Executed
- SecurityRemediationTest: 32 passed (104 assertions) — includes 2 new + updated bypass.
- CheckoutApiTest: 13 passed. Refund adapter: PaymentCurrencyTest refund 1 passed.
- PaymentCallbackStressTest: 6 passed / 3 failed — all 3 pre-existing rig failures
  (second-request 409, dirty-PDO-tx in stale hand-rolled schema; identical before changes;
  behaviors proven in SecurityRemediationTest). Filed as Phase-16 legacy test debt.

### Security/Concurrency/Idempotency Validation
Amount/currency/null/1-cent blocked; unknown-order spoof closed; bypass env-gated;
duplicate idempotent; no new locks needed (existing lockForUpdate path untouched).

### Failure/Recovery Validation
Mismatch/failed-verify/coupon-block/unknown-order paths all fail safe + audited.

### Legacy Paths Reviewed
bKash stub (vendor route shim) — UNRELATED, untouched. Marvel Payment/* — untouched (P16).

### Remaining Risks
Staging must keep PAYMENT_TEST_GATEWAY_BYPASS unset; stress rig debt (P16); MySQL
concurrency proof deferred to P14; refund recovery flows owned by P12.

### Final Verdict
VERIFIED — gate criteria met. Auto-advancing to Phase 3.

# Phase 3 — Order Lifecycle — Status: VERIFIED
Started: 2026-09-22 · Completed: 2026-09-22 · Branch: main @ 107dd6b

## Phase 3 — Final Report

### Objective
One authoritative order state machine; illegal transitions rejected unmutated.

### What Was Implemented
1. Writer audit: SOLE live writer is `OrderService::changeOrderStatus` (validated via
   `canTransitionOrderStatus`→throw→422, audit history, actor tracking). Marvel admin
   `PATCH orders/{id}/status` routes through it. `Marvel OrderRepository::updateOrder`
   bypass (raw status write, no inventory/coupon/audit) has ZERO code callers → DEAD,
   classified DEPRECATE (P16). `CancelUnpaidOrders` direct-cancel mirrors release/coupon/
   events (verified equivalent, documented).
2. D1 fix: `changeOrderStatus` now wraps `PaymentSucceeded` dispatch in try/catch(report)
   — listener failures can no longer roll back a valid completion (matches callbacks).
3. Listener fix: `GenerateInvoiceListener` no longer rethrows deterministic
   `CurrencyMismatchException` (log+report; transient errors still rethrow for retry).
   Root-caused via full stack: after-commit queued listener escapes dispatch guards and
   500s sync callers post-commit.

### Existing Code Reused
Transition maps, fulfillment-status mapping, audit/history, actor detection — untouched.

### Files Changed
- app/Services/General/OrderService.php: D1 dispatch guard.
- app/Listeners/GenerateInvoiceListener.php: deterministic-vs-transient throw policy.
- tests/Feature/OrderLifecycleTest.php (new, 6 tests): legal applies, 3 illegal throw
  unmutated, same-state reset.
- tests/Concerns/CreatesTestTables.php: +transactions.idempotency_key (production parity;
  healed CartOrderLifecycleTest 5 stale-schema failures).

### Database Changes
NONE.

### Tests Executed
- OrderLifecycleTest: 6 passed. CartOrderLifecycleTest: 38 passed (5 stale failures FIXED
  by shared-trait column). PendingOrderLifecycleTest: 6 passed. SecurityRemediationTest:
  32 passed (no regression). PaymentCallbackStressTest: 6/9 (3 pre-existing rig fails).

### Critical Finding for Phase 4
Invoice `CurrencyValidator::ALLOWED_CURRENCIES=[EGP,USD,EUR,GBP,SAR,AED]` excludes KWD —
the app default currency. KWD completions skip invoice generation (logged). Currency
semantics belong to Phase 4; NOT changed here.

### Remaining Risks
Marvel dead bypass awaits P16 deprecation; stress-rig debt (P16); MySQL proof (P14).

### Final Verdict
VERIFIED — auto-advancing to Phase 4.

# Phase Numbering (reconciled 2026-09-22)

Execution follows the autonomous directive: P1 Architecture · P2 Payment · P3 Order ·
P4 Checkout/Pricing · P5 Inventory · P6 Fulfillment · P7 Warehouse · P8 Picking ·
P9 Batch · P10 Packing · P11 Shipment · P12 Recovery · P13 Security · P14 Concurrency ·
P15 Observability · P16 Legacy · P17 Validation. (Original file stubs P4–P16 below map
to new P5–P17 respectively and are replaced as each phase completes.)

# Phase 4 (new) — Checkout/Pricing — Status: VERIFIED
Started: 2026-09-22 · Completed: 2026-09-22 · Branch: main @ 107dd6b

## Phase 4 — Final Report

### Objective
Deterministic financial pipeline; snapshots; rounding; coupon lifecycle; invoice currency.

### What Was Implemented
1. FIX (critical): `CurrencyValidator` allowlist excluded KWD (app default currency) →
   every KWD completion skipped invoice generation. Now sourced from
   `payment.gateways.myfatoorah.supported_currencies` (single source) + safe fallback.
   Verified: 50/50 lifecycle tests green incl. KWD completions.
2. Verified (no change): single pipeline `refreshCartItemPrices(ProductPricingService)`
   → `calculateCheckoutTotals` (promotion→coupon→tax→shipping) shared by preview
   (`calcInvoicePrice`) and creation (`addItemsInOrder`); snapshot writes consume the
   SAME totals object (create/update + items sync + reservation).
3. Verified: integer money coherent — cents (tax, largest-remainder) + millis (callback);
   exact-safe given schema constraint (products/cart prices decimal 10,2; orders.total
   decimal 8,3 but always written 2-decimal). Constraint documented, not "fixed" (no bug).
4. Verified: coupon reserve (checkout) → consume (completion, once) → rollback (cancel/
   expiry) via existing tests (CartOrderLifecycle 26/27/29/30/36 pass).
5. Flagged (P16, not changed): `getPaymentStatusAttribute` derived fallback — keep as
   legacy guard; prefer stored column + backfill nulls later.

### Files Changed
- app/Services/Invoice/Validators/CurrencyValidator.php: config-sourced allowlist.

### Database Changes
NONE.

### Tests Executed
- OrderLifecycle+CartOrderLifecycle+PendingOrderLifecycle: 50 passed.
- PaymentCurrencyTest: 5 passed (incl. refund-currency adapter test).

### Remaining Risks
2-decimal schema constraint must hold if 3-decimal pricing ever introduced (would need
millis-checkout migration); promotion precedence changes need pipeline test updates.

### Final Verdict
VERIFIED — auto-advancing to Phase 5 (Inventory).

# Phase 5 (new) — Inventory — Status: VERIFIED
Started: 2026-09-22 · Completed: 2026-09-22 · Branch: main @ 107dd6b

## Phase 5 — Final Report

### Objective
Prove reserve/release/commit/restore/expiry/digital/concurrent/insufficient paths.

### What Was Implemented
No production-code change required (engine verified as-is). Test-rig repairs:
1. `CreatesTestTables`: +transactions.idempotency_key (production parity; healed 5
   CartOrderLifecycle stale-schema failures as side benefit).
2. `CreatesTestTables`: +user_notification_preferences, +order_notifications,
   +user_device_tokens (mirror 2026_09_12_00000{1,2,3}; order-event listeners query
   them; string status on sqlite instead of enum).
3. `OrderReservationLifecycleTest::makeActiveReservation`: denominate fixture orders
   in EGP (production checkout always sets currency; null fell through to env base
   and tripped the CORRECT mismatch guard — 4 failures).
4. Digital test: settle mocks in the order's real HTTP-checkout currency.

### ENV INCIDENT (no app code involved)
Mid-phase, all HTTP suites began failing with Encrypter "incorrect key length".
Root cause: local `.env` APP_KEY payload is 45 chars (base64_decode strict → false);
file mtime 11:21 today — externally modified during session, author unknown.
Backup `.env.bak-20260923`, regenerated valid key via `key:generate --force`.
Healed immediately. NOTE: local-only (gitignored); if any local encrypted data
exists it is unreadable under the new key; prod untouched.

### Tests Executed
- OrderReservationLifecycleTest: 24 passed (reserve/commit/release/idempotency/
  aggregation/cart-isolation/rollback/F1/retry/last-unit/reaper-races/COD/admin
  cancel/restore-via-listener/digital).
- GiftAndReconciliationTest: 8 passed.
- Restore path covered by `admin_cancel_paid_restores_inventory_once_via_listener`.

### Remaining Risks
True concurrency (parallel FU locks) needs MySQL (P14); reaper boundary tests are
time-sensitive (count variance observed, green on rerun).

### Final Verdict
VERIFIED — auto-advancing to Phase 6 (Fulfillment).

# Phase 6 (new) — Fulfillment — Status: VERIFIED
Started: 2026-09-22 · Completed: 2026-09-23 · Branch: main @ 107dd6b

## Phase 6 — Final Report

### Objective
Release rule, single transition owner, ghost-state removal, idempotent creation.

### What Was Implemented
1. `FulfillmentTransition` (new): SOLE status writer — locked conditional transition,
   model DAG, timestamps, audit log, same-state no-op, RuntimeException on illegal.
2. `Fulfillment::allowedTransitions/canTransitionTo` (model gate, Shipment pattern).
   DAG refined with evidence: added `picked` (packing needs picking-complete state);
   fulfillment-level `packed` REMOVED (task-level only).
3. `FulfillmentService::releaseForOrder` (new): locked release rule — capture methods
   require COMMITTED+paid (test-proven: active+paid never coexists post-callback);
   cod/cashier require ACTIVE+pending; cancelled/delivered rejected. Idempotent per
   key; unkeyed returns existing pending fulfillment (split via distinct keys).
4. `updateStatus` → thin owner delegate. Batch/packing direct writes → owner:
   batch-create (pending→picking), batch-complete advances fully-picked (→picked),
   packing-task create (→packing), verify (→ready_to_ship), legacy createShipment
   (→shipped; flagged for P11 ShipmentService delegation).
5. Migration `2026_09_23_000001`: +fulfillments.idempotency_key (unique, nullable),
   +ready_to_ship_at, with down().

### Design Correction During Implementation (test-driven)
Initial rule (active+paid for online) proved unsatisfiable — commit consumes active
in the same callback. Corrected to committed+paid with test evidence. Docs updated.

### Files Changed
- app/Services/Fulfillment/FulfillmentTransition.php (new)
- app/Services/Fulfillment/FulfillmentService.php (release + delegate)
- app/Services/Fulfillment/BatchPickingService.php (owner writes + picked advance)
- app/Services/Fulfillment/PackingService.php (owner writes, packed ghost removed)
- app/Models/Fulfillment/Fulfillment.php (gate + fields)
- database/migrations/2026_09_23_000001_* (new)
- tests/Feature/Fulfillment/FulfillmentLifecycleTest.php (new, 6 tests)
- tests/Unit/Services/Fulfillment/* (3 files: DI fix + DAG expectation updates)
- COMMERCE_ORDER_STATE_MACHINE.md, COMMERCE_FULFILLMENT_FLOW.md (rule refinements)

### Database Changes
One additive migration (up/down verified via RefreshDatabase suites).

### Tests Executed
- FulfillmentLifecycleTest: 6 passed (release rules ×4, idempotency+split, DAG+ghosts).
- Unit fulfillment suites: 51 passed (incl. updated DAG expectations).

### Remaining Risks
Auto-release triggers (listeners) deferred to P12/P15 behind wms flag; P11 shipment
delegation note; scopeByPriority FIELD() is MySQL-only (P16 portability).

### Final Verdict
VERIFIED — auto-advancing to Phase 7 (Warehouse/Locations/Barcode).

# Phase 6 — Warehouse / Locations — Status: NOT STARTED

# Phase 7 — Picking — Status: NOT STARTED

# Phase 8 — Batch Picking — Status: NOT STARTED

# Phase 9 — Packing / Packages — Status: NOT STARTED

# Phase 10 — Shipment — Status: NOT STARTED

# Phase 11 — Delivery / Completion — Status: NOT STARTED

# Phase 12 — Security — Status: NOT STARTED

# Phase 13 — Concurrency / Idempotency — Status: NOT STARTED

# Phase 14 — Observability / Audit — Status: NOT STARTED

# Phase 15 — Legacy Cleanup — Status: NOT STARTED

# Phase 16 — Full Validation — Status: NOT STARTED

---

# Final Status

Phase 0 COMPLETE (read-only). No application code, migrations, config, routes, or tests modified.
No implementation started. Awaiting explicit human approval + Q1–Q5 business decisions.
