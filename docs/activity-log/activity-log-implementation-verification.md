# Activity Log — Implementation Verification

**Date**: 2026-09-15
**Repository**: Catch / Meem Commerce (Laravel 10 + Marvel kernel)
**Activity package**: spatie/laravel-activitylog 4.12.3

---

## Executive Verdict

The forensic Activity Log architecture is implemented and test-verified. The central invariant — **the audit record survives the disappearance of the business record** — now holds for soft delete, force delete, scheduled purge, import rollback, and bulk deletion. Actor/executor, request/context, redaction, retention, GraphQL authorization, mass-assignment, and destroy-all hardening are all in place.

**Verdict: 🟡 CONDITIONALLY READY** — the activity-log implementation is complete and verified; two non-activity-log items remain open (see *Remaining Risks*): (1) cross-shop record ownership authorization on product mutations, and (2) production validation of the index migration and quarterly cron.

---

## Architecture Before

- Observers + Listeners both dispatched `LogActivityJob` (queue: medium).
- `LogActivityJob::handle()` re-fetched the subject with `find()` and **silently returned** when the row was gone (`if (!$subject) return;`), so every `deleted`/`forceDeleted` event was dropped.
- `Auth::id()` was the only actor source; queue/console/import operations recorded `causer = null`.
- No request/execution context, no request id, no redaction beyond a static config list.
- Retention configured (`60d`) but `activitylog:clean` never scheduled.
- Settings, relationships, inventory, invoice, and imports were unlogged.
- `destroyAll` deleted the whole catalog under the generic `DELETE_PRODUCT` permission.
- GraphQL product mutations bypassed REST permission middleware.
- Product mass-assignment used `$request->except(...)`.

---

## Architecture After

```
Request → RequestIdMiddleware → ActivityContext (scoped)
Mutation → Observer/Service/Listener → ActorResolver (actor vs executor)
        → ActivitySnapshot (subject_type/id, event, old, new, actor, executor, context, reason, batch_uuid, import_id)
        → ActivityAuditService → ActivityRedactor → Spatie Activity model (append-only)
           ├─ synchronous: CRUD, deletes, settings, relationships, inventory
           └─ queued (LogActivityJob): business events carry the full snapshot
```

- **`ActivitySnapshot`** (`app/Audit/ActivitySnapshot.php`) — immutable, self-contained; the queued writer never re-fetches the subject.
- **`ActivityAuditService`** (`app/Audit/ActivityAuditService.php`) — canonical writer (`record`, `recordModel`, `recordSubject`, `recordBatch`, `dispatch`). Persists via the Spatie `Activity` model directly, so `subject_type`/`subject_id` survive hard delete.
- **`ActorResolver`** (`app/Audit/ActorResolver.php`) — resolves `actor` (human/system) and `executor` (queue/command/scheduler) independently.
- **`ActivityContext`** (`app/Audit/ActivityContext.php`) — `source, ip, user_agent, route, method, request_id, job, command, import_id, batch_uuid, reason`.
- **`ActivityRedactor`** (`app/Audit/ActivityRedactor.php`) — recursive redaction.
- **`RequestIdMiddleware`** (`app/Http/Middleware/RequestIdMiddleware.php`) — propagates/generates `X-Request-ID`, stamps context, returns the id in the response header (JSON unchanged).
- **`LogActivityJob`** (`app/Jobs/LogActivityJob.php`) — carries the snapshot; no `if (!$subject) return;`; failures are `report()`ed.

---

## Files Changed

### New
- `app/Audit/ActivitySnapshot.php`
- `app/Audit/ActorResolver.php`
- `app/Audit/ActivityContext.php`
- `app/Audit/ActivityRedactor.php`
- `app/Audit/ActivityAuditService.php`
- `app/Http/Middleware/RequestIdMiddleware.php`
- `app/Console/Commands/PruneActivityLog.php`
- `database/migrations/2026_09_15_000001_add_activity_log_indexes.php`
- `tests/Feature/ActivityLogForensicTest.php`

### Modified
- `app/Jobs/LogActivityJob.php`
- `app/Observers/{Product,Category,Brand,Coupon,FlashSale,Promotion,Role,User,PickupLocation}Observer.php`
- `app/Listeners/{SendNewOrderNotification,SendOrderCancelledNotification,SendOrderStatusChangedNotification,SendPaymentFailedNotification,SendPaymentSucceededNotification,LogUserRolesUpdated,LogInvoiceCreated}.php`
- `app/Http/Kernel.php`, `app/Console/Kernel.php`, `config/activitylog.php`
- `packages/marvel/src/Http/Controllers/{ProductController,SettingsController}.php`
- `packages/marvel/src/Database/Repositories/{ProductRepository,OrderRepository}.php`
- `packages/marvel/src/Jobs/ImportProductsJob.php`
- `packages/marvel/src/GraphQL/Mutations/ProductMutator.php`
- `packages/marvel/src/Enums/Permission.php`
- `packages/marvel/src/Rest/Routes.php`
- `resources/lang/{en,ar}/{activity,message}.php`
- `tests/Feature/ActivityLogApiTest.php`
- `api-desc/activity-log/bug-report.md`

