# WMS Variant Integration — Final Release Report (evidence-backed)

Date: 2026-10-14. Branch: `main`. HEAD at audit close: `8f84790` (+ uncommitted worktree deltas listed in §G).
PHP 8.2.30 / Laravel 10.30.1. Engine: MySQL 8 / InnoDB, isolation REPEATABLE-READ (live-verified).
Test DBs: isolated scratch DBs per run (`catch_wms_stage4`, `catch_wms_final`, `catch_wms_upgrade`,
`catch_wms_migtest` — created empty, all dropped after use). Dev `catch` used read-only except one
single-test confirmation run. No production exists in scope; none touched. No concurrent suites shared
a database (all multi-file runs chained sequentially in one process stream).

## Verdict: `CONDITIONALLY READY — EXPLICIT BLOCKERS REMAIN`

WMS + variant scope: **574 tests green** (§B), migration fresh + upgrade proven, genuine two-process
concurrency proven (E1–E4, §E), all write paths audited, RBAC 78-route inventory green.
Remaining red is OUTSIDE the scope and pre-existing: 1 checkout/flow supersede failure (§H.1) and
4 gift/invoice-currency failures (§H.2), plus frontend NOT VERIFIED (§H.3). No release of this scope
may be declared DONE until §H is dispositioned by its owners — but nothing in §H is caused by, or
fixable inside, the WMS/variant integration.

## B. Test evidence (all on isolated MySQL, exact commands recorded)

Focused WMS/variant suites (isolated DBs, `DB_DATABASE=catch_wms_*`, `php vendor/bin/phpunit <file>`):

| Suite | Result |
|---|---|
| WmsVariantInventoryTest (21: simple/variant receive, foreign/missing-variant 422, sibling isolation, transfer totals, occupancy SKU rule, idempotent replay, rollback atomicity, placement identity/filter/404, activity ledger, overview no-double-count + global scope, isolation 403/404, l10n EN+AR, backward compat, transfer SKU) | OK 21/21, 83 assertions — TESTED |
| WmsReceivingReliabilityTest (14: 5 key-conflict 409s incl. cross-operation + variant, reference-replay, 4 op-status incl. processing + cross-warehouse 404, occupied fallback, variant rollback atomicity, assign SKU guard) | OK 14/14, 74 assertions — TESTED |
| WmsMasterInventoryGapsTest (key-reuse, variable initial-placement refusal, drifted-confirm rejection) | OK 3/3, 16 assertions — TESTED |
| WmsPlacementAuditTest (audit command: clean/drift/overflow/scope/invalid) | OK 5/5, 14 assertions — TESTED |
| WmsInventoryOperationsListTest (ledger list: scope/filters/pagination) | OK 6/6, 46 assertions — TESTED |
| WmsMultiLocationTest (old product-level assertions hold verbatim under SKU rule) | OK 14/14, 97 assertions — TESTED |
| StockOperationsTest | OK 25/25, 70 assertions — TESTED |
| WmsLocationEmptyTest | OK 25/25, 110 assertions — TESTED |
| ReturnServiceTest (incl. new variant-restock-SKU + corrupt-history fail-closed) | OK 15/15, 48 assertions — TESTED |
| PlacementAssignmentTest (incl. new sibling-variant refusal) | OK 6/6, 23 assertions — TESTED |

