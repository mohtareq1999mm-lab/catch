# Phase 13 — Admin Experience

## 1. Executive Verdict

**PASS WITH RESIDUAL RISKS.** The admin surface is broad and consistently gated: Sanctum + `throttle:admin` groups with granular per-action permissions across orders, flows, payments/refunds, gateways, shipments, WMS (10 controllers with warehouse confinement), coupons (targeting/distribution/rules), invoices (six distinct grants), analytics (+export), tracking, and settings. Financial actions carry dedicated permissions (`payments.mark_paid`, `payments.refund`); destructive/edge operations are reason-gated and audited; the activity system redacts secrets recursively; imports are ownership-scoped; queues use semantic names resolved from deployment config. The manual's catalog structure still maps the product, but its mechanics are stale (permissions renamed, endpoints moved, guards upgraded), and the post-manual admin surface (flows, WMS, coupon ops, gateway settings) has no manual home. Runtime proof is absent as everywhere.

## 2. Phase Objective

Per `PHASE-13-ADMIN-EXPERIENCE.md` (source: `HEAD:docs/production-manual/PHASE-13-ADMIN-EXPERIENCE.md`, 710 lines): document every administrative action — order/payment/invoice/shipment/refund/user/product/coupon/promotion/CMS management, dashboard analytics, settings, activity log, realtime, queue monitoring, reconciliation, common workflows, and file index.

## 3. Scope

**In scope:** admin routes/permissions, financial admin actions, invoice/shipment/refund ops surfaces, user/role administration, product/coupon/promotion/CMS admin, dashboard + analytics + export, settings, activity/audit, realtime admin channel, queue naming/monitoring, reconciliation summary, admin workflows.

**Out of scope:** Marvel CMS content internals, frontend dashboard implementation, vendor/shop marketplace accounting beyond balance hooks (Phase 10), DevOps deployment of workers/supervisor.

## 4. What Was Supposed to Be Implemented

The manual claims: order transitions via legacy map; mark-paid under `update-order-status`; invoice ops with five permissions (+debit unpermissioned); shipment CRUD permissionless; refund via listeners with `inventory_restored_at` guard; roles/permissions middleware; product/coupon/promotion management; CMS endpoints; 16-cached dashboard analytics (300s); settings/governorates/pickup endpoints; spatie activity log + `LogActivityJob`; Pusher admin channel + test route; high/medium/low queues with fixed workers; reconciliation job + dashboard summary; four admin workflows; file index.

## 5. What Actually Exists

Structure preserved, mechanics upgraded, surface greatly expanded:

- **Orders/flows**: status options/PATCH/batch with per-target `change-order-status.<code>` + flow catalog/inputs admin (`OrderFlowController`, `OrderStatusCatalogController`, `FlowInputController`) under granular `view/create/update-order-flows` grants; customer cancel, reaper, Marvel adapter as audited (Phase 05).
- **Payments**: mark-paid under `payments.mark_paid` (F-1, not `update-order-status`); direct refunds under `payments.refund` with idempotency keys (`PaymentRefundController`); gateway settings CRUD under settings perms; public availability snapshot.
- **Invoices**: six individually-gated ops (VIEW/REGENERATE/CORRECT/CANCEL/ISSUE_DEBIT_NOTE all present — GAP-1 closed); customer signed/owner access; verification authenticated (Phase 08 drift).
- **Shipments/WMS**: public CRUD gone (unrouted); order-shipment + fulfillment-shipment + 10 WMS controllers behind `view/create/update-shipment` + warehouse confinement (`WarehouseAccess`); cancel/receive/inspect/pack/pick/batch/package/location/warehouse ops permissioned per action.
- **Refunds**: request flow (throttled) + dual approval tracks (gateway-first Marvel flow; ledgered direct flow) with wallet/balance hooks (Phase 10).
- **Users/roles**: `UserType` (ADMIN/USER), spatie roles/permissions, `UserRolesUpdated` + `LogUserRolesUpdated` audit, `RoleObserver`; super-admin bypass posture in policies (standard).
- **Coupons**: configuration + targeting + rules-metadata + distribution admin controllers (post-manual; Phase 03).
- **Dashboard**: 16 cached analytics (300s `Cache::remember`, keys as manual lists) + finance + reconciliation summary (`getReconciliationSummary`: checked/mismatch/pending/resolved/last-run); analytics export endpoints (permissioned).
- **Activity/audit**: spatie activitylog + `ActivityAuditService` (actor resolution, snapshots, context) + recursive `ActivityRedactor` (password/credential/secret/api_key/token families) + 90-day prune; `LogActivityJob` (medium) + sync role-update logging.
- **Queues**: semantic `QueueName::{high,medium}` resolved from `queue.queues.*` config (physical names per-deployment, e.g. catch-high/meem-high) — no hardcoded strings; supervisor consumes the same env values.
- **Realtime**: Pusher broadcasting (configured, enforced connection per commit `54fcbcc`); admin channel auth in `routes/channels.php`.
- **Imports/exports**: ownership-scoped `ImportPolicy` (super-admin or owner for view/cancel/download); prune jobs (imports 14d, failed-jobs 720h); broadcast progress events.
- **Workflows (§13.17)**: COD flow perm changed; invoice flow adds sync-generation + signed access; shipment flow is fulfillment-bound (not POST /shipments); refund flow adds gateway-first + ledger cap + digital guard.

