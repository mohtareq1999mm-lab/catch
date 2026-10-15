# Findings — VERIFIED discovery (no fixes applied yet)

> Severity: BLOCKER / HIGH / MEDIUM / LOW. Status: OPEN (all). Evidence: file+line. No code changed in this phase.

## HIGH
### SEC-001 — OTP routes ACTIVE despite DISABLED comment + enumeration + no lockout
- Evidence: `packages/marvel/src/Rest/Routes.php:95-102` (comment fixed ACTIVE in C-002); `UserController:sendUserOtp/verifyLoginOtp/otpLogin`.
- Fix (this session): `sendUserOtp` returns generic 200 for unknown/inactive (no OTP created/dispatched; email shape identical); per-account lockout (5 fails → 429/15 min, sha1 cache keys, cleared on success) on `verifyLoginOtp` (otp_email), `otpLogin` phone path (otp_phone), `verifyForgetPasswordToken` (pwd_verify), `resetPassword` (pwd_reset, check moved before txn); 429 follows ApiResponse envelope.
- Residual (review F1/F2, documented): phone success includes `data.otp_id` vs absent for unknown (shape oracle); `verifyLoginOtp` 404 kept per explicit decision (email oracle). Status: FIXED with documented residuals.

### SEC-002 — update-contact IDOR (user_id from client)
- Evidence: `UserController:1136` (`User::find($user_id)`), `UpdateContactRequest` requires `user_id exists:users,id`; no `auth()->id()` binding.
- Routes: `POST /update-contact` (auth:sanctum,lang, NO throttle).
- Impact: any auth user with OTP for own phone can rewrite ANY user's contact; hijack prep.
- TODO: SEC-002. Recommendation: use `auth()->id()`, add throttle:sensitive, audit log. Status: FIXED this session (self-only + nullable user_id + sensitive throttle + 404 catch; php -l + route:list verified, runtime BLOCKED).

### SEC-003 — Contact admin ops in public group
- Evidence: `Routes.php:114-120` (`throttle:sensitive,lang` only, NO auth): reply, contact-us, delete-all, delete-all-read, contacts resource; constructor permission compensates but one misconfig from public wipe.
- TODO: SEC-003. Recommendation: split group (only contact-us public). Status: FIXED this session (contact-us + POST contacts alias public; rest auth+admin; names/URIs unchanged; route:list verified, runtime BLOCKED).

### SEC-004 — assign-role → super_admin escalation
- Evidence: `RoleAndPermissionController:__construct` (assignRole needs ASSIGN_ROLE only), `:150` syncRoles any guard=api role, only blocks type=user, no hierarchy check. Direct-permission APIs correctly require SUPER_ADMIN.
- Route: `PATCH /users/{userId}/assign-role`.
- TODO: SEC-004. Recommendation: require super_admin for privileged roles + hierarchy check. Status: FIXED this session (super-admin-only 403 guard on assign AND remove; explicit 'api' guard; reused message key). Open limitation: clone-role-with-all-permissions escalation needs product decision (review S3).

## MEDIUM-HIGH
### SEC-005 — Customer login needs no verification; 14-weekday token; logout single-token
- Evidence: `UserController:token` (issues token, returns email_verified flag but still full access, 14 weekdays), `adminToken` (requires verified + 1 week), `logout` (deletes current only).
- Decision (user, this session): KEEP + DOCUMENT as accepted risk (no verification gate, no TTL change). Status: ACCEPTED RISK (documented, no code change).

### SEC-006 — Password-reset 6-digit IP-only throttle
- Evidence: `UserController:forgetPassword` (random 100000-999999, hashed, 60min expiry); throttle sensitive 5/min IP only; no enumeration (same CHECK_INBOX), replay-safe.
- Fix (this session): per-account lockout shared with OTP design (pwd_verify/pwd_reset keys, 5/15 min, split to avoid cross-endpoint DoS). Status: FIXED (code), runtime BLOCKED.

## MEDIUM
### SEC-007 — Signed URLs are bearer tokens
- Evidence: `routes/api.php` signed+throttle:30,1; TTL invoices 10min, digital 30min (`digital.signed_url_ttl_minutes`); redeem checks status/expiry/binding/limit but NOT user_id. License reveal correctly auth-only.
- Decision (user, this session): cap-only scope — NO TTL/scope change. Digital 30 vs invoice 10 asymmetry + bearer nature documented as accepted. Status: DOCUMENTED (no code change).

### SEC-008 — Generic shipments lack warehouse scope
- Evidence: `Routes.php` shipments group (auth:sanctum,lang; constructor permission only); `ShipmentService:list/find` no user/warehouse filter (vs WMS admin/shipments scoped).
- Fix (this session, per cap-only decision): generic `index` limit capped 1..100 (DoS closed); scoping/deprecation left as documented design decision (fail-closed WMS surface remains canonical). Status: PARTIALLY FIXED (cap) + DOCUMENTED (scope).

