# Change log

## C-001 (this session) — OF-001/WMS-001 duplicate-release convergence
- Why: keyed automatic releases skipped pending/active reuse → duplicates in real `catch` (order 13 two pendings; orders 4,5 extra pending alongside actives) stalling `FulfillmentOrderSyncService::allAtLeast`.
- Files:
  - `app/Services/Fulfillment/FulfillmentService.php` (+~50/-~15): added `isAutomaticKey()`; keyed-auto + keyless now share foreign-guard + pending reuse (NULL-key claim) + active same-warehouse reuse (NULL-key claim); custom split keys preserve bypass. No API/shape/message/permission change.
  - `tests/Feature/Fulfillment/AutoReleaseDedupeRegressionTest.php` (new, +~120): 3 tests (keyless→auto same row + key claim; keyless no-duplicate on active; custom split still splits).
- Before: `if ($idempotencyKey === null) { guard + pending-only reuse }`; keyed auto created unconditionally on key miss.
- After: `$isSplitIntent = key!==null && !auto`; `if (!$isSplitIntent) { foreign guard; pending reuse (+claim); active reuse (+claim) }`.
- Compatibility: additive method; split semantics preserved (different-warehouse split test + same-warehouse custom split test green); warehouse scope/permissions untouched; idempotent replays converge.
- Tests: `FulfillmentHardeningTest` 6/6 PASS (isolated `catch_verify`); new suite 3/3 PASS; `AutoReleaseWiringTest` 16 PASS + 5 pre-existing locale FAILs (identical with fix stashed — unrelated).
- Review: independent reviewer — no BLOCKER; 3 SHOULD-FIX recorded as follow-ups (cross-order key scope, auto-suffix strictness, terminal reuse semantics) — out of scope, no silent redesign.

## Pre-existing working-tree changes (NOT this session — do not attribute)
- `app/Console/Commands/AuditInventoryPlacements.php`, `packages/marvel/src/Rest/Routes.php`, `tests/Feature/Wms/WmsPlacementAuditTest.php`, `.phpunit.cache/test-results`, untracked `docs/api/get-admin-batches*.md`, `docs/audits/inventory-*.md`, `tests/Feature/Wms/WmsAssignedTasksVisibilityTest.php`, `meemmarket_meem.sql` — already dirty/untracked before this fix (seen at first `git status` after fix). Left untouched.

## C-002 (this session) — HIGH + hygiene security fixes + review corrections
- Why: audit findings SEC-002/003/004/009/010 + RT-003/005 hygiene (user scope: HIGH + hygiene; OTP keep+document; super-admin-only guard; self-only contact). Independent review BLOCKER B1 + S1-S4/N1-N2 fixed before merge.
- Files:
  - `packages/marvel/src/Rest/Routes.php`: removed dead duplicate `GET me` (winner `throttle:admin` retained); login comment 10→5/min; OTP comment DISABLED→ACTIVE; contacts split (contact-us + POST contacts alias public, rest auth+admin); per-route throttles (change-password/update-contact→sensitive, address→authenticated). NOTE: file also carries parallel-track whitespace hunk (picking-tasks indent) + pre-existing dirt — those are NOT this change.
  - `routes/api.php`: removed shadowed top-level signed download duplicate; corrected digital comments to auth+signed reality (guest redemption = open product decision).
  - `UserController.php`: updateContact self-only (`auth()->id()`, `findOrFail`, ModelNotFound→404 catch); index allowlist + limit 1..100 + is_string sort guard.
  - `UpdateContactRequest.php`: `user_id` required→nullable, dropped `exists` (ignored field must not 422).
  - `RoleAndPermissionController.php`: assign/remove super_admin → 403 unless actor `hasRole(super_admin,'api')` (reused message key, no new strings).