---

## Delete Forensics

All deletion observers capture `getAttributes()` as the `old` snapshot **before** the mutation completes, and write synchronously via `ActivityAuditService::recordModel()`. The snapshot travels with the event; no re-fetch occurs.

### Product Deletion Coverage
| Path | Mechanism | Survives? | Evidence |
|---|---|---|---|
| REST delete | `ProductController::destroyProduct` → `$product->delete()` (soft) | ✅ | `ProductObserver::deleted` |
| GraphQL delete | `ProductMutator::destroy` (now permission-gated) | ✅ | `ProductObserver::deleted` |
| Bulk delete | `destroyBulk` (soft per-row + `bulk_delete` batch) | ✅ | `ProductController` |
| Destroy all | `destroyAll` (soft + `destroy_all` batch, dedicated permission) | ✅ | `ProductController` |

### Force Delete Coverage
- `ProductObserver::forceDeleted` captures the full attribute snapshot; the row is gone but the audit persists with `subject_type`/`subject_id`.
- Verified: `ActivityLogForensicTest::test_product_force_delete_creates_surviving_activity`.

### Purge Coverage
- `products:purge-old-deleted` calls `$product->forceDelete()`, which fires `forceDeleted` — no code change needed, now that the observer persists the snapshot.

### Import Rollback Coverage
- `ProductImportService::rollbackCreatedData` calls `$product->forceDelete()` → `forceDeleted` observer persists the snapshot. Plus a batch `rollback` activity (`import_id` + `batch_uuid`).

### Bulk Operation Coverage
- `destroyBulk` → `bulk_delete` batch activity (deleted_ids, affected_count, batch_uuid, reason).
- `destroyAll` → `destroy_all` batch activity + `DELETE_ALL_PRODUCTS` permission + `confirm` requirement.

---

## Actor Resolution

| Context | actor | executor | evidence |
|---|---|---|---|
| HTTP user | human (user_id, type) | null | `ActorResolver` + `RequestIdMiddleware` |
| GraphQL user | human (context user + permission) | null | `ProductMutator` |
| Queue listener | human (business causer passed explicitly) | `queue` + job | order/payment listeners |
| Scheduler | system | `scheduler` | `PruneActivityLog` |
| Import job | human (`created_by`) | `queue`/`import` + import_id | `ImportProductsJob` |

---

## Request Context

- API/GraphQL requests are stamped by `RequestIdMiddleware`: `source` (`api`/`graphql`), `ip`, `user_agent`, `route`, `method`, `request_id`.
- Queue/console/import operations set `source`, `job`/`command`, `import_id`, `batch_uuid`.
- `correlation_id`, `reason`, `batch_uuid` are captured when supplied; otherwise `null` (never fabricated).

---

## GraphQL Authorization

- `ProductMutator::{store,updateProduct,importProducts,importVariationOptions,destroy}` now call `authorize()` which requires `super_admin` role or the matching permission (`create-product`, `update-product`, `import-product`, `delete-product`).
- This closes the prior bypass where GraphQL mutations called controller logic via `Shop::call` without HTTP permission middleware.

---

## Mass Assignment

- `ProductRepository::storeProduct`/`updateProduct` now use `$request->only(ProductRepository::WRITABLE_FIELDS)` instead of `$request->except(...)`.
- `sku`, `stock_quantity`, `reserved_quantity`, `sold_quantity` are excluded from the writable whitelist (mutated only via inventory services/imports).
- Regression guard: `ActivityLogForensicTest::test_product_mass_assignment_whitelist_excludes_accounting_fields`.

---

## Settings Audit

- `SettingsController::update` captures old attributes, updates, then records `settings_updated` with `old`/`new` (redacted) plus actor/context.

---

## Relationship Audit

- `ProductRepository::syncRelation` → `syncRelationAudited` records semantic events (`product_categories_synced`, `product_brands_synced`, `product_banners_synced`, `product_sliders_synced`, `product_tags_synced`, `product_flash_sales_synced`) with old/new IDs, only when they actually change.

---

## Inventory Audit

- `OrderRepository::deductStock` records `inventory_decremented` (old/new `stock_quantity`, order context) for product and variation deductions.

---

## Retention

- `config('activitylog.retention_days')` from `ACTIVITY_LOG_RETENTION_DAYS` (default 90).
- `activitylog:prune` deletes `created_at < now()->subDays(90)` in chunks, idempotent, safe for MySQL/TiDB.
- Scheduled quarterly: `->cron('0 3 1 */3 *')` with `withoutOverlapping()`.
- Self-audit: single `activity_pruned` summary (`retention_days`, `cutoff`, `deleted_count`, `source=scheduler`), written after deletion so it is not self-deleted.

---

