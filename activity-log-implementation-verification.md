# Activity Log — Implementation Verification

**Date**: 2026-09-15
**Phase**: Implementation complete (Phases 1–8 per plan)

---

## 1. Files changed

### New files
| Path | Purpose |
|---|---|
| `app/Audit/ActivitySnapshot.php` | immutable, self-contained audit payload value object |
| `app/Audit/ActorResolver.php` | actor vs executor vs system resolution |
| `app/Audit/ActivityContext.php` | scoped request/execution context |
| `app/Audit/ActivityRedactor.php` | recursive sensitive-key redaction |
| `app/Audit/ActivityAuditService.php` | canonical writer (`record`, `recordModel`, `recordSubject`, `recordBatch`, `dispatch`) |
| `app/Http/Middleware/RequestIdMiddleware.php` | propagate/generate `X-Request-ID` + stamp context |
| `app/Console/Commands/PruneActivityLog.php` | 90-day chunked retention |
| `database/migrations/2026_09_15_000001_add_activity_log_indexes.php` | composite + created_at indexes |
| `tests/Feature/ActivityLogForensicTest.php` | forensic regression tests (8) |

### Modified files
| Path | Change |
|---|---|
| `app/Jobs/LogActivityJob.php` | snapshot-based; never re-fetches subject |
| `app/Observers/{Product,Category,Brand,Coupon,FlashSale,Promotion,Role,User,PickupLocation}Observer.php` | synchronous `ActivityAuditService::recordModel`; delete/restore/forceDelete snapshots |
| `app/Listeners/{SendNewOrderNotification,SendOrderCancelledNotification,SendOrderStatusChangedNotification,SendPaymentFailedNotification,SendPaymentSucceededNotification,LogUserRolesUpdated}.php` | `recordSubject` preserving business actor |
| `app/Listeners/LogInvoiceCreated.php` | activity log instead of `Log::info` only |
| `app/Http/Kernel.php` | register `RequestIdMiddleware` in `api` group |
| `app/Console/Kernel.php` | schedule `activitylog:prune` quarterly |
| `config/activitylog.php` | add `retention_days` (env `ACTIVITY_LOG_RETENTION_DAYS=90`) |
| `packages/marvel/src/Http/Controllers/ProductController.php` | `destroyAll` permission + confirmation + batch log; `destroyBulk` batch log |
| `packages/marvel/src/Database/Repositories/ProductRepository.php` | `$request->only(WRITABLE_FIELDS)`; relationship sync audit |
| `packages/marvel/src/Database/Repositories/OrderRepository.php` | inventory `decrement` audit |
| `packages/marvel/src/Jobs/ImportProductsJob.php` | `import_started`/`import_completed`/`import_failed`/`rollback` batch audit |
| `packages/marvel/src/GraphQL/Mutations/ProductMutator.php` | permission check (super_admin or `*_product`) |
| `packages/marvel/src/Enums/Permission.php` | `DELETE_ALL_PRODUCTS` |
| `packages/marvel/src/Rest/Routes.php` | register `GET /api/v1/logs/activity` |
| `packages/marvel/src/Http/Controllers/SettingsController.php` | settings audit (old/new) |
| `resources/lang/{en,ar}/activity.php` | new event keys |
| `resources/lang/{en,ar}/message.php` | `DESTROY_ALL_CONFIRMATION_REQUIRED` |
| `tests/Feature/ActivityLogApiTest.php` | rewritten to positive tests (7) |

---

## 2. Architecture implemented

```
Request → RequestIdMiddleware → ActivityContext (request scope)
Mutation → Observer/Service/Listener → ActorResolver
        → ActivitySnapshot (subject_type/id, event, old, new, actor, executor, context, reason, batch_uuid, import_id)
        → ActivityAuditService → ActivityRedactor → Spatie Activity model (append-only)
           ├─ synchronous: CRUD, deletes, settings, relationships, inventory
           └─ queued (LogActivityJob): business events carry full snapshot
```

- **Snapshot before mutation**: observers capture `getAttributes()` (old) at dispatch; `LogActivityJob` carries the snapshot and never re-fetches the subject.
- **Subject survival**: `ActivityAuditService` persists via the `Activity` model directly, so `subject_type`/`subject_id` survive hard delete.
- **Actor vs executor**: `ActorResolver` separates business actor (human/system) from technical executor (job/command/scheduler). Queued listeners pass `causer` explicitly to preserve the original business actor.

---

## 3. Mutation coverage

| Operation | Before | After | Evidence |
|---|---|---|---|
| Product create/update/status | logged (old/new) | logged + context/actor | `ProductObserver` |
| Product delete (soft) | **dropped** | **survives** | `ProductObserver::deleted` + snapshot |
| Product forceDelete | **dropped** | **survives** | `ProductObserver::forceDeleted` |
| Scheduled purge | **dropped** | **survives** | `forceDeleted` observer fires on `forceDelete()` |
| Import rollback | **dropped** | **survives** | `rollbackCreatedData` forceDelete → observer |
| Bulk delete / destroyAll | none | batch activity + confirmation + permission | `ProductController` |
| Category/Brand/Coupon/Promotion/Role/User/FlashSale/PickupLocation delete | dropped | survives | respective observers |
| Settings update | none | `settings_updated` old/new | `SettingsController` |
| Product relations sync | none | `product_*_synced` old/new ids | `ProductRepository::syncRelationAudited` |
| Inventory decrement | none | `inventory_decremented` | `OrderRepository::deductStock` |
| Order lifecycle | logged | logged + actor preserved | order listeners |
| Invoice created | `Log::info` only | activity `invoice_created` | `LogInvoiceCreated` |
| Import lifecycle | none | `import_started/completed/failed/rollback` | `ImportProductsJob` |
| Mass assignment | `$request->except` | `$request->only(WRITABLE_FIELDS)` | `ProductRepository` |
| GraphQL product mutation auth | absent | enforced | `ProductMutator::authorize` |