## 6. Architecture

```
ADMIN HTTP (all: api + sanctum + throttle:admin [+ lang]):
  orders/flows/inputs (granular flow perms) | payments (financial perms) |
  gateways (settings perms) | shipments/WMS (shipment perms + warehouse scope) |
  invoices (six grants) | refunds (throttled requests + dual approvals) |
  coupons (config/targeting/rules/distribution) | analytics+export |
  tracking | settings | users/roles (audited)
CROSS-CUTTING: permission middleware (route + controller) → services (authorities unchanged) →
  history/audit rows (sanitized) → activity log (redacted) → notifications/queues
OBSERVABILITY: dashboard caches (300s) + reconcile summary + telescope + failed-job alerts +
  structured logging/metrics (P2-5) + audit trails
```

## 7. Complete Execution Flow

Representative admin flows (mechanics verified in home phases): mark-paid (perm → locked txn → canonical completion with audit context); direct refund (perm → idempotency key → ledgered provider refund → markers/history); invoice correct/cancel/debit (perm → locked service op → timeline); shipment dispatch/deliver/cancel (perm + warehouse scope → transition authority → mirror); flow catalog edits (perm → guarded writes, versioning deferred D7); WMS ops (perm + `denyUnless` → locked service transitions); coupon targeting/distribution ops (perm → validated rule trees → outbox runs); analytics (perm → cached aggregates); imports (owner policy → queued chunked jobs → progress broadcast → prune).

## 8. Business Rules

| # | Rule | Enforcement |
|---|---|---|
| R1 | Financial actions need financial permissions (`payments.mark_paid`, `payments.refund`) | Route middleware + in-service F-1 gate |
| R2 | Status targets need per-target grants (`change-order-status.<code>`) | Options/PATCH/batch controllers |
| R3 | WMS actions need action perm + home-warehouse scope | `WarehouseAccess::denyUnless` (404/409 mapping) |
| R4 | Destructive/audit-relevant actions need reasons + actors | Cancel/force-deliver/correct/debit/refund reason capture + history metadata |
| R5 | Secrets never persist in audit trails | Recursive redactor (exact + substring key families) |
| R6 | Imports are owner-visible (or super-admin) | `ImportPolicy` |
| R7 | Dashboard data may lag 300s (cached) | Documented TTLs (ops awareness) |
| R8 | Role changes are audited synchronously | `LogUserRolesUpdated` |

## 9. Source of Truth / Authorities

Admin actions are thin governors over unchanged domain authorities (order lifecycle, payment completion/refund, invoice, shipment, coupon, inventory) — no parallel admin writers found. Admin-specific authorities: flow catalog tables (admin CRUD), gateway settings store, analytics aggregates (read-model), activity log (append-only), import registry (ownership).

## 10. Database Impact

Admin reads span all domain tables (indexed, paginated, cached for aggregates); admin writes flow through domain transactions (locks as home phases); `activity_log` (+event/batch columns, indexes `2026_09_15_000001`, 90-day prune); `imports` (+indexes, operation_type, prune); notification/device/preference tables (user-scoped). No admin-only destructive schema; queue/failed-job retention configured.

## 11. API Surface

Admin groups (all `v1/admin`, sanctum + `throttle:admin`): tracking, analytics, analytics/export, payment-gateways, orders (+shipment fields), order-statuses, order-flows, order-flow-inputs, payments (refund), warehouses, locations, fulfillments (+items), picking-tasks, batches, packing-tasks, packing-stations, packages, shipments, coupons (config/targeting/rules/distribution), invoices (six ops), refunds (Marvel resource + direct), settings, users/roles, imports/exports. Marvel admin CMS/product/category/brand/media endpoints persist alongside (legacy surface, permissioned).

## 12. Authentication & Authorization

Uniform sanctum + throttle:admin + least-privilege grants; financial/ops separation (picker/packer vs financial perms by seeding); warehouse confinement; super-admin escape hatches explicit in policies; compat grants (`update-order-status` alongside granular codes during transition); device/notification surfaces owner-scoped. No admin endpoint found without auth (legacy public shipment surface unrouted).

## 13. Validation

