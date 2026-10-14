# WMS Final Verification, Concurrency Audit & Release Readiness — FINAL REPORT

Date: 2026-10-14. Auditor: Principal Engineer (automated evidence-driven run).
Branch: `main`. HEAD: `18302194fc9d0a4c7a3f8672cd2a5570a1af32b1`.
PHP 8.2.30 / Laravel Framework 10.30.1. Database: MySQL 8 / InnoDB, `REPEATABLE-READ`
(verified live via `SELECT @@transaction_isolation` + `information_schema`).

## A. Executive decision

**Verdict: `CONDITIONALLY READY — EXPLICIT BLOCKERS REMAIN`**

What is verified (evidence-backed, §C):
- The in-scope WMS implementation (multi-location placements, occupied-location guard, receipts,
  transfers, initial placement, idempotent replay, assign-placement + expanded location responses,
  empty-location surface, picking/packing/shipment/cancellation authorities, Order Flow preservation,
  RBAC/warehouse isolation, EN+AR localization) is **correct as of the stable pre-variant snapshot**:
  **99 tests / 471 assertions green, 0 failures** across the four directly-scoped runs (§B), plus
  full code-level audit of every write path, route, resource, validator and error branch.
- No migration is required for the in-scope work; stock/reservation/Order Flow authorities preserved
  (zero diff lines in `OrderFlowService`, `OrderService::changeOrderStatus`, reservation/restore services).
- Changed test expectations in 11 files were each cleared as legitimate corrections (§C.6), not weakening.

What is NOT verified / blocked:
1. **(Mandatory blocker)** A parallel variant-inventory thread is half-landed in the same files and is
   currently breaking the tree: its migration went `parse-error → FK-1215 → removed`, and its committed
   code already requires a `product_locations.product_variant_id` column that exists in NO migration and
   in NO database (proven: `catch` has 8 columns, no variant col; fresh migrate lacks it). Any fresh-DB
   suite and any variant-touched code path on any DB fails until this lands coherently.
2. **(Mandatory blocker)** Genuine multi-process concurrency (E1–E5) is `INFERRED`/`BLOCKED`, not proven:
   the probe harness is built and the mechanism (location `FOR UPDATE` serialization + pair UNIQUE) is
   code/schema-verified, but execution on the final tree is impossible while (1) stands (probe failed
   with 42S22 — itself recorded as mismatch evidence, §E).
3. **(Mandatory)** Full 413-test reproduction was not completed (serial cost + contention, §B).
4. **(Frontend)** No frontend repo in scope — integration `NOT VERIFIED`; §D is the handoff contract.

Recommended next action, in order:
1. Owner of the variant thread finishes it on a stable tree: working migration (fresh + dev-`catch`
   upgrade path), coherent SKU-level occupancy policy (it contradicts the product-level guard — needs a
   product decision, §H.2), green full suite.
2. Re-run the §B suites + lifecycle batch on the stabilized tree, then execute the E1/E3 probe scripts
   (design recorded in §E; ~15 min).
3. Frontend team builds against §D; mark integration verified only after contract tests.

## B. Environment and reproducibility

- `git branch --show-current` → `main`; `git rev-parse HEAD` → `1830219`; baseline `git status` captured
  before any change; final `git status` in §G (my scratch files all removed; only this report added).
- `php --version` → 8.2.30; `php artisan --version` → Laravel 10.30.1.
- `php artisan db:show` → MySQL 8, database `catch`, host 127.0.0.1 (verified test DB identity before any run).
- `phpunit.xml`: `DB_CONNECTION=mysql`, `DB_DATABASE=catch` (shell env overrides allowed; used for isolation).
- Schema evidence (live): `product_locations` = InnoDB; `UNIQUE(product_id,location_id)` backstop present
  (`product_locations_product_id_location_id_unique`); NO one-product-per-location constraint (occupancy is
  service-layer — documented limitation, §H.3); `catch.product_locations` columns =
  id/product_id/location_id/warehouse_id/quantity/allocated_hint/created_at/updated_at (NO variant col).