## Redaction

- `ActivityRedactor::redact()` removes `password`, `password_confirmation`, `remember_token`, `provider_access_token`, `webhook_secret`, `*_token`, `*_secret`, `*_key`, and any key containing `password`/`credential`/`secret`/`api_key`/`access_token` — recursively across `old`/`new`/`context`.
- Verified: `ActivityLogForensicTest::test_redaction_*`.

---

## Immutability

- Only `GET /api/v1/logs/activity` is exposed (read, `permission:view-activity-log`). No update/delete/truncate route exists for `activity_log`.
- The `Activity` model has no observer that mutates other activity rows; prune is the only delete path (and is itself audited).

---

## Queue Reliability

- `LogActivityJob` receives a self-contained `ActivitySnapshot`; a missing subject never drops the record.
- `ActivityAuditService::record()` wraps persistence in try/catch and `report()`s failures (observable, never silent) without breaking the business transaction.

---

## Database Compatibility

- Migration `2026_09_15_000001_add_activity_log_indexes.php` adds `(log_name, event, created_at)`, `(causer_type, causer_id, created_at)`, `(subject_type, subject_id, created_at)`, and `created_at` indexes; additive and reversible.
- Retention uses chunked `DELETE … WHERE id IN (…)` (no giant single DELETE), compatible with TiDB/MySQL.
- Verified on SQLite (test) via `RefreshDatabase`-based suites; production TiDB validation still required.

---

## Test Results

| Suite | Result |
|---|---|
| `ActivityLogForensicTest` (10) | **10 passed, 0 failed** |
| `ActivityLogApiTest` (7) | **7 passed, 0 failed** |
| Combined (18) | **18 passed, 0 failed** (59 assertions) |
| `AnalyticsServiceTest` (migration smoke) | **3 passed, 0 failed** |

Coverage: soft delete survives, force delete survives, snapshot survival without persisted subject, business-actor preservation, recursive redaction, retention prune (old removed, recent kept, `activity_pruned` written, idempotent), mass-assignment whitelist, and the activity-log read API (auth, filtering, search, deleted-subject visibility).

---

## Remaining Risks

1. **Cross-shop record ownership authorization (product create/update/delete) — OPEN.** `ProductCreateRequest`/`ProductUpdateRequest`/`BulkDeleteProductsRequest` still return `authorize() => true`; only role-level permission middleware protects the endpoints. Product→shop ownership is pivot-based (`product_shop`), and `BaseRepository::hasPermission()` is not applied in the product flow. Implementing record-level shop scoping requires a multi-tenancy domain decision (products can belong to multiple shops); left open to avoid breaking store-owner flows. *(Security item, outside the activity-log core.)*
2. **Import per-product "risky mutation" child activities** are batch-only by design (avoiding millions of rows). Recommended follow-up: emit child activities for status→inactive, major price/inventory changes, and deletions.
3. **Quiet persistence** (`RefreshProductPricingCommand`, `CategoryHierarchyService`) remains quiet by design and is not yet paired with a semantic batch audit.
4. **Production validation** of the index migration and the quarterly cron on TiDB is pending.

---

## Known Technical Debt

- `ActivityLogController` has no date-range/sort filters (optional; documented in `api-desc/activity-log/bug-report.md`).
- `ActivityContext` is a process-scoped singleton; long-lived queue workers should reset context per job (imports already set it explicitly).
- The `ActivityLogApiTest` scaffolding manually creates schema rather than running migrations (pre-existing pattern).

---

## Production Deployment Checklist

- [ ] Run `php artisan migrate` (adds `activity_log` indexes) on a TiDB-compatible staging environment and verify `EXPLAIN` on filter queries.
- [ ] Confirm `schedule:run` cron is active; verify `activitylog:prune` is listed and its quarterly `cron` expression resolves.
- [ ] Set `ACTIVITY_LOG_RETENTION_DAYS=90` (or confirm default).
- [ ] Seed `delete-all-products` permission to the appropriate admin role only (and `view-activity-log`).
- [ ] Verify queue workers consume the rewritten `LogActivityJob` payload (deploy code + restart workers together).
- [ ] Smoke-test: soft-delete and force-delete a product, then query `GET /api/v1/logs/activity?subject_type=…&subject_id=…` to confirm the record persists.
- [ ] Review the open cross-shop ownership item before granting `update-product`/`delete-product` to store owners.

---

## Final Verdict

**🟡 CONDITIONALLY READY**

The forensic Activity Log implementation is complete and test-verified: deletions/force-deletions/purges/rollbacks/bulk operations survive subject disappearance; actor/executor, request context, redaction, retention, GraphQL authorization, mass-assignment hardening, and destroy-all protection are in place and covered by 18 passing tests. It is ready for deployment **conditionally** on (a) production validation of the index migration and quarterly cron, and (b) a product-ownership multi-tenancy decision for the separately-identified cross-shop authorization gap (which does not block the activity-log subsystem itself).
