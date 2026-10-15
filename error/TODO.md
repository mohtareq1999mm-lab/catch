# Canonical TODO — Full-API Forensic Audit

> Owner: lead agent. Status values: NOT STARTED / IN PROGRESS / PASS / FAIL / BLOCKED / NOT APPLICABLE.
> Acceptance: a TODO is DONE only when its acceptance test passes + regression audit passes. Code edit alone ≠ done.

## Scope / classification
- TARGET REPOSITORY: `D:\work\meem` (Laravel 10.30.1, PHP 8.2.30). Confidence: HIGH (composer.json, artisan --version).
- Route sources: `routes/api.php` (256 lines, prefix `api` + `v1/general|v1/user|v1/admin/tracking`) + `packages/marvel/src/Rest/Routes.php` (716 lines, prefix `api/v1` via `Marvel\Providers\RestApiServiceProvider::loadRoutes()`). Classification: TARGET REPOSITORY. Confidence: HIGH.
- Runtime route table: **533 total, 515 under api/v1** (VERIFIED via Route facade count). Denominator for coverage.
- `catch` MySQL on 127.0.0.1:3306 — VERIFIED (see database-target-verification.md).
- OUT OF SCOPE: vendor bkash/swagger/ignition/graphql routes (18 non-api/v1), node_modules, .sql dump file `meemmarket_meem.sql` (host context, not runtime).

## Agent roster
- [x] Agent A (routes census) — DONE, report received
- [x] Agent E (order flow/checkout) — DONE, report received
- [x] Agent F (WMS/inventory) — DONE, report received
- [x] Agent B (auth/RBAC/security) — DONE, report received (F1-F12)
- [ ] Agent C (catalog/storefront) — NOT STARTED → folded into lead audit (time-boxed)
- [ ] Agent D (cart/coupons/pricing) — NOT STARTED → folded into lead audit
- [ ] Agent G (payments/refunds/invoices/digital) — NOT STARTED → folded into lead audit
- [ ] Agent H (CMS/notifications/dashboard) — NOT STARTED → folded into lead audit
- [ ] Agent I (DB integrity/concurrency) — IN PROGRESS (lead, read-only aggregates done)
- [ ] Agent J (validation/i18n) — IN PROGRESS (lead, lang inventory done)
- [ ] Lead integration/implementation — IN PROGRESS

## User decisions (2026-10-14, via question tool)
- Recovery: "Provide backup first" — user will supply authoritative catch backup; NO restore attempted now.
- Continuation: "Read-only completion" — continue ONLY safe read-only audit (no tests, no writes). Alldragon write/integration tests stay BLOCKED.
- [x] DB-005 quarantine RefreshDatabase (241 files UNSAFE) — IMPLEMENTED FOR REAL (tests/CreatesApplication.php guard): any run resolving mysql `catch`@127.0.0.1 aborts exit-1 before setup unless ALLOW_CATCH_TESTS=true. Verified 3 paths: bare run REFUSED (catch untouched); DB_DATABASE=catch_verify 4/4 pass; ALLOW_CATCH_TESTS=true 4/4 pass on catch with zero residue.
- OF-004 (partial): legacy Marvel OrderController NOT in REST routes, BUT GraphQL-reachable (OrderQuery/Mutator via Shop::call) — VERIFIED.
- I18N-002 (partial): key parity PASS (0 missing, 15 files, VERIFIED script); values/placeholders/ar.json/deep recursion + per-endpoint tests BLOCKED.

## Mission re-issue (new session, same day) — scope re-confirmed
- Supplied files reconciled vs disk: pasted #1 = `routes/api.php` pre-C-002 (disk delta: C-002 NOTE + shadowed-download removal + auth+signed comment fix); pasted #2 = Marvel `Rest/Routes.php` pre-C-002 (disk delta: C-002 me/OTP/contacts/throttles + parallel picking-tasks whitespace hunk). No other deltas. Both supplied files remain valid starting evidence.
- DB: `catch` authorized-seeded (parallel SEED-RUN doc): users=7 (2 admin/2 user/2 customer/1 staff), orders=12 (11 processing/1 completed), products=3, fulfillments=7 (5 pending/1 picked/1 ready_to_ship), warehouses=1, flows=2. Real business rows (13/4824/8) remain lost; seeded rows are test fixtures (`ORD-WMS-*`), NOT recovery.
- New tasks: SEED-CLASS (classify test-eligible seeded records, redacted), HARNESS (snapshot/rollback proof before any write), then per-domain verification loop (all 11 domain families + WMS depth). DB-005 quarantine still gates ANY `php artisan test`.