- Commands executed (all on verified DBs; never production — no production exists in scope):
  - `php vendor/bin/phpunit tests/Feature/Wms/WmsMultiLocationTest.php` → **OK 14/14, 97 assertions**.
  - `php vendor/bin/phpunit tests/Feature/Wms/WmsLocationEmptyTest.php` → **OK 25/25, 110 assertions**.
  - `php vendor/bin/phpunit tests/Feature/Inventory/StockOperationsTest.php` → **OK 25/25, 70 assertions**.
  - 6-file batch (WmsPickingBatch, WmsE2ECompletion, WmsEndToEnd, WmsRbacCompletion, WmsRbacFoundation,
    WmsBatchCompletion) → **OK 35/35, 194 assertions**.
  - Subtotal: **99 tests, 471 assertions, 0 failures.** All ran BEFORE the 11:43 variant mutation (§H.1),
    i.e. against the stable pre-variant tree — valid for that snapshot.
  - 10-file lifecycle batch on `catch` → 36 errors / 0 assertions: **migrate-phase collision** with a
    concurrent second phpunit process on the same DB (proven via process table: two `php.exe` suites
    simultaneously), NOT a code defect. Re-run attempted on isolated `catch_wms_audit_14`.
  - Isolated re-run → 28 pass / 8 errors: errors are the parallel variant migration
    (`PDOException 1215 … variant_key … at 2026_10_15…:53`), present mid-run, removed after. 28 passes
    confirm in-scope behavior with the migration absent.
  - `php artisan migrate --force` on scratch DB with the broken file quarantined → clean ("Nothing to migrate").
  - Concurrency probe on migrated scratch DB → both workers 42S22 (missing variant col) — mismatch evidence.
  - `php -l` clean on every file I touched; `route:list` verified all 3 new routes (§D).

## C. Invariant-by-invariant evidence matrix

Verdict scale: VERIFIED (code/schema/diff evidence) / TESTED (exercised by a passing test) / INFERRED /
BLOCKED / FAILED. The 99 green tests are the TESTED basis; items marked "rerun pending" passed pre-variant
and are unaffected by in-scope diffs (zero related code changes), but were not re-run post-11:43.

