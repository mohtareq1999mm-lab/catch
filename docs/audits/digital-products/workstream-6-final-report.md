# WORKSTREAM 6 — FINAL REPORT

- **Date:** 2026-08-25
- **Verdict:** see §23.

---

## 1. Scope
Derived verbatim from `master-todo.md` Phases 7–8: SHOW endpoint · widened UPDATE (display_name/status/metadata) · explicit file-replacement endpoint · admin entitlement management (list/filter, limit incl. unlimited sentinel, revoke, restore — gated `manage-digital-access`, activity-logged). Nothing else.

## 2. Contract derived from source
All four TODO lines read from master-todo; entitlement lifecycle reused from W1 (`DigitalFulfillmentService::revoke`, D7 interlock); replacement reuses the W4 byte-truth pipeline + compensation pattern; status semantics extend the W3 column; unlimited sentinel = `download_limit=0` enforced atomically inside the existing race-safe download UPDATE.

## 3–5. Implementation summary
- **SHOW** `GET digital-assets/{uuid}` (view-products) — collection-entry contract.
- **Widened UPDATE** `PUT digital-assets/{uuid}` — adds display_name/status/metadata; `status` restricted to active|inactive (revoked/expired system-reserved → 422); bytes/checksum immutable (proven raw).
- **REPLACE** `POST digital-assets/{uuid}/replace` (update-product, multipart) — FILE-only (URL/LICENSE/ACCESS → 422 translated); validate-new-bytes → checksum → write-new → tx-swap(path/mime/ext/size/checksum) → compensate-new-on-failure → retire-old-after-commit with drift warning; uuid/original_name stable.
- **Entitlement management** — `GET digital-entitlements` (+uuid/status/order_id/user_id/search filters, meta envelope), `GET {uuid}`, `PATCH {uuid}/limit` (omit/null/0 ⇒ UNLIMITED sentinel), `POST {uuid}/revoke`, `POST {uuid}/restore`. Business logic in new `app/Services/Digital/DigitalEntitlementService.php`; Marvel controller is CRUD+dispatch only.
- **Customer semantics:** inactive assets vanish from disclosure and are refused by the download gate (404); unlimited sentinel wired into the atomic increment SQL.

## 6. Routes
Six new routes registered in the Marvel admin group (`auth:sanctum`+`throttle:admin`) with whereUuid constraints and named routes; **route:cache succeeded and cleared** (the earlier bKash route:list failure does not affect caching/dispatch).

## 7. Permissions
NEW enum case `MANAGE_DIGITAL_ACCESS='manage-digital-access'`; seeder master+staff/owner/super-admin buckets; en/ar labels. Split proven over HTTP: view-orders-only admin lists (200) but cannot revoke/limit (403); manage-digital-access mutates without product perms. No unused permissions added.

## 8–9. Validation & Database
Reserved-status rejection, prohibited fields, batch caps, integer bounds — all 422 with zero partial state. **Zero migrations required** (W3 schema sufficient). Raw-PDO assertions confirm every persisted field including sentinel `0`.

## 10–13. Transactions / Queue / Redis / Filesystem
- **Transactions/failure injection:** replace write-failure → old pair intact; DB-failure (live column hide) → new file compensated, old intact; delete/revoke paths unchanged from W4/W1 guarantees.
- **Queue:** REAL database-queue proof — revoke via HTTP → LogActivityJob persisted on `meem-medium` → real worker (`queue:work --once --queue=meem-medium`) consumed it → activity_log row written (5/5). Root-caused en route: job targets the named queue, not default.
- **Redis/cache:** N/A — W6 touches no cached resources (documented).
- **Filesystem:** replacement retires exactly one old file, leaves exactly one current file; post-commit unlink failure logs drift (warning) per W4 contract.

## 14. Security
IDOR/product-crossing/entitlement-crossing blocked by uuid scoping + permission middleware (proven over HTTP). Secrets/path/disk absent from SHOW/listing payloads. Reserved-status tampering rejected. Inactive assets unreachable by customers even with valid signed URLs.

## 15. Concurrency
Real MySQL cross-process download race (`w6_concurrency_check.php`, retained): cap=1 two racers → exactly one 200/one 403/count=1; unlimited sentinel → four racers all delivered, counter atomic. **5/5 PASS.**

## 16. Translation
One new message ×3 locales (`ERROR.DIGITAL_ASSET_NOT_REPLACEABLE`); three new activity labels en/ar (`digital_entitlement_*`). Runtime-probed, Arabic glyph assertions in suite; no raw keys leak.

## 17–19. Regression & Independent check
Full digital matrix: **OK — 135 tests / 516 assertions** vs W5 baseline 120/435 (+15 W6). New failures: **0** after parity patches to two legacy local bootstraps (missing `status` column — test infrastructure only). Independent black-box checker (`w6_independent_check.php`, production migrations + raw PDO): **28/28 PASS**.

## 20. Defects
No OPEN defects. Authoring-time self-corrected items (envelope nesting paths, replace-failure status family, legacy bootstraps) were fixed before green claims and are documented here rather than hidden.

## 21. Observations
Restore is an admin override (activity-audited) — business-rule gating deliberately left to ops policy per Phase-13 wording. Unlimited sentinel exposes as `unlimited:true` for clients. Route cache works; `route:list` remains blocked by unrelated bKash gap.

## 22. Deferred
W7 DeliveryResolver/streaming/preview/audited-redirect; AV scanning; import/export coupling.

## 23. Final Verdict

**WORKSTREAM 6 — CLOSED**

Every Phase 7–8 TODO implemented, independently verified at HTTP/DB/filesystem/queue/concurrency layers, regression clean, scope audit clean.