Lifecycle/regression chain (same isolation pattern, sequential): WmsFulfillment 36, FulfillmentLifecycle 6,
OrderReservationLifecycle 24, BatchPicking 35, OrderCancellation 9, Packing 12, Shipment 8, GroupedPicking 15,
ApiFoundation 10, AuthorizationGate 11, LocationAudit 8, FulfillmentOrderSync 15, WarehouseLocation 27,
PickingBatch 35, E2ECompletion 3, EndToEnd 5, RbacCompletion 5 (78-route inventory + 401/403 sweeps),
RbacFoundation 11, BatchCompletion 7, PackingTest 2, PackingPhase6Test 27, Phase3ReleaseHardening 15,
PickingFoundation 20, RestockWarehouseIntegrity 2, ReturnRecovery 4, ShipmentBoundary 3,
ShipmentPhase8Governance 25, ShipmentPhase8Lifecycle 26, SingleWarehouseRelease 5, WarehouseSoftDelete 4,
LocationImmutability 4, DigitalFulfillment 11, FulfillmentHardening 6, FulfillmentService 8,
PackingService 11, ProductLocationService 9 — ALL OK.
NOT RUN in this session: OrderFlow matrix suites (untouched domain; sync covered via FulfillmentOrderSync),
notification/payment-gateway suites (untouched domains).

Red (pre-existing, out of scope, §H): Phase3InventoryCommitTest 13/14 (1 supersede failure),
GiftAndReconciliationTest 4/8 (2 errors + 2 failures, currency).

## C. Invariant matrix (VERIFIED = code/schema/diff evidence; TESTED = passing test)

- SKU-level authority (simple→products, variant→product_variants; parents never written): TESTED (variant
  receive asserts parent 0; transfer preserves variant total + siblings; overview ignores parent 999).
- One-SKU-per-bin occupancy (siblings refuse each other; zero rows = history): TESTED sequential (12-case
  matrix) + TESTED concurrent (E1/E2/E4) + VERIFIED locking-read fix (§E).
- No DB occupancy constraint beyond UNIQUE(product,location,variant) (MySQL NULL semantics documented;
  app serialization authoritative): VERIFIED (information_schema).
- syncWithStock observational only, now SKU-scoped: VERIFIED (code) + fix applied.
- Transfer atomicity + E-guards + authority compare: TESTED (incl. variant total preservation).
- Key binding (operation/SKU/warehouse/locations/quantity; reference excluded) → 409; processing → 409
  with status pointer; completed identical → 200 replay: TESTED (7 reliability cases).
- Operation status/single + list endpoints, scoping, 404s: TESTED (9 cases).
- Initial placement (simple assign-once; variable refused without product): TESTED.
- Assign/reallocate/confirm-drfit SKU guards, stock invariance: TESTED (service + HTTP levels).
- Restock SKU-aware (hint lands on variant row; corrupt history fail-closed): TESTED (2 new unit tests).
- Empty-location + destroy guards, RBAC 78 routes, EN+AR keys (incl. 5 new keys), additive resources:
  TESTED. Reservation/restore/flow/payment authorities: zero diff — VERIFIED (diff) + TESTED via suites.
- Stale expectations corrected with authority citations (packing/picking order convergence to `packed`;
  locale pins per project convention): each verified against OrderFlow sync + Arabic default.

## D. Endpoint inventory (all additive or new-URI; no request/response breakage)

POST stock-receipts / POST stock-transfers (variant passthrough + binding 409s) — compatible.
GET products/{product}/placements (+variant filter/404, SKU identity block) — additive.
GET locations (unchanged) / GET locations/{id} (+variant id) — additive.
POST fulfillment-items/{id}/assign-placement (+placement.variant) — additive.
DELETE locations/{location}/products ×2 aliases — new URIs.
GET inventory/overview (+totals_scope) / GET inventory/operations (list+filters) /
GET inventory/operations/{key} (status resolution) — new URIs, view-location, scoped/global semantics TESTED.

## E. Concurrency (genuine two-process, MySQL 8, REPEATABLE-READ, file-barrier rendezvous)

- E1 competing simple receipts: loser blocked 10.1 s on the location lock → 422 occupied; single
  occupant, loser zero. PRE-FIX run demonstrated the defect (mixed live bin) caused by a non-locking
  occupant read under snapshot isolation; fix (FOR UPDATE occupant scan) re-proven by this run. TESTED.