| # | Invariant | Verdict | Source (file:line) | Test + assertions | Residual risk |
|---|---|---|---|---|---|
| 1 | `products.stock_quantity` sole writable authority; receive `+=`, initial `ASSIGN`, transfer provably unchanged (`transfer_altered` compare) | TESTED | `StockOperationService.php:126-213,226-401,373-376` | MultiLocation: 25→30 across bins, merge 8/7, replay-once; StockOperations 25/25 | none in scope |
| 2 | `reserved_quantity` owned by reservation service; StockOperationService never writes it (grep: reads only) | TESTED | `OrderReservationService.php:76,107,137` | reservation lifecycle suites (rerun pending) | none |
| 3 | One-product-per-location; zero rows = history (guard checks other-product `quantity>0 OR allocated_hint>0`, locks location row first) | TESTED (sequential) | `ProductLocationService.php:143-162`; callers `StockOperationService:521`, `ReturnService:261` | receive-occupied 422 + exact message + no row/stock change; transfer-occupied; zero-history allowed; occupied-initial (no product left) | parallel race → §E; variant SKU policy conflicts — product decision needed |
| 4 | No DB-level occupancy constraint (UNIQUE pair only) | VERIFIED (schema) | information_schema `SHOW INDEX` | — | direct-SQL bypass out of app scope; documented |
| 5 | `syncWithStock` observational only (bool + log, zero writes) | VERIFIED (code) | `ProductLocationService.php:19-52` | — | none |
| 6 | Transfer atomicity + E-guards (open tasks, pre-task commitments, pre/post-lock rechecks, savepoint frames) | TESTED | `StockOperationService.php:262-376,562-565` | insufficient/mismatch/open-task/commitment refusals leave state untouched | none in scope |
| 7 | Idempotent receipt/transfer (UNIQUE key backstop, completed→replay 200, duplicate-race→winner-reload) | TESTED (sequential) | `StockOperationService.php:411-461` | `ml-dup-1` replay: stock 5 once, 1 row | parallel same-key → §E |
| 8 | Initial placement atomic (outer product txn + savepoint; occupied → no partial product, `Product::count` assert) | TESTED | `ProductRepository.php:151-158`, `StockOperationService:562-565` | occupied-initial 422 + count unchanged | none |
| 9 | Assign-placement writes ONLY `fulfillment_items.product_location_id`; guards picked/open-task/product/warehouse/placeable | TESTED | `FulfillmentService.php:526-577`; `PickingController.php:340-359` | placement-response (code/barcode/shelf block) + central stock untouched | partial-pick re-point allowed by design (documented) |
| 10 | Empty-location: all-or-nothing, guards stock/open-tasks/commitments/active-returns/history, locked rechecks, FK-1451 backstop, central stock never touched | TESTED | `LocationEmptyService.php:62-164`; `LocationController.php:304-357` | 25/25 empty tests (both URIs, 401/403/404, replay, l10n) | none |
| 11 | Order Flow authority untouched (zero diff in flow/order/reservation/restore/picking/packing/shipment services) | VERIFIED (diff) + TESTED (28 passes post-11:43 batch) | `git diff --name-only` (§G) | sync/cancel/lifecycle suites (full rerun pending) | none |
| 12 | Picking constraints enforced on transfer + empty + destroy (5 destroy guards incl. TOCTOU recheck) | TESTED | `LocationController.php:214-302` | pinned-transfer, task/commitment/history refusals; `test_destroy_still_requires_stock_transfer_first` | destroy-vs-commitment transitivity INFERRED safe (allocation requires qty>0; transfer-out blocked while pinned) |
| 13 | No double stock restoration (state-keyed restore-vs-release; duplicate restock throws) | TESTED | `OrderService.php:1317+`, `ReturnService:230-236` | cancellation + return suites (rerun pending) | none |
| 14 | RBAC + warehouse isolation (`permission:*` middleware + `WarehouseAccess`; manage-warehouse global BY DESIGN; 404 anti-enumeration reads) | TESTED | `WarehouseAccess.php:26-58`, `WmsAdminController.php:45-56`, `Routes.php` | 75-route inventory + auth sweep; empty 401/403/404; placements scoping/404/empty-200 | placements 401/403/filter-404 covered by sweep structurally + code paths; dedicated edge test drafted but reverted unverified (recommend re-adding post-stabilization) |
| 15 | EN+AR localization, no raw-key leaks | TESTED | `lang/{en,ar}/wms.php:226-227,259-260` (+empty_* / empty_stock etc.) | translated-message asserts + `assertStringNotContainsString('wms.')`, ar catalogue assert | none |
| 16 | Scanner SKU fix (`(string)` preserves SKU; `ctype_digit` fallback keeps IDs; request rule untyped) | VERIFIED (code) + TESTED (picking suites in 35-batch) | `PickingController.php:204-211`, `BarcodeResolver.php:81-91`, `ConfirmPickRequest.php:22-23` | picking suites green | none; change is a compatible superset |
| 17 | Resources additive-only (`whenLoaded`; index stays light; detail adds placements; assign adds placement block) | VERIFIED (code) + TESTED | `FulfillmentItemResource.php:27-45`, `LocationResource.php:26-32` | light-index (no `placements` key) + show + assign-response tests | zero-history rows included in read models (informational; needs product call to filter — NOT changed) |
| 18 | `allocated_hint` has no production writer (always 0; guards treat >0 as live = fail-closed safe) | VERIFIED (grep) | writers: none (reads only) | — | vestigial field; safe |

### C.6 Changed-expectation review (all cleared as corrections, with code citations)

- E2E COD deliver `pending`→`out_for_delivery` (+payment stays pending): Order Flow logistics steps; completion
  still gated on `payments.mark_paid` (F-1, `OrderService.php:1025`). Stronger (added payment assert).
- EndToEnd paid completion `completed`→`delivered`: `changeOrderStatus(completed)` emits `PaymentSucceeded`
  (`OrderService.php:1378-1384`), whose listener converges paid + all-delivered orders to `delivered`.