Admin inputs validated per FormRequest family (flow upserts, batch inputs, refund requests, gateway settings, invoice correct/debit, WMS requests, coupon targeting); unknown flow keys rejected; protected shipment keys rejected; reason fields required where audited; amounts ledger-checked in minor units.

## 14. Transactions

Admin mutations join domain transactions (locks as home phases); catalog edits guarded with in-flight protections; wallet/balance updates locked and atomic within approval transactions; analytics/export read-only; activity/audit writes failure-isolated (reported, never blocking).

## 15. Concurrency

Inherited domain protections (order locks, claim atomicity, ledger caps, idempotent refunds, label backstop, claim leases). Admin-specific: concurrent flow-definition edits guarded (versioning deferred D7); concurrent approvals serialized (refund claim); concurrent WMS claims conflict → 409. Runtime proof absent (standard limitation).

## 16. Async / Queues / Events

Admin-triggered work fans out via the same queued listeners as customer flows (invoice PDF, notifications, fulfillment release, credit notes, outbox publishing); imports/exports chunked with progress broadcasts; failed jobs observable (`AdminQueueJobFailedNotification`, Telescope); prune schedules bounded (activity 90d, imports 14d, failed 720h, products purge 30d).

## 17. Error Handling

Permission failures → 403 (or 404 anti-enumeration in WMS); validation → 422 with maps; domain refusals → 422 with messages; poison records never abort batch/reaper runs (reported, skipped); audit/log failures never break admin actions; provider failures → 422/400 with sanitized messages (no secret/config leakage).

## 18. Security

- Least-privilege grants per action; financial/ops separation; warehouse confinement; anti-enumeration.
- Reason/actor capture on sensitive mutations; redacted audit trails.
- Throttles on admin groups + abuse-prone endpoints (refunds 5/min, uploads, sensitive ops).
- Signed/expiry-bounded document URLs instead of session-passing.
- No secrets in code/logs/reports (redactor + logging discipline verified).
- Super-admin bypasses explicit and narrow (policies).

## 19. Performance

Dashboard aggregates cached 300s; admin lists paginated/filtered/sorted with indexes; WMS reads eager-loaded; bulk operations chunked; queue fan-out keeps admin requests fast; reconcile/export jobs scheduled off-peak where configured. No admin N+1 identified on primary paths.

## 20. Tests & Verification

Admin-adjacent suites: `AdminInvoiceAuthTest` (+ invoice admin suite), `WmsAuthorizationGateTest`, `WmsRbacFoundation/CompletionTest`, `GatewaySettingsAdminTest`, `CouponAdminListFilterTest`, `AdminDistributionApiTest`, `AdminOrderTest`, `AdminShipmentIntegration` (order-tracking), import/export suites, `WarehouseSecurityTest`, `BarcodeResolverTest`. **None executed** (environment).

## 21. Edge Cases

Covered: permission-missing actors (403/404), cross-warehouse attempts (denied), disabled-gateway in-flight payments (verify-exempt), over-cap refunds (rejected), already-approved re-approval (rejected), terminal-state mutations (flow/DAG/invoice-machine refusals), protected-key mass updates (rejected), poison batch items (skipped + reported), expired claims/reservations (swept), missing invoices on refund (warn + skip).

## 22. Potential Bugs

### Finding F-01
- Severity: MEDIUM
- Type: DOCUMENTATION
- Status: PROVEN
#### Finding
The manual's mechanics are stale across §§13.1–13.5, 13.13–13.17: legacy transition maps (now flow-gated), `update-order-status` for mark-paid (now `payments.mark_paid`), five-permission invoice list (now six), permissionless shipment CRUD (now admin-permissioned fulfillment-bound), `inventory_restored_at` guard framing (now state-claim), hardcoded queue names/workers (now semantic + config-resolved), public invoice verify (now authenticated), and the §13.18 file index (paths/counts predate refactorings).
#### Evidence
Phase 05/01/07/09/10/08 drift sections (each verified); current routes/permissions enumerated in §11 above.
#### Why it matters
Admin runbooks with wrong permissions/endpoints/guard models cause failed operations and misdiagnosis during incidents.
#### Current behavior
Correct implementation; stale admin reference.
#### Recommended future action
Refresh the admin manual's mechanics + workflows + file index (on explicit docs request).
> NOTE: This audit is READ-ONLY. No fix was applied.

### Finding F-02
- Severity: INFO
- Type: DOCUMENTATION
- Status: PROVEN
#### Finding
The post-manual admin surface — flow catalog/inputs admin, coupon targeting/rules/distribution admin, gateway settings admin, 10 WMS controllers, analytics export, direct-refund admin, signed document flows — has no manual home. The manual covers ~60% of the current admin surface by endpoint count (estimate from route inventory).
#### Evidence
Route inventory (§11) vs manual §§13.1–13.18 coverage.
#### Why it matters
Undocumented admin capabilities are underused and mis-operated.
#### Current behavior
Functional; undocumented in the phase manual.
#### Recommended future action
Extend the admin manual with the new surfaces (on explicit docs request).
> NOTE: This audit is READ-ONLY. No fix was applied.