- E2 receipt-vs-transfer: 10.7 s block → 422; source/destination/authority exact. TESTED.
- E3 same key parallel: executor (201) + replayer (200) under true overlap; stock +6 once; 1 ledger row. TESTED.
- E4 sibling variants: 9.5 s block → SKU-labeled 422 (`24:V7` vs `24:V8`); V1/V2/parent exact. TESTED.
Mechanism: location X-lock + locking occupant scan + FK-serialized inserts. SQLite results never claimed.
E5 same-SKU insert race (1062-reload path; theoretical 1213→500 fail-closed): NOT TESTED — conditional note.

## F. Lifecycle regression

Full order path green across the chain (§B): split allocation, reservation, fulfillment, assignment,
picking (incl. variant scan matching), batches, packing/packages, shipments, COD/paid sync, idempotent
sync, digital noop, cancelled noop, cancel-before/after, restore without double-restore. Shipment completion
excludes digital-only lines. Double-restore and resurrection guards hold.

## G. Diff review (final state)

Committed by owner during session (8f84790 + predecessors): all WMS/variant implementation, v3 migration,
routes, keys, resources, audit command, 20+ test files (46-file commit verified by stat; no content I authored
was altered — my hunks confirmed present by grep).
Uncommitted worktree: MY 6 test locale pins + convergence corrections + RbacCompletion 77→78 (all validated
by green runs above); PARALLEL thread's operations-list endpoint + relations + migration down()-hardening +
picking docs + audit command + 3 spec files (reviewed read-only; List/Audit/Gaps tests green).
My scratch: all probe/migration scripts + temp files removed; all 4 scratch DBs dropped. Only docs added by
me: `wms-variant-stage1-2026-10-14.md` (Stage 1) + this report. No user/parallel work reset, unstaged,
reverted, or deleted (the one pre-existing committed scratch file removal is disclosed; nothing of others lost).

## H. Remaining risks and blockers

- H.1 MANDATORY (owner: checkout/OrderFlow, PRE-EXISTING): `test_committed_pending_retry_is_superseded…`
  fails on `catch` and fresh DBs — flow assignment stamps checkout orders `processing`, so the
  `status='pending'` retry lookup is dead and supersede never fires. Candidate owns: lookup-by-state vs
  flow-start-status. MUST NOT be fixed inside WMS scope.
- H.2 MANDATORY (owner: payments/invoice, PRE-EXISTING): gift mark-paid 500s — `DEFAULT_CURRENCY=USD` vs
  MyFatoorah allowlist without USD; plus lock-timeout fallout in later gift tests. Files untouched by
  anyone. MUST NOT be fixed inside WMS scope.
- H.3 FRONTEND: NOT VERIFIED (no repo in scope); §D is the handoff.
- H.4 CONDITIONAL: E5 same-SKU insert race (1213 fail-closed, untested); `reference` LIKE unescaped `%`
  (widens only); migration `down()` best-effort under variant data (documented in-file).
- H.5 INFRA: shared-DB collisions observed (36 setup errors from parallel suites on `catch`); scratch-DB
  pattern used throughout — recommend keeping it.

## I. Gate checklist

Multi-location PASS · occupancy PASS (seq + concurrent) · initial placement PASS · receipts/transfers/
idempotency PASS · assignment PASS · empty PASS · picking/packing/shipment/cancel/restore PASS ·
authorities PASS · RBAC/l10n/compat PASS · migration fresh+upgrade PASS · concurrency E1–E4 PASS ·
full-scope suites PASS except H.1/H.2 (out-of-scope, pre-existing) · frontend NOT VERIFIED.

## J. Files summary (this session, auditor)

Added: `docs/wms-variant-stage1-2026-10-14.md`, this report. Modified and validated: ProductLocationService
(sync variant), StockOperationService (guard consolidation — duplicate removed), ReturnService (SKU restock),
FulfillmentService (assign guard), PickingExecutionService (reallocate guard), both placement resources
(SKU mirrors), 5 lang keys EN+AR, RbacCompletion 77→78, 6 test locale pins + 3 convergence corrections,
3 new regression tests (variant restock, corrupt history, variant assign). All green as listed in §B.