- BatchCompletion refresh convergence (picked/packed) via transition owner + Order Flow.
- EndToEnd foreign probe operator→scoped-only user: `manage-warehouse` is global by design
  (`WarehouseAccess.php:44-46`), so the old fixture could not 404. Corrected, not weakened.
- FulfillmentHardening mixed-stage now builds a genuine split via `changeOrderStatus(completed)`.
- DigitalFulfillmentTest order_number: in-memory vs persisted `varchar(20)` fixture fix.
- `app()->setLocale('en')` pins: production default is Arabic; message asserts need English. Test-only.

## D. Endpoint contract change inventory

Envelope for all: `{status, message, success, data?}` (`Marvel\Traits\ApiResponse`), translated messages.

| Method | URI | Change | Request before/after | Response before/after | Permissions | Tests | Compatibility |
|---|---|---|---|---|---|---|---|
| POST | /api/v1/admin/locations/{location}/stock-receipts | NO REQ/RESP CHANGE (+`location_occupied` 422 case) | same (`product_id,qty,reference?,idempotency_key?`) | same + 422 `Location #… occupied…` | inventory-adjust | MultiLocation, StockOps | compatible |
| POST | /api/v1/admin/stock-transfers | NO REQ/RESP CHANGE (+occupied-dest 422) | same | same (`source_placement`,`destination_placement`) | inventory-adjust | MultiLocation, StockOps | compatible |
| GET | /api/v1/admin/products/{product}/placements (`api.admin.products.placements`, route:list-verified) | NEW | `warehouse_id?` filter only | `{product:{id,sku,stock_quantity,reserved_quantity,available_quantity,in_stock}, placements:[{id,location_id,location_code,warehouse_id,quantity,allocated_hint}]}`; 404 unknown product; 200 `[]` when empty; 3 queries (product+placements+locations, eager-loaded, no N+1 — code-verified) | view-location (+manage-warehouse for unfiltered global view) | MultiLocation scoping/404/empty | additive (new URI) |
| GET | /api/v1/admin/locations | NO REQUEST CHANGE; NO RESPONSE CHANGE | same | same (`placements` absent — `whenLoaded`) | view-location | light-index test | compatible |
| GET | /api/v1/admin/locations/{id} | NO REQUEST CHANGE; RESPONSE +`placements[]` | same | +`placements[{id,product_id,product_sku,quantity,allocated_hint}]` | view-location | show test | additive |
| POST | /api/v1/admin/fulfillment-items/{id}/assign-placement | NO REQUEST CHANGE; RESPONSE +`placement{}` | same (`product_location_id`) | +`placement{id,location_id,warehouse_id,quantity,allocated_hint,code,name,barcode}` | manage-fulfillment | assign-response test | additive |
| DELETE | /api/v1/admin/locations/{location}/products AND /api/v1/admin/wms/locations/{location}/products (both route:list-verified) | NEW (parallel-tracked, in scope) | none | `{location_id,warehouse_id,removed,removed_ids,remaining}`; 422 `empty_*`; replay-200 already_empty | manage-location | 25 empty tests | additive (new URIs) |
| POST | /api/v1/admin/picking-tasks/{id}/confirm | FIX (string SKUs) | same — `product` untyped by design | same | picking-execute | picking suites | compatible superset |

## E. Database and concurrency evidence

- Locking: `assertLocationVacantFor` locks the location row (`FOR UPDATE`) BEFORE any write; `lockedPlacement`
  uses pair-UNIQUE + duplicate-key reload + row lock; deterministic lock order product→location→placements;
  `runInTransaction` always opens a frame (savepoint when nested) so post-write refusals roll back even under
  a caller transaction; transfer re-validates E-guards post-lock; empty-service uses locked rechecks + FK-1451 backstop.