### Finding F-03
- Severity: MEDIUM
- Type: TEST GAP
- Status: PROVEN (gap) / UNPROVEN (runtime)
#### Finding
Admin authorization is permission-dense (dozens of grants × warehouse scopes × financial separation) but its test bench — RBAC/gate suites, invoice-auth, gateway-settings, distribution-admin — was not executed here. A miswired grant (fail-open) or scope leak would be silent without runtime proof.
#### Evidence
Test inventory (Phase 20 list); execution impossible (MySQL-only).
#### Why it matters
Authorization is the admin surface's primary control; it needs proof, not just wiring.
#### Current behavior
Wiring verified statically; enforcement unproven at runtime.
#### Recommended future action
Execute the full admin/RBAC test bench against real MySQL in CI with a permission-matrix report; add negative tests for any grant lacking one.
> NOTE: This audit is READ-ONLY. No fix was applied.

## 23. Potential Failure Scenarios

- **Grant misconfiguration**: fail-closed middleware (403/404) is the default posture; compat grants narrow the risk of lockouts during transition.
- **Dashboard staleness**: 300s caches can show pre-completion states post-payment (ops awareness; manual refresh path exists via uncached reconciliation summary).
- **Worker/queue mismatch**: semantic names + env-resolved physical names + supervisor parity by convention — a renamed queue without worker update silently parks jobs (deployment checklist item; `QUEUE_CONFIGURATION_REFACTOR_AUDIT.md` exists as prior evidence).
- **Audit-table pressure**: 90-day prune bounds growth; high-volume status fan-out is single-row inserts.
- **Admin double-submit**: idempotency keys (refunds), atomic claims (refunds/approvals), transition guards (idempotent noops) cover the financial paths.

## 24. Documentation Drift

Manual accurate for: coverage structure, dashboard metric names/TTLs, activity-log shape, reconciliation purpose, CMS/product/coupon/promotion management framing, settings models. Stale: permissions, endpoints, guards, queue names, transition authorities, workflows (§13.17 all four), file index, and all post-manual surfaces (F-01/F-02).

## 25. Dependencies

- **Depends on**: all domain authorities (admin is a governor, not an owner), auth/permission system, warehouse master data, settings store, queue/mail/broadcast infra.
- **Consumed by**: Phase 14 (support tooling overlaps), ops runbooks, dashboard consumers.
- **Shared tables**: all domain tables (read) + activity/import/notification stores.
- **Shared services**: domain authorities + `DashboardService`, `ActivityAuditService`, `GatewaySettingsService`, `WarehouseAccess`.

## 26. Out of Scope

Marvel CMS content modeling, frontend admin UX, worker/supervisor deployment specifics, backup/DR, super-admin provisioning ceremony, API documentation generation (safety mode).

## 27. Residual Risks

1. Admin manual mechanically stale (F-01) + new surfaces undocumented (F-02).
2. Authorization unproven at runtime (F-03).
3. Queue/worker rename drift relies on convention.
4. Dashboard 300s staleness during incidents.
5. Compat grants (`update-order-status`) widen the transition surface until removed.

## 28. Evidence / Source Files

Manual: `HEAD:docs/production-manual/PHASE-13-ADMIN-EXPERIENCE.md` (710 lines, read fully, temp extract). Code: `routes/api.php:219-410` (admin groups verified) + Marvel `Rest/Routes.php` (CMS/admin legacy surface); `app/Http/Controllers/Api/Admin/*` (14 + 10 WMS controllers inventoried); `DashboardService.php` (cache keys/TTLs + reconcile summary verified); `ActivityAuditService/ActivityContext/ActivitySnapshot/ActorResolver/ActivityRedactor` (redaction verified); `ImportPolicy.php` (ownership verified); `QueueName.php` (semantic resolution verified); `LogActivityJob`; `routes/channels.php` (referenced); permission families (`Marvel\Enums\Permission`, `PermissionSeeder` warehouse roles — referenced); home-phase authorities (Phases 01–11). Tests (admin/RBAC suites listed, not executed).

## 29. Final Assessment

**Verdict: PASS WITH RESIDUAL RISKS.**

The admin experience is comprehensively gated, financially separated, audited with redaction, and observably healthy in design — a genuine enterprise operations surface. It cannot reach PASS because its manual misdescribes Permissions/endpoints/guards auditors rely on, new surfaces are undocumented, and the permission-dense authorization matrix lacks runtime proof. No blocking defect found in admin controls.