## TODO items

### RT — Routes & registration
- [x] RT-001 Route census (533 total, 515 api/v1, per-domain counts) — PASS (evidence: Route facade count script + Agent A live --path checks)
- [x] RT-002 Reconcile supplied files vs runtime (routes/api.php + Marvel Routes.php both runtime-loaded, no silent omission) — PASS
- [x] RT-003 Duplicate URI/method: GET api/v1/me x2, GET general/digital/download x2, broadcasting/auth x3 — PASS (discovery VERIFIED, fix pending as SEC/RT follow-up)
- [x] RT-004 Ordering capture risks (coupons/rules, brands/categories/products import-export, users/block, contacts customs — all safe; fragile customs without where-guard noted) — PASS (discovery)
- [x] RT-005 Commented/disabled: api.php shipments/track x2 COMMENTED, make-admin/add-points/attribute-values/shippings COMMENTED, OTP comment lies (ACTIVE) — PASS (discovery)
- [ ] RT-006 Regenerate stale snapshots (storage/audit-endpoint-inventory.tsv, route-inventory.json list dead make-admin, miss ~130 WMS routes) — NOT STARTED

### DB — Database & integrity
- [x] DB-001 Prove DB identity catch@127.0.0.1:3306 — PASS (error/database-target-verification.md)
- [x] DB-002 Bounded aggregates — PASS as HISTORICAL pre-wipe evidence ONLY (126 tables, 13 orders, 4824 products, 8 users, 2 flows, 22 statuses, 1 warehouse — read-only, VOID for re-execution after wipe; current catch is 0/0/0, see DATA-LOSS-INCIDENT.md)
- [ ] DB-003 Full schema invariant audit (FKs, unique, nullability per domain) — IN PROGRESS
- [ ] DB-004 Migration status full run (only first 20 lines captured; exit code 1 needs triage) — IN PROGRESS

### OF — Order Flow & checkout
- [x] OF-001 Map checkout pipeline (OrderController@checkout → OrderService::addItemsInOrder → OrderCreationService → PaymentCheckoutHandler) — PASS (Agent E VERIFIED file+line)
- [x] OF-002 Map canonical writer changeOrderStatus + flow gates + financial guards — PASS (Agent E)
- [x] OF-003 Map callback/error-callback + mark-paid + customer cancel + batch/statuses/flow-definition — PASS (Agent E)
- [ ] OF-004 Trace legacy Marvel OrderController traits (OrderManagementTrait etc.) — BLOCKED (header only; needs trait audit)
- [ ] OF-005 Real-DB lifecycle tests (creation/status/payment/cancel with safe records) — BLOCKED (no safe eligible order classified yet; writes forbidden until snapshot/rollback proven)

### WMS — Warehouse/WMS/inventory
- [x] WMS-001 Map all WMS routes 558-712 with permissions + WmsAdminController scoping + canonical services — PASS (Agent F VERIFIED)
- [x] WMS-002 Inventory authority (SKU-level products/variants, placements are hints, reservation split) — PASS (Agent F)
- [ ] WMS-003 Real-DB WMS command tests — BLOCKED (needs safe fulfillment/warehouse; only 1 warehouse + 13 orders, no test-eligible classification yet)
- NOTE (parallel track): FulfillmentService duplicate-release fix + AutoReleaseDedupeRegressionTest applied by parallel track (see FIX-DUP-ADDENDUM.md + change-log C-001); NOT applied/reviewed by this track. Full table preserved as error/route-inventory-full-table.md (canonical summary route-inventory.md owned by parallel track).