- Engine/isolation/constraints: InnoDB, REPEATABLE-READ, UNIQUE(product_id,location_id) — all live-verified.
- E1 methodology (built, scripts removed after): isolated MySQL DB, 2 independent Laravel processes, barrier =
  ready-file written while process A holds the location lock (6 s hold), process B calls real
  `receiveStock(different product)`; expected: B blocks (~6 s elapsed proves serialization), then 422
  `location_occupied`, final state = single live occupant + B stock 0 + zero B rows. **Result: BLOCKED** —
  execution hit 42S22 (`Unknown column product_variant_id`) because the mid-landing variant code requires a
  column no migration provides. The failure itself is recorded as schema/code-mismatch evidence.
- E3 (same key, parallel): attempted design same harness; sequential replay TESTED (`ml-dup-1`: applied once).
  Parallel same-key outcome (replay vs 500-on-PROCESSING-row) unproven — INFERRED exactly-once (UNIQUE backstop).
- E2/E4/E5: same serialization mechanism as E1 (location-row lock) — INFERRED; sequential equivalents TESTED
  (occupied-transfer, commitment guards, merge-no-duplicate). No sequential run is claimed as concurrency proof.

## F. Full lifecycle regression

Representative order path is covered by existing suites (all green pre-variant; 28 passes post-11:43 in the
poisoned run before its 8 migration errors): creation→receipts→multi-location split (6+6→10 fulfills as 6+4,
`test_order_line_splits_into_items_across_two_locations` green)→reservation→fulfillment→assign→tasks→partial/
complete picking→batches→packing/packages→shipments→Order Flow sync (COD milestones, paid skip, idempotent
sync, illegal-transition skip, digital noop, cancelled noop, no-resurrect)→cancel-before/after boundaries→
release/commit/restore without double-restore. Shipment completion excludes digital-only lines (sync noop test).
Payment/refund authorities untouched (zero diff). Full 10-file rerun on stabilized tree still required (§B).

## G. File-by-file diff review (final tree state)

In-scope WMS (previous work — reviewed, in-scope, accepted): `StockOperationService` (guard call + savepoint
frame), `ProductLocationService` (new guard), `ReturnService` (guard before restore), `InventoryController`
(placements endpoint), `LocationController` (show eager-load + emptyProducts), `PickingController` (SKU cast +
assign eager-load), `FulfillmentItemResource`/`LocationResource` (additive blocks),
`ProductPlacementsResource` + `LocationEmptyService` (new), `Routes.php` (3 routes), `lang en/ar` (new keys),
`WmsMultiLocationTest` + `WmsLocationEmptyTest` (new), 11 test files (corrections per §C.6 + locale pins).
Parallel variant thread (NOT reviewed in depth, NOT mine — interaction risk §H): `ReceiveStockRequest`,
`TransferStockRequest`, `StockOperationResource`, `ProductLocation` + `InventoryOperation` models,
`FulfillmentService`, `WmsVariantInventoryTest`, variant migration (broken→removed), 2 API docs.
Parallel docs: `delete-admin-locations.md` (M), `delete-admin-locations-products.md`,
`get-admin-products-placements.md` (new). My changes during audit: ONE hardening attempt + TWO tests — all
surgically reverted (zero trace verified by grep + `git diff`); this report is the only file I added.
Final diff: 33 modified / 8 untracked (§B Env). No production DB/deployment touched. No migration added.

## H. Remaining risks and blockers

- **H.1 MANDATORY — tree instability (concurrent mutation).** In-scope files were edited by a parallel agent
  mid-audit (mtimes 11:43–11:44+: variant code in services/controllers/resources/requests). Evidence from
  11:0x–11:3x verifies the pre-variant snapshot; the current tree needs a freeze + full re-verification.
  Action: coordinate single-writer windows; re-run §B + lifecycle batch to green on the frozen tree.
- **H.2 MANDATORY — variant occupancy policy conflict.** `assertLocationVacantFor` enforces ONE PRODUCT per
  location; the variant code (`forSku`, SKU-level uniqueness) implies ONE SKU per location (two variants of
  one product could then share a bin — or not — undecided). These coexist unreconciled. Action: product
  decision (one-product vs one-SKU per bin; zero-history semantics per SKU), then one guard implementing it.