- Before/after: IDOR closable by any authed user → self-only; contacts admin ops public-group → auth-group; any ASSIGN_ROLE holder could grant super_admin → super-admin-only; raw order_by/limit → allowlisted/capped; unthrottled auth endpoints → throttled; dead routes/comments fixed. `POST /contacts` alias preserved public (B1).
- Compatibility: URIs + route names unchanged (533 total before/after); `user_id` still accepted (ignored); non-super_admin assignments unaffected; defaults (15/created_at/desc) preserved; limit>100 clamps (intentional). Known limitation (S3): clone-role-with-all-permissions escalation needs product decision — documented, not closed.
- Tests: php -l 5/5 clean; route:list 533 + per-route middleware verified (contacts split, throttles, single me/download). NO `php artisan test` (DB empty + 241 RefreshDatabase files; Contact/Role suites cannot run safely) — runtime behavior UNVERIFIED, marked BLOCKED pending recovery.
- Review: independent reviewer re-check of B1/S1-S4/N1-N2 pending; prior review: 1 BLOCKER + MUST-FIX (this entry resolves), SHOULD-FIX remainder logged.

## C-003 (2026-10-14, FIX ALL ERROR session — scope: safe non-breaking + authorized seed)
- Why: user chose "Safe non-breaking only" + "if empty, seed and run with true values". Independent review of the C-001/C-002 tree returned 1 BLOCKER + MUST-FIX items; verified + repaired within scope.
- Files (this session only):
  - `packages/marvel/src/Rest/Routes.php`: whitespace normalization of picking-tasks block (indent-only, no logic change; rest of file = C-002, untouched).
  - `database/seeders/WmsOperationsSeeder.php`: production fail-closed guard (`abort_if production`); `AdminWmsToken` idempotent (reuse existing, no new row per re-run); blanket `whereIn id 1..10` inventory rewrite scoped to `order_number LIKE 'ORD-WMS-%'`.
  - `app/Services/Fulfillment/FulfillmentService.php`: `isAutomaticKey()` anchored to `/^auto-release-order-\d+$/` (was unanchored prefix; reviewer MUST-FIX).
- Verified this session: `php -l` clean on Routes/UserController/UpdateContactRequest/FulfillmentService/AuditInventoryPlacements/RoleAndPermissionController/routes/api.php/regression test; `route:list` loads (≈533 routes, 534 output lines); `POST /contacts` public alias present (review BLOCKER already resolved in tree); `updateContact` self-only + request nullable verified by read.
- Seed run (user-authorized write to EMPTY catch): `php artisan db:seed --no-interaction` → OK (WmsOperationsSeeder success banner). Before: orders=0 users=0 products=0. After: orders=12 users=7 products=3 fulfillments=7 warehouses=1 flows=2 statuses=22 (tinker counts, DB=catch). Post-seed invariants: 0 duplicate active fulfillments per order+warehouse; 0 flow-less orders. Bearer token issued (name `AdminWmsToken`) — REDACTED everywhere, test credential only. Detail: `error/SEED-RUN-2026-10-14.md`.
- NOT run: `php artisan test` (still halted: 241 RefreshDatabase files vs empty catch; Contact/Role suites unsafe) — runtime behavior of C-002/C-003 beyond lint+routes stays UNVERIFIED/BLOCKED pending DB-005 guard + recovery classification.

## C-004 (2026-10-14 re-entry — challenger-driven, scope: safe non-breaking)
- Why: independent challenger review of C-003 returned 1 MUST-FIX (seeder commit-guard placement) + 1 SHOULD-FIX (token print) + 1 NICE (docblock) — all accepted; contacts-alias BLOCKER already resolved in tree (verified present, no action).
- Files (this session only):
  - `database/seeders/WmsOperationsSeeder.php`: ORD-WMS commit guard moved from mid-file to end-of-run (covers scenarios 1–9; scoped `ORD-WMS-%`, never blanket IDs); token summary prints usable-token-or-hint (no unusable literal on re-run).
  - `app/Services/Fulfillment/FulfillmentService.php`: namespace docblock aligned to anchored pattern (comment-only).
  - `packages/marvel/src/Rest/Routes.php` picking-tasks "hunk": verified ABSENT from diff (whitespace already clean — zero net change).