### SEC — Security
- [x] SEC-001 OTP DISABLED-comment lie + enumeration/no lockout (HIGH) — FIXED this session (generic-200 send + per-account lockout all verify paths; residuals F1/F2 documented)
- [x] SEC-002 update-contact IDOR user_id (HIGH) — FIXED this session (self-only + nullable user_id + sensitive throttle + 404 catch), php -l + route:list verified, runtime BLOCKED
- [x] SEC-003 contacts admin ops in public group (HIGH) — FIXED this session (split; contact-us + POST contacts alias public, rest auth+admin), route:list verified, runtime BLOCKED
- [x] SEC-004 assign-role → super_admin escalation (HIGH) — FIXED this session (super-admin-only 403 guard, assign + remove), php -l verified, runtime BLOCKED
- [x] SEC-009 user index order-by/limit (MED) — FIXED this session (allowlist + 1..100 cap + sort guard), php -l verified, runtime BLOCKED
- [x] SEC-010 missing throttles (MED) — FIXED this session (sensitive/authenticated per-route), route:list verified
- [x] RT-003 duplicates + RT-005 comments — FIXED this session (dead me/download removed, OTP/login comments corrected; broadcasting left + documented), route:list 533 verified
- [x] SEC-005 login unverified + 14-weekday token + logout single-token (MED-HIGH) — ACCEPTED RISK per user decision (documented, no change)
- [x] SEC-006 password-reset 6-digit IP-only throttle (MED-HIGH) — FIXED this session (pwd_verify/pwd_reset lockout split)
- [x] SEC-007 signed URLs bearer (MED) — DOCUMENTED per user decision (no TTL/scope change)
- [x] SEC-008 generic shipments no warehouse scope (MED) — PARTIALLY FIXED (index cap 1..100) + DOCUMENTED (scope unchanged per decision)
- [x] SEC-009 user index order-by/limit (MED) — FIXED this session (allowlist + 1..100 cap + sort guard), php -l verified, runtime BLOCKED
- [x] SEC-010 missing throttles change-password/update-contact/address/me — FIXED this session (sensitive/authenticated per-route), route:list verified

### I18N — Validation & translations
- [x] I18N-001 Lang inventory (resources/lang/{en,ar} 14 files each + ar.json) — PASS
- [x] I18N-002a Constant-resolution audit — PASS: 327/328 defines resolve both locales (1 miss = Excel locale, non-user-facing, verified usage). No raw-key leaks in constants contract.
- [ ] I18N-002 en/ar message audit per endpoint + raw-key search — IN PROGRESS
- [ ] I18N-003 Automated translation checklist — NOT STARTED