### SEC-009 — User index order-by/limit
- Evidence: `UserController:~130-155` (orderBy/sort from query, no allowlist; limit uncapped vs InvoiceController cap 100).
- TODO: SEC-009. Status: FIXED this session (allowlist created_at/name/email/id + asc/desc only + limit 1..100; matches InvoiceController).

### SEC-010 — Missing throttles (change-password/update-contact/address/me)
- Evidence: `Routes.php:104-108` (auth:sanctum,lang only, NO throttle). Address scoping correct (customer_id=auth.id) but unlimited probing.
- TODO: SEC-010. Status: FIXED this session (change-password/update-contact→throttle:sensitive; address→throttle:authenticated; route:list verified).

### RT-003 — Duplicate routes (LOW, hygiene)
- `GET api/v1/me` x2 (lines 83 vs 134, winner 134 throttle:admin) — VERIFIED live.
- `GET general/digital/download` x2 (api.php:189 + :221, same handler/name) — VERIFIED live single.
- `broadcasting/auth` x3 (App provider + Marvel provider + Routes.php:64 under api/v1) — MEDIUM risk to Echo auth.
- TODO: RT-003. Status: FIXED this session (dead me + download duplicates removed; route:list 533 unchanged, single winners verified; broadcasting left + documented).

### RT-005 — Comment drift (LOW, correctness)
- OTP DISABLED lie, login 10/min comment vs 5/min actual, enum-types public vs product-type auth asymmetry, banner singular/plural split. All VERIFIED.
- TODO: RT-005. Status: FIXED this session (OTP comment ACTIVE, login 5/min, digital auth+signed reality; broadcasting + guest-redemption + banner split documented as decisions).

### I18N — message.php drift (SUPERSEDED by translation-audit.md key-parity PASS)
- Old concern (line drift 668 vs 613) CORRECTED: PHP parity script (top-level + 1-nested, 15 files) → 0 missing both directions (message 592/592). Line drift = formatting/value-length, NOT missing keys. Value quality/placeholders/ar.json/deep recursion + per-endpoint tests remain OPEN/BLOCKED. See `error/translation-audit.md:8-14`.
- TODO: I18N-002 (PARTIAL). Status: OPEN.

## BLOCKED / UNKNOWN (not defects, need evidence)
- OF-004 legacy Marvel traits reachability — BLOCKED.
- OF-005/WMS-003 real-DB write tests — BLOCKED (no safe eligible records classified).
- Concurrency multi-process proof — BLOCKED.
- Frontend validation — BLOCKED / FRONTEND SOURCE NOT AVAILABLE (no frontend repo in workspace; workspace root has no frontend dir).
- Live gateway/refund/shipment side effects — NOT APPLICABLE (forbidden by data rules; sandbox not configured).

## What was NOT changed
- No code edits, no migrations, no .env changes, no data mutations in this discovery phase. All findings are read-only.

## FIX-ALL track supplement (2026-10-14 re-entry — read-only + authorized seed only)
### SEED-001 — DatabaseSeeder NOT re-runnable: CouponSeeder aborts second run (MINOR, VERIFIED/TESTED)
- Evidence: `php artisan db:seed` re-run → `InvalidArgumentException: Coupon code is already taken` from `packages/marvel/src/Database/Models/Coupon.php:89-91` canonical-duplicate guard (model `creating` hook). Counts after failed run: orders=12 users=7 products=3 fulfillments=7 coupons=20 tokens=1 — zero drift, failure is fail-closed (throws before insert).
- Impact: the moved ORD-WMS commit guard (change-log C-004) cannot take effect via re-seed until seeder idempotency is addressed; fixture orders 6–12 stay `inventory_state none` (fixture-by-design, no lifecycle impact: write-tests BLOCKED regardless).
- Decision: NO CouponSeeder change (coupon domain = out of Order-Flow/WMS scope; opportunistic fix refused per minimal-fix rule). TODO: seeder-idempotency backlog.
### SEED-002 — Fixture inventory_state gap (INFO, VERIFIED)
- 7/12 seeded orders (`ORD-WMS-*` stage fixtures 6–12) carry `fulfillment_status NULL + inventory none + payment_method NULL` because their `firstOrCreate` blocks set none of those columns (WMS agent traced `WmsOperationsSeeder.php:410-666`). Fixture-by-design, NOT sync corruption; single-fulfillment fixtures cannot self-stall (`allAtLeast`, sync L297-306). No data repair attempted.