- Re-seed attempt (authorized): `php artisan db:seed` re-run FAILS fail-closed at CouponSeeder (`Coupon.php:89-91` canonical-duplicate guard) — DatabaseSeeder is NOT idempotent (new finding SEED-001). Post-failure counts identical (12/7/3/7/20/1): zero drift. End-placement guard therefore NOT yet effective on live rows (fixtures 6–12 stay `none`); NO CouponSeeder change (out-of-scope domain, refused). SEED-001 → backlog TODO.
- Verified: `php -l` clean on both edited files; sub-agent evidence (WMS lifecycle conformance + skeptical challenge) on file this turn.

## C-005 (2026-10-14 re-entry verification — no new behavior change)
- Why: confirm C-004 challenger items actually present in tree + resolve refund-scope question without churning fixtures.
- Verified present (grep + read, `php -l` clean): single `ORD-WMS-%` commit guard at end-of-run (`WmsOperationsSeeder.php:688-690`, mid-file occurrence gone); prod `abort_if` (L37); token placeholder + hint print (L220/L699-703); anchored `isAutomaticKey` + aligned docblock (`FulfillmentService.php:28-29,56-65`); Routes picking-tasks hunk absent from `git diff` (zero net change).
- Decision (documented, no edit): REFUND-009 stays inside commit scope — a paid `completed` order with COMMITTED inventory is the coherent state (refund-approve restore path expects committed stock to restore); excluding it would create the inconsistency the challenger feared. Challenger SHOULD-FIX (token-hint) already satisfied in tree; NICE docblock satisfied.
- Re-seed still BLOCKED by SEED-001 (CouponSeeder guard) — end-placement takes effect only on next clean seed; fixtures 6–12 remain `none` (by-design, zero lifecycle impact).

## C-008 (2026-10-14 — true-data bin master + SKU coverage, scope: safe non-breaking)
- Why: user asked for a true-data locations seeder with all products assigned via the true flow. Discovery proved bins (11) + full live coverage (3/3 SKUs) already exist; `LocationSeeder.php` is geographic data (misnomer), bins came from two non-idempotent seeders.
- Files (this session only): `database/seeders/WarehouseBinMasterSeeder.php` (new, standalone — NOT wired into DatabaseSeeder): 11-bin union master firstOrCreate by (warehouse,code) with WMS operational defs first (scanner barcodes), prod `abort_if 403`, drift warn (never rewrite), per-SKU allocatable-coverage report mirroring allocator (`quantity>hint`, NULL-or-placeable, same warehouse). Zero placement-quantity writes by design — inflow stays via stock-receipts/transfers.
- Run TESTED (`--class=` only): 11 declared/0 created, coverage 3/3 allocatable, drift flagged PICK-01 (kept live), counts unchanged 11/4. `php -l` clean; independent review no BLOCKER (SHOULD-FIX adopted: placeable-mirror, hint-aware, drift warn, 403; NICE logged as follow-ups).
- Not changed: no stock/authority writes, no placement rewrites, no DatabaseSeeder wiring (non-idempotent, SEED-001), no quantity fabrication (would falsify bin truth).

## C-006 (2026-10-14 — picking observability, scope: safe non-breaking)
- Why: reported "fulfillment OK but picking tasks won't create" presents as HTTP 200 + empty list when items lack allocation (PICK-001); order 13 premise fails in `catch` (max id 12), so mechanism-level fix only.
- Files (this session only): `app/Services/Fulfillment/OrderPickingService.php` (+counter, log context, zero-pickable warning; IDs-only logs, no PII; no response/state/permission/message change).
- Not changed (verified correct): 401 translation path (AUTH-001 PASS); response contract (200+list preserved — 422/message change refused as breaking); no auto-placement (operator decision, speculative behavior refused).
- Verified: `php -l` clean; independent review no BLOCKER/MUST-FIX (2 NICE-TO-HAVE: wording refined, pre-existing in-txn log pattern accepted). Runtime UNVERIFIED-BLOCKED (no order 13, no writes, no test runs).