---

## 4. Delete verification

| Path | Result |
|---|---|
| soft delete | `deleted` activity persists with `subject_type/subject_id` (subject hidden but id preserved) |
| force delete | `forceDeleted` activity persists (row gone, snapshot retained) |
| scheduled purge | `products:purge-old-deleted` forceDelete → `forceDeleted` observer fires → activity persists |
| import rollback | `rollbackCreatedData` forceDelete → observer → activity persists |
| bulk delete | `bulk_delete` batch activity + per-product `deleted` rows |
| destroyAll | `destroy_all` batch activity + `DELETE_ALL_PRODUCTS` permission + `confirm` required |

Verified by `ActivityLogForensicTest` (snapshot survives deleted/force-deleted subject, batch record with import_id).

---

## 5. Actor verification

| Context | actor | executor | evidence |
|---|---|---|---|
| HTTP user | human (user_id, type) | null | `ActorResolver` + `RequestIdMiddleware` |
| GraphQL user | human (via context user + permission check) | null | `ProductMutator` |
| Queue listener | human (business causer passed explicitly) | `queue` + job name | order/payment listeners |
| Scheduler | system | `scheduler` | `PruneActivityLog` `source=scheduler` |
| Import job | human (created_by) | `queue`/`import` + import_id | `ImportProductsJob` |

---

## 6. Import verification

- `import_started` / `import_completed` / `import_failed` / `rollback` batch activities in `log_name=imports`.
- Each carries `properties.import_id`, `context.batch_uuid` + `import_id`, and `causer = created_by`.
- Queryable via `log_name=imports` + `properties->import_id`.

---

## 7. Retention verification

- `activitylog:prune --days=90` (default from `config('activitylog.retention_days')`).
- Chunked delete (`limit($chunk)` loop), idempotent, safe for MySQL/TiDB.
- Scheduled quarterly: `->cron('0 3 1 */3 *')` with `withoutOverlapping()`.
- Self-audit: single `activity_pruned` summary (`retention_days`, `cutoff`, `deleted_count`, `source=scheduler`), written after deletion so it is not self-deleted.
- Verified: old (>90d) rows deleted, recent rows preserved, `activity_pruned` present, rerun idempotent.

---

## 8. Security verification

- **GraphQL authorization**: `ProductMutator` enforces super_admin or the relevant `*_product` permission before delegating to controller logic.
- **destroyAll**: dedicated `DELETE_ALL_PRODUCTS` permission + `confirm` boolean + batch activity + soft-delete only.
- **Mass assignment**: product `store`/`update` use `$request->only(WRITABLE_FIELDS)`; `sku`, `stock_quantity`, `reserved_quantity`, `sold_quantity` excluded.
- **Redaction**: recursive `ActivityRedactor` removes `password`, `*_token`, `*_secret`, `*_key`, credentials — verified in tests (old/new nested).
- **Immutability**: no write/update/delete route for `activity_log`; only `GET /logs/activity` (read, permission-gated).

---

## 9. Tests

Executed:

| Suite | Result |
|---|---|
| `ActivityLogForensicTest` | **8 passed, 0 failed** (25 assertions) |
| `ActivityLogApiTest` | **7 passed, 0 failed** (17 assertions) |
| `AnalyticsServiceTest` (migrations smoke, incl. new index migration) | **3 passed, 0 failed** |
| Combined `ActivityLogForensicTest|ActivityLogApiTest` | **15 passed, 0 failed** (42 assertions) |

Pre-existing unrelated failures observed in `BrandApiTest`/`AttributeApiTest` (6) — route/locale issues (`attribute-values` resource commented out; Arabic vs English brand name assertion). These are not introduced by this change and are outside the activity-log scope.

---

## Known limitations

- Import/export child-level per-product audit is limited to **batch** activities (by design, to avoid millions of rows). Risky per-product mutations (status→inactive, price/inventory changes) are not yet emitted as child activities — recommended follow-up.
- `RefreshProductPricingCommand` (`updateQuietly`) and `CategoryHierarchyService` (`saveQuietly`) remain quiet by design and are not yet paired with a batch/semantic audit — documented, follow-up recommended.
- Relationship audit covers product relations (categories/brands/tags/banners/sliders/flash_sales); category/brand/banner `products()->sync` and entitlement `syncWithoutDetaching` not yet instrumented.
- Static request context (`ip`, `user_agent`, `route`) is captured only for HTTP requests; queue/console contexts carry `source`/`job`/`command`/`import_id` but not IP (correctly null).

---

## Remaining risks

- Composite indexes must be validated on production TiDB before deploy (migration is additive and reversible).
- Synchronous activity writes add one insert per admin CRUD mutation (accepted; low volume).
- `ActivityContext` is a process-scoped singleton reset by middleware; long-lived workers should reset context per job (imports set it explicitly).