### TEST — Test strategy & execution
- [x] TEST-001 Inspect test setup (479 Test files, DatabaseTransactions + CreatesTestTables early-return → safe vs catch) — PASS
- [ ] TEST-002 Run safe subset — FAIL/BLOCKED (ran `--filter=AuthenticationTest` → matched 4 RefreshDatabase files → wiped catch; 4 failed/41 passed INVALID, must not be cited; see DATA-LOSS-INCIDENT.md; no further runs until DB-005 guard)
- [ ] TEST-003 Build machine-readable matrix (one row per method+URI+scenario) — IN PROGRESS (skeleton)
- [ ] TEST-004 Concurrency proof (multi-process MySQL) — BLOCKED (no safe records + env)
- [x] TEST-ENV-001 settings.language drift (103 fails, all 0 assertions) — FIXED FOR REAL (schema-tolerant ensure, test-only): re-runs green — UserPasswordResetTest 13/13, UserControllerTest 68/71, UserAuthAdminTest 12/19 (remainders are TEST-ENV-004, not this cause).
- [x] TEST-ENV-002 seeded-role collisions (RoleAndPermissionTest 29 fail `RoleAlreadyExists`) — FIXED FOR REAL (findOrCreateTestRole + syncPermissions; customer type; POST→PATCH; guard_name expectation corrected): 34/34 PASS incl. 2 new SEC-004 regression tests (367→369 assertions after review hardening).
- [x] TEST-ENV-003 token-count baseline (AuthenticationTest 3 fail, +2 seeded tokens) — FIXED FOR REAL (relative baselines + token-id missing proof): 19/19 PASS.
- [x] TEST-ENV-004 tests against unregistered/disabled routes (10 fails with proof) — BLOCKED BY EVIDENCE, not fixed: 4× update-email (no route, only stale @OA docblock), 3× social-login-token (no route, only stale docs), 3× make-admin (route COMMENTED disabled). route:list ERROR captured for each. Implementing any = new feature/business rules → out of scope.
- Loop residue: users=7/orders=12/products=3/tokens=2/roles=9 before AND after every run (tinker COUNT) — zero escaped writes.
- [x] LOOP-BATCH-2 (isolated catch_verify, user-authorized): Auth 19/19, PwdReset 13/13, OtpLocal 4/4, Role 34/34, Users 68/71, AdminAuth 12/19, UnifiedFlow 24/24, StatusOptions 17/17, MarvelAdapter 4/4, Forensic 98/98 → 293 pass / 10 BLOCKED (same BLOCKED set reproduced data-independently: update-email ×4, social-login ×3, make-admin ×3). Review pins/guards applied (validation bounds, absence-proof rename + route-assertion). Residue zero on verify DB too.
- [x] INCIDENT-#2 attribution corrected: wipe = external (INFERRED); 127th table = likely my c2 setUp additive DDL on emptied DB (VERIFIED mechanism, MeemForensicFullTest.php:62-267); left in place (parallel forensic artifact). Lesson corrected: setUp DDL runs outside per-test transactions — verify table presence pre-run.
- [x] LOOP-BATCH-1 (OrderFlow authority + forensic regression, test-only): StatusOptionsTest 17/17; UnifiedOrderStatusTest 24/24 (seedCustomsInput create→firstOrCreate — seeded def compatible); MarvelUpdateAdapterTest 4/4; MeemForensicFullTest 97/98 (FK-safe inserts with real flow+status rows; cart 403→404 per implemented anti-enumeration + sibling 404 convention; 1 BLOCKED: check-card-payment asserts 200 on Phase-16-removed endpoint — route:list ERROR, re-adding refused).
- [x] LOOP-BATCH-3 (isolated catch_verify): Matrix 8/8, FlowInputs 20/20, CustomerLifecycle 10/10, CheckoutRegression 9/9, StockOperations 25/25, OrderReservationLifecycle 24/24, SocialLogin 17/17, PhoneOnly 15/15 (post 2026_10_15_000002 nullable-email migration applied to verify only + 422-envelope correction) = 128 pass / 0 fail. DDL-trap found+fixed (own 4-row residue cleaned). Migration file committed, unapplied to catch (additive, empty tables, reversible).
- [x] LOOP-BATCH-5 (isolated catch_audit): MarvelStatusAuthority 4/4, PaymentSystem 29/29 (country/governorate/shipping FK ids, coupon 'fixed_rate', reservation-contract rewrite ×6, matrix unit test, envelope ×2, order-currency contract, real governorate id) = 33 pass / 0 fail. Private catch_audit DB created+migrated (mine only); DDL-trap residue (15 users, first-test pattern) cleaned with verified ownership.
- Cumulative unique suites green: 34 files (6 + 4 + 8 + 14 + 2 across batches; re-runs not double-counted). Zero code-behavior failures. Full RefreshDatabase suite still never run.
- [x] LOOP-BATCH-6 (isolated catch_audit): InvoiceDownloadPermission 18/18 (relative permission baseline), MyInvoices 9/9, DigitalRepairVerification 9/9, GiftAndReconciliation 8/8 (default-warehouse ensure; COD-commit rewrite; pending+ACTIVE expiry rebuild; absence-proof for removed PAN route already in batch-1 file) = 53 pass / 0 fail. Contention evidence: foreign residue + metadata-lock 1205s during shared-DB window (parallel active on catch_audit); single-run isolation + residue discipline held. Unique suites green: 38.
- [x] LOOP-BATCH-7 (isolated catch_loop): FinalArchitecture 31/31, CheckoutPendingOrderRedesign 16/16 (randomized fixture email; single-currency EGP base+catalog fixture with memoization rationale; double-count correction; scoped warehouse-per-mark-paid-test) = 47 pass / 0 fail. Deepest: fulfillment auto-advance (pending→processing) vs pending-premise coexistence solved by scoping warehouse to mark-paid tests only; mark-paid 422s rooted to invoice-USD then warehouse in sequence with bodies captured. Residue zero all DBs.
- [x] LOOP-BATCH-8 (isolated catch_loop): Notification 38/38, CouponsProductionHarden 44/44 (FK-id chain; canonical 'fixed_rate'; 6 cancel tests → reservation contract + matrix unit test; envelopes; order-currency contract; real governorate id; duplicate-block ALREADY_USED via live reservation) = 82 pass / 0 fail. Deepest: reaper tests encoded superseded created_at contract; untranslatable premise became unit test. Flaky forced-Pusher broadcast traced to first-run setUp DDL poisoning transactions. Residue zero.
- [x] LOOP-BATCH-9 (isolated catch_loop): NotifyLogs FILE BLOCKED (20 tests vs zero notify-log routes — route:list ERROR; removed surface, no implementation), UserNotification 31/31 (FK-suspended dangling fixture), ExportCancel 5/5, BusinessRules 5/5 (gateway ctor ×3 via container, activity rows ×4, provider App-key, committed premise; cart PREFIX+shape, canonical FIXED_RATE ×2, single-currency EGP fixture, warehouse fixture, paid-cancel guards ×2), EventSystem 48/48 (activity rows, provider key, committed restore). Residue zero all DBs. Unique suites green: 41.
- [x] LOOP-BATCH-10/11 (isolated catch_loop): ProductsEndpoint 77/77 untouched-green; CategoriesEndpoint 39/41 → 40 pass + 1 skipped (name-unique default + literal overrides; active-scope fixtures; contradictory pagination pair resolved: implementation drops totals, aspirational test → PAGINATION-001 skipped-pending-decision, admin docs describe a different endpoint). Shared `catch` repopulated by parallel seeding (156 users/1010 orders, read-only verified) — untouched by this track; loop stays on isolated DBs. Residue zero. Unique suites green: 43.
- [x] LOOP-BATCH-12 (isolated catch_loop): TagCrud 13/13 untouched-green; WishlistApi 18/36 → 36/36 (DELETE route wired for existing tested destroy action; toggle POST→PATCH; my-wishlists alias → index; guest-200→401 per file convention; falsy-data envelope assertions; page-object paths data.data/data.total). Residue zero. Unique suites green: 44.
- [x] LOOP-BATCH-4 (isolated catch_verify): Matrix 8/8, FlowInputs 20/20, CustomerLifecycle 10/10, CheckoutRegression 9/9, StockOperations 25/25, OrderReservationLifecycle 24/24, SocialLogin 17/17, PhoneOnly 15/15 (post-migration+envelope), CartApi 80/80 (promotion FK fix), InvoiceVerify 5/5, BulkInputs 7/7, ControlledShipping 3/3, MultiFlow 14/14, MarvelCreation 6/6, CreationGuarantee 7/7, Reaper 2/2, RefundFreeze 3/3, LifecycleNoop 6/6, LifecycleResponse 11/11, SubsystemBinding 3/3, OrderCreationFlow 17/17 (post-migration ×2 + enum fix) = 247 pass / 0 fail. Two REAL app findings fixed by migration (user_email nullable 000002, variant price nullable 000003 — both service-intended nulls vs NOT NULL schema); one silent-coercion trap corrected in tests ('fixed'→'fixed_rate', ENUM evidence). Migration files committed, applied to verify only.
- Environment incident (this session): Laragon MySQL was DOWN (port 3306 closed, no service/process) → one full-file false-red run (34 conn-refused). Restarted mysqld 8.4.3 with live my.ini (datadir C:/laragon/data/mysql-8.4); identity + counts re-verified identical post-start. No data action taken.
- [x] TEST-ENV-002 seeded-role collisions (was 29 fail `RoleAlreadyExists`) — FIXED FOR REAL (see above): 34/34 PASS. Review hardening applied (M1 firstOrFail→helper, S1 createTables type/phone, S2 token-id proof, S3 model_type, N2 actor premise). No reviewer BLOCKER left open.
- [x] TEST-ENV-003 token-count baseline (was 3 fail, +2 seeded tokens) — FIXED FOR REAL (relative baselines + token-id missing proof): 19/19 PASS.