## C-007 (2026-10-14 — "add to all" audit + user contract decisions, no behavior change)
- Audited every sibling skip/empty site read-only: batch creation fails loud (`zero_pickable` 422, en+ar present); batch retry answers explicitly (`retried` vs `nothing_retry`, en+ar present); packing void returns count + sealed warning log; pending-fulfillments empty list is correct list semantics; sync same-state `continue` is a no-op. Only order-flow create-tasks was silent (fixed C-006). No further code change warranted.
- User decisions (explicit, via question tool): keep 200 + log-only (no 422 change) + keep manual placement (no auto-allocation). Both breaking/speculative options REFUSED with approval recorded.

## C-006 (this session) — remaining-findings round: OTP anti-enumeration + per-account lockout + shipment cap
- Why: user "fix all" with per-item decisions (generic-200 OTP; login keep+document; lockout all verify paths; cap-only shipments). No invented business values: lockout 5 fails/15 min documented as tunable defaults; TTL/policy changes refused without values (SEC-005/007 accepted/documented).
- Files:
  - `packages/marvel/src/Http/Controllers/UserController.php`: sendUserOtp unknown/inactive → generic 200 `OTP_SENT_SUCCESSFULLY` (no OTP created/dispatched; email shape identical); lockout helpers (sha1 keys, ApiResponse-shape 429 with status); hooks in verifyLoginOtp (otp_email), otpLogin phone (otp_phone, empty-guard), verifyForgetPasswordToken (pwd_verify), resetPassword (pwd_reset, check moved before txn); clear-on-own-success (verify/otp after token, reset in-txn documented negligible).
  - `app/Http/Controllers/Api/ShipmentController.php`: index limit 1..100 (InvoiceController pattern; service double-clamps).
- Before/after: enumeration via send-otp-code closed (email fully; phone shape oracle F1 residual); brute-force bounded 5/window per account on all 4 verify paths; shipment index DoS closed. verifyLoginOtp 404 + login policy + TTLs unchanged per explicit decisions.
- Compatibility: no route/permission/envelope changes except additive 429 (ApiResponse shape with status); no new translation keys (`too_many_requests` exists en+ar); no migration (cache-based).
- Tests: php -l 2/2 clean; route:list 533 unchanged; no test runs (seeded fixtures must not be wiped; RefreshDatabase quarantine). Runtime payloads BLOCKED.
- Review: independent review (no BLOCKER/MUST-FIX); F1/F2 residuals + S3 clone-role + guest-redemption documented, not closed. Known: GraphQL token-vs-otp param mismatch (pre-existing, separate).

## C-007 (this session) — test-environment fix loop for real results (test-only, zero app changes)
- Why: user "fix all ... in true result": 6 certified-safe files ran 23 pass / 134 fail. Root causes all environmental (pre-existing), none in my app diff.
- Fixes (all test-only): TEST-ENV-001 schema-tolerant settings ensure (3 files — no app code reads settings.language, verified); TEST-ENV-002 findOrCreateTestRole+syncPermissions, customer type, POST→PATCH ×4, guard_name expectation corrected to RoleResource shape, 2 new SEC-004 regression tests; TEST-ENV-003 relative token baselines + token-id missing proof. Review round: M1/S1/S2/S3/N2 applied.
- Results (by explicit file path, real MySQL `catch`): AuthenticationTest 19/19, UserPasswordResetTest 13/13, OtpLocalLoginTest 4/4, RoleAndPermissionTest 34/34, UserControllerTest 68/71, UserAuthAdminTest 12/19 → 150 pass / 10 BLOCKED. The 10: tests against unregistered/disabled routes (4 update-email, 3 social-login-token, 3 make-admin) with route:list ERROR proof each — implementing = new features, refused.
- Residue: 7/12/3/2/9 counts identical before/after every run; test_user=0. Zero escaped writes.
- Environment: Laragon MySQL found DOWN mid-loop (conn-refused false-red run discarded, not cited); restarted mysqld 8.4.3 against live datadir; identity+counts re-verified identical. `artisan test` takes ONE path argument (verified by count) — multi-path invocations silently drop files; always run per-file.
- Review: independent review of test diffs (no BLOCKER; M1/S1/S2/S3/N2 fixed). Directiva: RefreshDatabase suites (241 files) still never run against catch.