## LOC track supplement (2026-10-14 — true-data bins + full SKU coverage)
### LOC-001 — True bin data + assignment already live in `catch` (VERIFIED, no repair needed)
- 11 bins, warehouse 1, all active+placeable (storage×8, picking PICK-01 p15, receiving REC-01, packing PACK-01); 4 placements cover 3/3 SKUs with live qty (p1 50+50, p2 60, p3 20); allocator requires placeable + `hasStock` + warehouse/variant match (`ProductLocationService:72-137`, `scopePlaceable:103-109`). `LocationSeeder.php` is geographic/shipping data (misnomer — NOT bins); bins originate from `WarehouseSeeder` + `WmsOperationsSeeder` (both non-idempotent: `create` / CouponSeeder abort — SEED-001 family).
- New canonical master `database/seeders/WarehouseBinMasterSeeder.php` (standalone, NOT wired into DatabaseSeeder): 11-bin union firstOrCreate (WMS operational defs first: barcodes scanner tests rely on), prod `abort_if 403`, drift warn (never rewrite), per-SKU allocatable-coverage report mirroring allocator semantics (`quantity>hint`, NULL-or-placeable type, same-warehouse). Zero quantity writes by construction — true inflow stays exclusively via stock-receipts/transfers. Run TESTED: 11 declared/0 created, coverage 3/3, counts unchanged 11/4, drift correctly flagged PICK-01 (WarehouseSeeder-first English/no-barcode vs master). Independent review: no BLOCKER (4 SHOULD-FIX adopted, WMS-`status` literal kept — no such constant exists).
### LOC-002 — Placement-vs-authority gaps are hints, not defects (INFO)
- p2 placed 60/authority 100, p3 20/50: hints need not sum to authority (unbinned/receiving goods); fabricating bin quantities to match would falsify physical truth. Unplaced sellable SKUs (none currently) must be received via `POST stock-receipts`. No action.
### LOC-003 — Per-SKU placement proof + user acceptance (VERIFIED 2026-10-14, no writes)
- Live join (`product_locations`→`locations`→`products`, read-only): ALPHA-101 in PICK-01(picking,50)+A-01-01(storage,50); BETA-102 in A-01-02(storage,60); GAMMA-103 in A-01-01(storage,20); all hints 0. `product_variants` count = 0 (no variant SKUs exist). Coverage 3/3 allocatable, re-confirmed by seeder run (11 declared/0 created).
- User decision (explicit, via question tool): accept as complete — no pick-face transfers, no other database. Transfer/stock-receipt paths documented as the only true inflow for future SKUs. Refused-with-reason preserved: zero-qty rows (useless per `hasStock`, key pollution) and quantity fabrication (falsifies bin truth).

## FIX-ORDER13 track supplement (2026-10-14 — picking-task creation + login validation)
### PICK-001 — create-tasks silent empty success on unallocated items (MEDIUM, VERIFIED by code, reproduction BLOCKED)
- Chain: `POST admin/fulfillments/{id}/create-tasks` (`Routes.php:623`, auth+`picking-execute`+warehouse scope) → `PickingController::createTasks:131-144` → `OrderPickingService::createTasksForFulfillment:27-90` skips `product_location_id NULL` items (`:39-41`) and returns `[]` with HTTP 200 + `wms.picking.ready` when ALL items are unallocated. Allocation fallback lives in `FulfillmentService::createFulfillmentItem` (NULL placement + notes on failure). Remedy path: `POST .../assign-placement` (`permission:manage-fulfillment`) → re-run create-tasks. Other empty-result causes: all items fully picked (legitimate), wrong id kind (order id vs fulfillment id → translated 404), missing auth/permission/scope (translated 401/403).
- Premise check: order id 13 DOES NOT exist in `catch` (max id 12, zero `order_number LIKE %13%`, fulfillments on orders 3,6–11 only — read-only SELECT). Reproduction of the user's exact case BLOCKED (no order 13, writes forbidden, no test runs). General mechanism VERIFIED by code trace.
- Fix applied (observability only, zero contract change): PICK-OBS counter + `skipped_unallocated` log context + warning when zero pickable tasks with unallocated items (`OrderPickingService.php`); independent review: no BLOCKER/MUST-FIX. Status: FIXED (code) / runtime UNVERIFIED-BLOCKED.
### AUTH-001 — login validation on related WMS routes (LOW, VERIFIED, no fix needed)
- `admin/fulfillments` group carries `auth:sanctum` (`Routes.php:617`); unauthenticated → 401 `message.ERROR.UNAUTHENTICATED` (`Handler.php:169-170`, `translateHttpMessage:265-266`); keys present en (`message.php:575`) + ar (`message.php:486`); picking messages (`wms.picking.ready` en:149/ar:146) translated. No raw-key leak on this path. Status: PASS (audit).