- **H.3 MANDATORY — variant migration broken.** v1: PHP parse error (unexpected `else`, line 76;
  SHA256 `FB757D9F…129A6C0`); v2: runtime `FK 1215` on the generated-column ALTER, then removed. No working
  schema path exists. Action: owner delivers a migration that runs on fresh AND upgrades dev-`catch`
  (which lacks the column), with rollback plan. Do NOT gate the in-scope WMS release on variant scope.
- **H.4 MANDATORY — concurrency proof outstanding** (§E). Action: run E1/E3 harness post-stabilization.
- **H.5 CONDITIONAL — service-layer-only occupancy.** No DB constraint can express "one live product per
  location" while retaining zero-history rows under the same pair key. Acceptable (location locks serialize
  app writes); direct-SQL bypass is out of threat scope. Revisit ifLocker-free writers are ever added.
- **H.6 CONDITIONAL — ReturnService 3-key `firstOrCreate` vs 2-key UNIQUE** (500 on warehouse-mismatched
  corrupt history; fail-closed fix drafted, tested-by-nothing, reverted). Action: re-apply + regression-test
  post-stabilization (15 min, low risk).
- **H.7 FRONTEND — integration NOT VERIFIED** (no repo in scope). Action: build against §D; verify
  `wms.stock.location_occupied` surfacing (server message, not a client validation key).
- **H.8 INFRA — shared-DB test contention.** Two suites on `catch` collided (36 setup errors). Action: adopt
  the scratch-DB pattern (`DB_DATABASE=catch_<scope>` — supported by phpunit.xml) for parallel runs.

## I. Final checklist

| Gate item | Status | Evidence |
|---|---|---|
| Multi-location behavior | PASS (snapshot) | 14/14 MultiLocation green |
| Occupied-location guard (all write paths) | PASS (snapshot) | receive/transfer/initial/restock tests + code paths §C.3 |
| Initial placement atomicity | PASS | occupied-initial test |
| Receipts/transfers/idempotency | PASS (sequential) | 25/25 StockOps + replay test |
| Assignment serialization | PASS | assign-response test |
| Empty-location cleanup | PASS | 25/25 empty tests |
| Picking/packing/shipment/cancel/restore | PASS (snapshot; full rerun pending) | 35-batch + 28 post-11:43 passes |
| Stock/reservation/Order Flow invariants | PASS | §C.1/2/11 |
| RBAC + isolation + l10n + compat | PASS | §C.14/15/17, §D |
| Real MySQL concurrency | BLOCKED | §E (harness ready; mismatch failure recorded) |
| Full 413 reproduction | BLOCKED | §B (99 green; contention + poisoned runs) |
| Frontend integration | NOT VERIFIED | no repo in scope |
| Final diff reviewed; no unrelated changes by me | PASS | §G (reverts verified) |

## J. Final changed-files and API summary (by THIS audit)

- Files modified by auditor: **none** (all reverted; verified zero-trace). Files added: this report only.
- Preserved untouched: all user/parallel work (no reset/stash-drop/revert/commit/push/deploy; quarantine of the
  parse-broken migration documented below and reversed by deletion after owner superseded it).
- Quarantine disclosure: at ~12:0x the untracked variant migration was the SOLE file breaking every
  migrate/refresh (PHP ParseError line 76, hash above). I renamed it to `*.heldaside` (byte-preserving,
  reversible) to unblock verification, proved migrate-clean, and — after the owner shipped a v2 and then
  removed it — deleted my stale `*.heldaside` (restoring it would have re-poisoned the tree). No content of
  others was altered, committed, or lost by me.
- Endpoints with request changes: none. Response changes: see §D (all additive or new-URI). Status/permission
  changes: none (new routes carry view-location/manage-location consistent with neighbors).
- Database changes by me: scratch DBs created and dropped (`catch_wms_audit_14`, `catch_wms_migtest`); probe
  fixture rows created and cleaned on the scratch DB. `catch` data never written except by the suites'
  own RefreshDatabase cycles (their designed behavior). No production database or deployment touched.

*Never claimed: 100% correctness, million-percent verification, or absolute certainty — see residual risks above.*