## C-009 (audit track) — isolated loop + review round 3 (test-only)
- Batch-2 review items (validation pin, absence-proof rename + route-assertion) applied; re-runs green on catch_verify.
- Incident-2 doc corrected (attribution split wipe/table). No app-code changes this entry.

## C-010 (audit track) — DB-005 quarantine guard (test harness, zero app change)
- tests/CreatesApplication.php: refuse any run resolving shared catch@127.0.0.1 unless ALLOW_CATCH_TESTS=true (exit-1 before setup). No test hardcodes catch (verified by grep: only queue-name/worker-conf matches + concurrency scripts defaulting to meem_test); all resolve via phpunit.xml + process env.
- Verified: bare run REFUSED with zero DB touch; isolated override 4/4; explicit-flag catch run 4/4 with zero residue (0/0 unchanged).

## C-011 (audit track) — batch-3 loop: nullable-email migration + envelope + DDL-trap (test-only + 1 migration)
- Migration 2026_10_15_000002 (NEW, unapplied to catch): orders.user_email NOT NULL to nullable. Evidence: service writes null by design; both DBs reject; PhoneOnly test asserts null-email order. user_phone untouched (no evidence). Applied to catch_verify only (872ms); catch pending via normal migrate (additive, empty tables).
- PhoneOnlyE2ETest 422 envelope corrected to Handler shape (errors.email) x2 — no envelope redesign.
- CheckoutRegressionTest: extracted ensureVariationOptionsTable (legacy table absent from migrations/DBs). FOUND mid-loop: setUp DDL triggers MySQL implicit commit, breaking per-test rollback (9 duplicate-email fails from committed residue). Cleaned own residue (1 user + dependents) on isolated DB; re-ran 9/9 with zero residue. Lesson recorded: pre-create test-only tables OUTSIDE test transactions; residue-check every file.

## C-012 (audit track) — batch-4 loop + 2 widening migrations (evidence-backed)
- Migration 2026_10_15_000002: orders.user_email NOT NULL to nullable (service writes null by design; both DBs rejected; PhoneOnly asserts null-email order). Applied verify-only.
- Migration 2026_10_15_000003: product_variants.price NOT NULL to nullable (service branches on null; accessors null-tolerant; fallback test asserts). Applied verify-only. catch pending via normal migrate (both additive, reversible, empty tables).
- Test-only: 422 envelope to live Handler shape x2; promotion FK real row; 'fixed' to canonical 'fixed_rate' x2 (ENUM coerces to '' then percentage math — proven by roundtrip + pure-function isolation: fixed(130,15)=115 vs perc=110.5).
- Batch-4: 247 pass / 0 fail across 20 files (isolated). Residue zero both DBs.

## C-013 (audit track) — batch-5 loop: payment system 29/29 (test-only)
- FK-id chain fix (country/governorate/shipping via created ids — auto_increment 71 on empty table proven); coupon 'fixed' to 'fixed_rate' (model validates strictly + ENUM coerces); 6 cancel tests rewritten to reservation contract (ACTIVE + expires_at) + matrix unit test replacing stale created_at/config premise; 422 envelope x2; order-currency contract rewrite (resolver ignores payment.default_currency by design); real governorate id.
- Private catch_audit DB (created+migrated, mine only). DDL-trap residue (15 users) cleaned after verified ownership (all random-suffixed fixtures); re-run 29/29 + zero residue.

## C-014 (audit track) — batch-6 loop: invoice/digital/gift green (test-only)
- InvoiceDownloadPermission 18/18 (relative permission baseline + scoped no-dup check); MyInvoices 9/9; DigitalRepairVerification 9/9.
- GiftAndReconciliation 8/8: default-warehouse ensure (auto-release listener requires ACTIVE default); COD-commit assertion rewrite (reserved-0/sold-1/COMMITTED vs stale reserved-1); expiry test rebuilt as pending+ACTIVE with real order lines (COD processing is outside reaper pending-only scope by design); cart-404 + PAN-absence already covered.
- Contention on shared catch_audit (foreign residue, 1205 metadata-lock waits) answered with single-run isolation + per-file residue checks; zero residue both DBs.