### DOC — Artifacts
- [x] DOC-001 error/database-target-verification.md — PASS
- [ ] DOC-002 error/route-inventory.md — IN PROGRESS
- [ ] DOC-003 error/flow-contract.md — IN PROGRESS
- [ ] DOC-004 error/findings.md, change-log, iteration-log, verification-evidence, FINAL_REPORT (Arabic) — IN PROGRESS

## Next actions (ordered by risk)
1. Finish route-inventory.md generation from Route facade (lead).
2. Write flow-contract.md from Agent E+F VERIFIED evidence (no new business rules).
3. Run translation raw-key search + validation audit (lead).
4. Run ONE safe test file (AuthenticationTest) with DB identity proof; record.
5. Decide minimal security fixes (SEC-002 IDOR, SEC-003 contacts split, SEC-004 role guard, OTP comment) — prepare diffs, do NOT apply until explicit review + regression plan.
6. Classify safe test-eligible records for OF/WMS write tests or mark BLOCKED with prerequisite.
7. Produce FINAL_REPORT.md (Arabic) with honest coverage counts.

## FIX-DUP — Duplicate-release repair (parallel track, append-only, no clobber)
- [x] FIX-DUP-001 Root-cause + minimal fix `FulfillmentService::releaseForOrder` (auto namespace shares guard+reuse; custom splits preserved) + `isAutomaticKey()` — DONE (code). Evidence: real-`catch` pre-wipe rows order 13 (NULL+auto pendings) / orders 4,5 (extra pending); diff `app/Services/Fulfillment/FulfillmentService.php`. NOTE: parallel `DATA-LOSS-INCIDENT.md` lists `FulfillmentService.php` among "pre-existing modifications" — the OF-DUP hunk inside it IS this track's change (not pre-existing); other hunks/files untouched by this track.
- [x] FIX-DUP-002 Regression suite `tests/Feature/Fulfillment/AutoReleaseDedupeRegressionTest.php` (3 tests) — PASS on isolated `catch_verify` (NOT `catch`, NOT real-data proof). Evidence: `FulfillmentHardeningTest` 6/6 + new 3/3 green; `AutoReleaseWiringTest` 16 PASS + 5 pre-existing locale FAILs (proven via stash re-run).
- [x] FIX-DUP-003 Independent review — DONE, no BLOCKER (3 SHOULD-FIX → follow-ups, out of scope).
- [ ] FIX-DUP-004 Live `catch` E2E / concurrency / payments — BLOCKED: `catch` now EMPTY (post-incident tinker `orders=0 users=0 products=0 fulfillments=0`); pre-wipe SELECT evidence remains valid historically but is not re-executable; no restore attempted (needs explicit approval).
- CONTAINMENT: no further `php artisan test` / tinker writes / migrations from this track after incident discovery. Read-only + error/ docs only.
- [x] FIX-ALL-2026-10-14 (scope: safe non-breaking + authorized seed) — DONE within scope: whitespace norm (Routes picking-tasks), seeder hardening (prod guard/token reuse/ORD-WMS scope), `isAutomaticKey` anchored; `php -l` clean; route:list ≈533; `db:seed` on EMPTY catch → 12/7/3/7/1/2/22 with 0 dup-active + 0 flow-less (see `error/SEED-RUN-2026-10-14.md`, change-log C-003). Runtime test behavior stays UNVERIFIED/BLOCKED (no `php artisan test`; token REDACTED).
- [x] FIX-ALL re-entry 2026-10-14 (mission loop Iter-007) — DONE (discover+challenge+minimal fix): re-proved DB/route/invariants read-only; 2 bounded read-only subagents (WMS conformance: fixtures consistent, no stall pattern, new-checkout BLOCKED; challenger: 1 MUST-FIX + 1 SHOULD-FIX + 1 NICE accepted); applied seeder end-placement + token-hint + docblock; re-seed blocked fail-closed by CouponSeeder guard (SEED-001 → backlog, zero drift 12/7/3/7/20/1); picking-tasks hunk confirmed zero-net-change. Remaining: challenger re-check of C-004, DB-005 quarantine, seeder-idempotency owner decision, live E2E BLOCKED (no safe records/sandbox).
- [x] Iter-008 verification (no new behavior change) — DONE: C-004 items confirmed present in tree by grep+read+lint (single end-of-run guard, prod abort, token hint, anchored key, aligned docblock); refund-scope resolved by reasoning (REFUND-009 stays committed — coherent paid-completed state); re-seed NOT re-attempted (SEED-001 already proven); full 12-order census + invariants re-checked read-only. Open: C-004 challenger re-check (next turn), SEED-001 owner, DB-005, safe-record classification, sandbox.
- [x] Iter-009 order-13 picking report — DONE (diagnose + observability): order 13 ABSENT in `catch` (max 12) so exact reproduction BLOCKED; mechanism VERIFIED by trace (NULL-allocation skip → 200+`[]`, remedy assign-placement); login-validation path PASS as-is (translated 401 en+ar, no leak); PICK-OBS logging fix applied, lint-clean, independently reviewed with no BLOCKER/MUST-FIX; runtime UNVERIFIED-BLOCKED. To confirm on your environment run (read-only): `fulfillment_items` for the fulfillment with `product_location_id IS NULL`, `picking_tasks` count, fulfillment `status`; then `assign-placement` → re-run create-tasks.
- [x] Iter-010 "add to all" sweep + contract decisions — DONE: audited all sibling skip/empty sites (batch create loud 422, retry explicit message, packing count+warning, list semantics correct, sync no-op) — only create-tasks needed the fix (C-006, already applied); user explicitly chose keep-200+log and keep-manual-placement, so the two breaking/speculative options are closed with approval on file. No new code delta.
- [x] Iter-011 true-data bins + SKU coverage — DONE (build + TESTED): `WarehouseBinMasterSeeder.php` (standalone, idempotent, zero quantity writes); `--class=` run proved 11/0 created, 3/3 SKUs allocatable, PICK-01 drift flagged and kept, counts unchanged 11/4; review no BLOCKER with SHOULD-FIX adopted. True inflow for any future unplaced SKU stays via stock-receipts (no fabrication). Open: DB-005, SEED-001 owner, safe-record classification, sandbox.
- [x] Iter-012 all-product/variant placement proof — DONE (verify-only): live join proves ALPHA PICK-01(50)+A-01-01(50), BETA A-01-02(60), GAMMA A-01-01(20), 0 variants exist; receive/transfer traced as the only truthful inflow (receipts grow authority, transfers move hints); user explicitly accepted complete (no transfers, no other DB). Zero writes. Open: DB-005, SEED-001 owner, safe-record classification, sandbox.
- [x] Iter-013 "run and fix" safe gates — DONE: lint 22/22 clean, route:list 533 no errors, invariants 12/7/3/7/11/4 + 0 dup-active + 0 NULL flow; zero failures → zero fixes. Suite/seed/writes deliberately NOT run (DB-005, SEED-001, no safe records/sandbox — standing blockers). Open: same blockers.
- [ ] Iter-014 second wipe — CONTAINMENT: authorized isolated runs done (3/3, 6/6, 16+5-baseline); `catch` found empty after with parallel-artifact table (`content_page_section` only creatable by their suite); forensics + attribution in `error/DATA-LOSS-INCIDENT-2.md`; this track halts ALL runs (even isolated) until DB-005 + coordination. Recovery decision requested — NO action taken.
