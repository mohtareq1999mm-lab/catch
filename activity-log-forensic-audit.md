# Activity Log — Phase 0 Forensic Audit

**Repository**: Catch / Meem Commerce (Laravel 10 / Marvel kernel / Spatie Activitylog 4.12.3)
**Audit date**: 2026-09-15
**Phase**: 0 (read-only). No code, migration, model, or response was modified.

---

## 1. Existing Architecture

### 1.1 Package & wiring

| Item | Value | Evidence |
|---|---|---|
| Package | `spatie/laravel-activitylog` | `composer.json:35` |
| Version | **4.12.3** | `composer.lock:9988` |
| Activity model | `Spatie\Activitylog\Models\Activity` (default) | `config/activitylog.php:25` |
| Table | `activity_log` | `database/migrations/2026_07_05_080102_create_activity_log_table.php` |
| Extra columns | `event` (080103), `batch_uuid` (080104) | migrations |

`LogsActivity` trait is **not** used anywhere. Logging is implemented via custom Observers + Listeners, both dispatching a single queued writer.

### 1.2 Write path (dual path)

```
Model event  → Observer → LogActivityJob::dispatch(...)  ┐
Event        → Listener → LogActivityJob::dispatch(...)  ┘   queue: medium
                                                            │
                                       LogActivityJob::handle() → activity()->log()
```

- Sole writer: `app/Jobs/LogActivityJob.php`.
- Observer registration: `app/Providers/EventServiceProvider.php::$observers`.
- Listener registration: `app/Providers/EventServiceProvider.php::$listen`.

### 1.3 Configuration (`config/activitylog.php`)

| Key | Value | Status |
|---|---|---|
| `enabled` | `env('ACTIVITY_LOGGER_ENABLED', true)` | on |
| `delete_records_older_than_days` | `60` | **inert — `activitylog:clean` never scheduled** |
| `default_auth_driver` | `sanctum` | |
| `subject_returns_soft_deleted_models` | `true` | **declared, but not honored by `LogActivityJob`** |
| `default_except_attributes` | `password, remember_token, created_at, updated_at` | minimal, static |
| `default_except_methods` | HEAD/OPTIONS/TRACE/CONNECT/GET | unused by Spatie v4 |

### 1.4 Read path

- `packages/marvel/src/Http/Controllers/ActivityLogController.php` → `GET /logs/activity` (`permission:view-activity-log`).
- `packages/marvel/src/Http/Resources/ActivityLogResource.php`.
- **Existing tests are vacuous**: `tests/Feature/ActivityLogApiTest.php` asserts `404` for *every* case (including a valid super-admin fetch). There is no positive test proving the endpoint works.

---

## 2. Complete Mutation Inventory

### 2.1 CREATE

| Entity | Path | Observed/logged? |
|---|---|---|
| Product | `ProductController::store` → `ProductRepository::storeProduct` | ✅ `created` |
| Product (GraphQL) | `ProductMutator::store` → `ProductController@ProductStore` | ✅ (but see §7 auth) |
| Product (import) | `ProductImportService::processProductRow` `saveQuietly` | ❌ |
| Category/Brand/Coupon/Promotion/FlashSale/Role/User/PickupLocation | controllers → repositories | ✅ |
| Order | `OrderCreationService` / `OrderRepository::storeOrder` | ✅ `OrderCreated` event |
| Invoice | `GenerateInvoiceListener` | ⚠️ `Log::info` only |
| Import row | `ProductImportController::import` → `Import::create` | ⚠️ `imports.created_by` only (not activity_log) |
| DigitalAsset | `DigitalAssetService` | ❌ |

### 2.2 UPDATE

| Entity | Path | Observed/logged? |
|---|---|---|
| Product | `ProductRepository::updateProduct` (`$product->update($data)`) | ✅ `updated` (old/new) |
| Product (import) | `saveQuietly` | ❌ |
| Product (bulk pricing) | `RefreshProductPricingCommand::updateQuietly` | ❌ |
| Product variants | `ProductRepository::updateProduct` (delete + recreate) | ⚠️ variant delete bypasses observers |
| Category/Brand/Coupon/Promotion/FlashSale/Role/User | repositories | ✅ |
| Category hierarchy | `CategoryHierarchyService::updateDescendantLevels` `saveQuietly` | ❌ |
| Order status | `OrderRepository::updateOrder` → `changeOrderStatus` | ✅ `OrderStatusChanged` event + `order_status_history` table |
| Order (other fields) | `OrderRepository::updateOrder` | ⚠️ status only; generic diff not logged |
| Settings | `SettingsController::update` | ❌ |
| Currency / CurrencyRate | `CurrencyService`, `CurrencyRateService` | ❌ |
| Country/Governorate bulk status | `bulkStatus()` `whereIn()->update()` | ❌ |
| Cart | `CartRepository::persistCart` `update()` | ❌ |

### 2.3 DELETE

| Entity | Path | Type | Reversible | Logged? |
|---|---|---|---|---|
| Product | `ProductController::destroy` → `$product->delete()` | soft | ✅ | ❌ dropped |
| Product (all) | `ProductController::destroyAll` | soft | ✅ | ❌ |
| Product (bulk) | `ProductController::destroyBulk` | soft | ✅ | ❌ |
| Product (GraphQL) | `ProductMutator::destroy` | soft | ✅ | ❌ |
| Product (purge) | `PurgeOldSoftDeletedProducts` (sched daily 02:30) `forceDelete()` | **permanent** | ❌ | ❌ |
| Product (import rollback) | `ProductImportService::rollbackCreatedData` `forceDelete()` | **permanent** | ❌ | ❌ |
| Category | `CategoryController::destroy` | soft | ✅ | ❌ |
| Category (bulk) | `BulkDeleteCategoriesJob` `$category->delete()` | soft | ✅ | ❌ (imports table only) |
| Brand/Coupon/Promotion/Role/User | controllers | soft (Brand/User) / hard (Coupon/Promotion/Role) | mixed | ❌ |
| Contact (all) | `ContactRepository::deleteAllContacts` `query()->update(['deleted_at'=>now()])` | bulk soft | ⚠️ | ❌ |
| ProductVariant | `ProductRepository.php:156` `where()->delete()` | hard (qb) | ❌ | ❌ |
| CouponReservation | `CouponReservationService` / `ExpireCouponReservations` | hard (qb) | ❌ | ❌ |
| Cart/CartItem | `CartInventoryService` | hard | ❌ | ❌ |
| DeviceToken | `SendFcmNotificationJob:50` `whereIn()->delete()` | hard (qb) | ❌ | ❌ |
| Import files | `PruneImports` | file delete (rows kept) | n/a | n/a |

### 2.4 Relationship mutations (currently unlogged)

| Location | Operation |
|---|---|
| `ProductRepository::syncRelation` | `categories()->sync`, `brands()->sync`, `tags()->sync`, `banners()->sync`, `sliders()->sync`, `flash_sales()->sync/detach` |
| `CategoryRepository::saveCategory/updateCategory` | `products()->sync` |
| `BrandRepository::saveBrand/updateBrand` | `products()->sync` |
| `BannerRepository::createBanner/updateBanner` | `products()->sync` |
| `DigitalFulfillmentService::fulfillOrder` | `assets()->syncWithoutDetaching` |
| Seeders | `attach`/`sync`/`syncWithoutDetaching` (non-runtime) |

### 2.5 Pivot cascades (FK `onDelete('cascade')`)

`coupon_product`, `slider_product`, `banner_product`, `product_tag` cascade on `product_id` (and their counterpart FK). Deleting a coupon/brand/banner/tag hard-deletes pivot rows → removes **product associations** (products can drop out of categories/search facets without the product row being touched).

---

## 3. Every Observer

Registered in `app/Providers/EventServiceProvider.php::$observers`:

| Observer | Model | SoftDeletes | Events logged |
|---|---|---|---|
| `ProductObserver` | Product | ✅ | created, updated, statusChanged, deleted, restored, forceDeleted |
| `CategoryObserver` | Category | ✅ | created, updated, statusChanged, deleted (restored flushes cache only) |
| `BrandObserver` | Brand | ✅ | created, updated, statusChanged, deleted (restored not logged) |
| `CouponObserver` | Coupon | ❌ | created, updated, statusChanged, deleted |
| `FlashSaleObserver` | FlashSale | ✅ | created, updated, statusChanged, deleted, restored, forceDeleted |
| `PromotionObserver` | Promotion | ❌ | created, updated (tracked fields), statusChanged, deleted |
| `RoleObserver` | Role | ❌ | created, updated, deleted |
| `UserObserver` | User | ✅ | created (authed only), updated, statusChanged, deleted, restored, forceDeleted |
| `PickupLocationObserver` | PickupLocation | ✅ | created, updated, statusChanged, deleted |
| `ContentPageObserver` / `SectionObserver` / `SectionTypeObserver` / `SectionTypeSettingObserver` / `StaticPageObserver` / `StaticSectionObserver` | CMS/static | varies | created/updated/deleted |
| `MediaCleanupObserver` | Product/Category/Brand/FlashSale/User/Banner/Review/Shop/Slider | — | media deletion on `deleting`/`forceDeleting` |

**Common defects**: (a) all `deleted`/`forceDeleted` dispatches are dropped downstream (see §5.1); (b) Category/Brand restored handlers do not log; (c) Coupon/Promotion/Role have no restore/forceDelete handlers at all.

---

## 4. Every Listener

### 4.1 Listeners that write activity (`LogActivityJob`)

| Listener | Event | Log event |
|---|---|---|
| `SendNewOrderNotification` | `OrderCreated` | `order_created` |
| `SendOrderCancelledNotification` | `OrderCancelled` | order cancelled |
| `SendOrderStatusChangedNotification` | `OrderStatusChanged` | `order_status_changed` |
| `SendPaymentFailedNotification` | `PaymentFailed` | payment failed |
| `SendPaymentSucceededNotification` | `PaymentSucceeded` | payment succeeded |
| `LogUserRolesUpdated` | `UserRolesUpdated` | `roleUpdated` (old/new roles) |

### 4.2 Listener that should log but does not

| Listener | Event | Gap |
|---|---|---|
| `LogInvoiceCreated` | `InvoiceCreated` | uses `Log::info`, not activity_log |

### 4.3 Other listeners (side effects only, no activity)

Inventory restore (`RestoreProductInventory`), digital fulfillment (`FulfillDigitalProducts`, `RevokePendingDigitalEntitlements`), notifications (many `Send*Notification`), `GenerateInvoiceListener`, `DispatchFrontendCacheInvalidation`, `HandleFailedQueueJob`.

---

## 5. Every Bypass

### 5.1 CRITICAL — delete logs are silently dropped

`app/Jobs/LogActivityJob.php:27`:

```php
$subject = app($this->subjectType)::find($this->subjectId);
if (!$subject) return;   // <-- silently drops
```

- Soft-deleted models (Product, Category, Brand, FlashSale, User, PickupLocation, Order): `find()` applies the SoftDeleting global scope → soft-deleted row not found → dropped.
- Hard-deleted models (Coupon, Promotion, Role): row gone → dropped.
- `forceDeleted` (Product/FlashSale/User) and the scheduled purge: dropped.

This is already known internally (`api-desc/activity-log/bug-report.md` Issues #2, #3) but unfixed. The config key `subject_returns_soft_deleted_models` does not affect the custom job.

**Consequence**: the central forensic requirement ("this product disappeared — what happened?") is not satisfiable today.

### 5.2 Silent-save / quiet-update (bypass observers + Scout)

| Location | Call |
|---|---|
| `ProductImportService.php:420,424,430,440,498,502,512` | `saveQuietly()` |
| `RefreshProductPricingCommand.php:31,41` | `updateQuietly()` |
| `CategoryHierarchyService.php:90` | `saveQuietly()` |
| `Order.php:144` | `saveQuietly()` (order-number backfill) |

### 5.3 Query-builder mutations (bypass observers)

| Location | Operation |
|---|---|
| `OrderRepository.php:345,348` | `->decrement('stock_quantity', …)` — stock deduction |
| `OrderRepository.php:464` | `Order::withoutGlobalScopes()->whereKey()->update(['status'=>…])` |
| `ContactRepository.php:79,83` | `Contact::query()->update(['deleted_at'=>now()])` — bulk delete-all |
| `CountryRepository.php:53`, `GovernorateRepository.php:110` | `whereIn()->update(['status'=>…])` — bulk status |
| `CouponClaimService.php:189` | `where()->update([…])` — expire claims |
| `ProductRepository.php:156` | `ProductVariant::where()->delete()` |
| `CouponReservationService.php:85,94`, `ExpireCouponReservations.php:35` | `where()->delete()` |
| `SendFcmNotificationJob.php:50` | `whereIn()->delete()` |

### 5.4 Relationship sync/attach/detach (never fires model events)

Listed in §2.4.

### 5.5 `$request->except(...)` mass-assignment boundary

`ProductRepository::storeProduct`/`updateProduct` use `$request->except(['images','categories','variants','brands','banners','sliders'])`. Product `$fillable` includes `sku`, `stock_quantity`, `reserved_quantity`, `sold_quantity`, `quantity`, `status`, `has_discount`, etc. — several not covered by FormRequest rules. A caller with `UPDATE_PRODUCT`/`CREATE_PRODUCT` can write unvalidated fillable fields.

### 5.6 Actor resolution gaps

Observers call `Auth::id()` at dispatch. For queue/console/scheduler/import contexts there is no authenticated user → `causer = null`, and no system/executor marker is captured.

---

## 6. Every Missing Audit Path

1. **Deletions** — all dropped (§5.1). Includes product, category, brand, coupon, promotion, role, user, and the scheduled purge + import rollback force-deletes.
2. **Settings** — no observer, no service logging (`SettingsController.php:73`).
3. **Relationship changes** — category/brand/tag/flash-sale `sync`, entitlement `syncWithoutDetaching` (§2.4).
4. **Inventory mutations** — `OrderRepository` stock `decrement`, `OrderService` inventory reservation/restore, `CartInventoryService`.
5. **ProductVariant** — create/delete via query builder and `saveQuietly`.
6. **Order attribute diffs** — only status/created/payment events are logged; generic field updates are not.
7. **Payment/refund attribute changes** — events log high-level only; no old/new diffs.
8. **Currency / CurrencyRate / Shipping / Tax class** — no observers.
9. **Bulk operations** — `destroyAll`, `destroyBulk`, `BulkDeleteCategoriesJob`, `bulkStatus`, `deleteAllContacts` produce no batch activity.
10. **Imports/exports** — `imports` table tracks stats + `created_by`, but nothing in `activity_log`; per-product mutations invisible; `batch_uuid` unused.
11. **`LogInvoiceCreated`** — writes to `Log::info`, not the audit trail.
12. **Read-only operations** — correctly not logged (per requirement).

---

## 7. Security Findings

| # | Sev | Location | Problem | Impact | Fix |
|---|---|---|---|---|---|
| S-1 | CRITICAL | `app/Jobs/LogActivityJob.php:27` | Deletes/force-deletes silently dropped | Untraceable data loss | snapshot payload + `withTrashed()` + subject-less fallback |
| S-2 | HIGH | `ProductController::destroyAll` | Deletes all products with only `DELETE_PRODUCT`, no confirmation, no batch audit | Catalog wipe | dedicated permission + confirmation + batch log + soft-delete-only |
| S-3 | HIGH | `product.graphql` mutations | `deleteProduct`/`createProduct`/`updateProduct` use `@field(resolver: "ProductMutator@…")` with **no `@can`** (contrast `category.graphql:61` `@can(ability:"super_admin")`); mutator calls controller via `Shop::call` bypassing HTTP middleware | Permission bypass via GraphQL | add `@can`/`@guard` or service-level `authorize()` |
| S-4 | HIGH | `ProductRepository::storeProduct/updateProduct` | `$request->except(...)` mass-assigns unvalidated fillable fields (`sku`,`stock_quantity`,`reserved_quantity`,`sold_quantity`) | Arbitrary inventory/accounting writes | `$request->only(...)` + full rules |
| S-5 | MEDIUM | FormRequests (`ProductCreateRequest`, `ProductUpdateRequest`, `BulkDeleteProductsRequest`) | `authorize(): true`; no per-record ownership/scope check | IDOR for shop-scoped roles | policy checks |
| S-6 | MEDIUM | `SettingsController::update` | settings mutate unlogged, `UPDATE_SETTINGS` only | silent config change | settings audit + redaction |
| S-7 | MEDIUM | observers `Auth::id()` | system/job/console mutations record `causer=null` | automated ops untraceable | `ActorResolver` |
| S-8 | LOW | `Routes.php` | `GET /logs/activity` registered twice | harmless redundancy | dedupe |
| S-9 | LOW | `PruneImports` | deletes import files after 14 days | reduced evidence | archive-before-delete |
| S-10 | LOW | `ActivityLogApiTest.php` | all tests assert `404` | no positive coverage of the audit API | rewrite tests |

---

## 8. Retention Findings

- `delete_records_older_than_days = 60` exists in config but **`activitylog:clean` is not scheduled** anywhere (`routes/console.php` is empty; `app/Console/Kernel.php` has no activitylog entry). Retention is **not running**.
- Requirement is now **90 days**, quarterly cleanup, chunked delete.
- No composite indexes for filtering by `(log_name, event, created_at)`, `(causer_type, causer_id, created_at)`, `(subject_type, subject_id, created_at)`. Existing indexes: `log_name` + morph `subject` + morph `causer` (from migrations).
- Retention itself is not audited (no `activity_pruned` record).
- Production DB is MySQL/TiDB-compatible → chunked `DELETE … LIMIT n` loop is required (avoid single giant DELETE / long locks).

---

## 9. Recommended Architecture

### 9.1 Central principle

> Activity log is a **forensic record of the operation**, not a reflection of the current DB state. Capture snapshots **before** mutation; the audit must survive the subject's disappearance.

### 9.2 Components (minimal, integrate with Spatie — do not replace)

1. **`ActorResolver`** — resolves `actor` (human/system) and `executor` (job/command) distinctly. Order: authenticated user → explicit context actor → `system`. Never rely solely on `Auth::id()`.
2. **`ActivityContext`** — scoped container stamped by middleware/queue/console/import: `source`, `ip`, `user_agent`, `route`, `method`, `request_id`, `correlation_id`, `job`, `command`, `import_id`, `batch_uuid`, `reason`.
3. **`RequestIdMiddleware`** — generate or propagate `X-Request-ID` for API + GraphQL.
4. **`ActivityAuditService` / `ActivityLogWriter`** — single entry point that builds the Spatie payload (subject morph, causer, event, `properties` with `old`/`new`/`context`/`actor`/`executor`/`reason`) and applies **redaction**.
5. **`ActivitySnapshot`** — value object holding `subject_type/id`, `event`, `old`, `new`, `actor`, `context`, `batch_uuid`, `import_id`, `reason`, `source`. Serialized into the job payload so the worker never re-fetches the subject.

### 9.3 Write strategy

- **Critical/low-volume** (deletes, force-deletes, settings, role changes): **synchronous** logging inside the business transaction (rollback-safe).
- **High-volume** (imports, bulk): **queued**, but the job carries the full snapshot (no re-fetch). Queue must never be able to erase evidence.
- On logging failure: **report** (observable), never silent `return`; do not abort the business transaction by default (document the policy).

### 9.4 Observers

Keep observers for generic `created/updated/deleted/restored/forceDeleted`, but rewrite them to (a) capture old/new snapshots at dispatch, (b) pass `ActorResolver` + `ActivityContext`, (c) never drop delete events.

### 9.5 Bypasses — resolution per §11 of the spec

- `saveQuietly`/`updateQuietly` → **Option B** (explicit service/batch logging) for imports & pricing refresh; do **not** blindly convert to `save()` (Scout/queue side effects).
- Query-builder bulk updates/deletes → **Option B/C** (batch-level activity + selected high-value child rows).
- Relationship `sync` → **semantic events** (`product_categories_synced`, `product_brand_changed`, …) with old/new IDs, not per-pivot rows.
- `$request->except` → **whitelist** (`only` + rules).

### 9.6 Import/export/bulk strategy

- One **batch activity** (`import_started`/`import_completed`/`import_failed`/`rollback`, `bulk_delete`, `destroy_all`, `bulk_update`) with `batch_uuid` + `import_id` + counts + actor + source.
- Child activities **only for risky mutations**: deletion, force-delete, rollback, status→inactive, major price/inventory change.
- Support `"show everything for Import #123"` via `properties->import_id` (and `batch_uuid`).

### 9.7 Schema & indexes

- Reuse Spatie columns. Store `import_id`, `correlation_id`, `request_id`, `reason`, `source`, `actor`, `executor`, `old`, `new`, `context` in `properties` JSON.
- Do **not** add columns unless a dedicated indexed `import_id` is required by the query engine.
- Add composite indexes (justified): `(log_name, event, created_at)`, `(causer_type, causer_id, created_at)`, `(subject_type, subject_id, created_at)` — verify TiDB/MySQL compatibility before applying.

### 9.8 Redaction (centralized, recursive)

Exclude `password`, `password_confirmation`, `remember_token`, `*_token`, `*_secret`, `*_key`, `provider_access_token`, `webhook_secret`, payment/API credentials — recursively across `old`, `new`, `context`, `properties`. Test-verified.

### 9.9 Immutability

No write/delete endpoints for `activity_log`. Append-only from the application. (Optionally restrict DB privileges later.)

### 9.10 Retention

- `ACTIVITY_LOG_RETENTION_DAYS=90`.
- Quarterly scheduled command (chunked delete `created_at < now()->subDays(90)`), idempotent, `withoutOverlapping()`.
- Self-audit: emit one `activity_pruned` summary (retention_days, cutoff, deleted_count, source=scheduler).

---

## 10. Exact Implementation Plan

### Phase 0 (this document) — STOP FOR APPROVAL

### Phase 1 — Core Activity Writer
1. `ActivitySnapshot` + `ActivityAuditService` (single writer, redaction built-in).
2. Rewrite `LogActivityJob` to consume a full snapshot payload (no re-fetch; `withTrashed()` only as a fallback; subject-less fallback so deletes still persist).

### Phase 2 — Snapshot + Actor + Context
3. `ActorResolver` (actor vs executor vs system).
4. `ActivityContext` scoped container.
5. `RequestIdMiddleware` (API + GraphQL).
6. Update observers to pass snapshots/actor/context; fix Category/Brand `restored` logging; add `forceDeleted` for Coupon/Promotion/Role.

### Phase 3 — Delete / Force-Delete Hardening
7. Ensure soft-delete, force-delete, purge (`PurgeOldSoftDeletedProducts`), and import-rollback deletions all persist evidence (old snapshot survives).

### Phase 4 — Global Mutation Coverage
8. Settings audit (old/new, redacted). Invoice → activity_log. Relationship semantic events. Inventory/service-level activities where meaningful.

### Phase 5 — Import / Export / Bulk / Queue
9. Batch activities for import/export/bulk-delete/destroy-all/bulk-status; `import_id`/`batch_uuid` linkage; risky child activities.
10. `destroyAll` gating (dedicated permission + confirmation + batch log + soft-delete-only).

### Phase 6 — Security Hardening
11. GraphQL `@can`/service-level authorization for product mutations.
12. Mass-assignment whitelist (`$request->only`) + full validation rules.
13. Ensure no activity-log write/delete endpoint exists.

### Phase 7 — 90-day Retention + Quarterly Cleanup
14. `ACTIVITY_LOG_RETENTION_DAYS=90`; chunked `activitylog:prune` command; schedule quarterly; composite indexes; `activity_pruned` self-audit.

### Phase 8 — Full Test + Forensic Verification
15. Test suite (§30 of the spec) + `activity-log-implementation-verification.md`.

---

## Forensic Acceptance Mapping (pre-implementation state)

| Criterion | Current | After |
|---|---|---|
| A. Deleted product investigable (soft/force/purge/rollback/bulk) | ❌ | ✅ |
| B. actor/event/subject/ts/old/new/source/reason | partial (no actor in async, no reason) | ✅ |
| C. Queue/scheduler preserve business actor | ❌ | ✅ |
| D. Automated ops marked as system | ❌ | ✅ |
| E. Import/bulk traceable by batch_uuid/import_id | ❌ | ✅ |
| F. Sensitive values never persisted | partial | ✅ (recursive redaction) |
| G. activity_log append-only | ✅ (no write endpoint) | ✅ |
| H. >90d removed | ❌ (not running) | ✅ |
| I. <90d preserved | n/a | ✅ |
| J. No silent bypass | ❌ (documented §5) | ✅ (all documented + handled) |

---

**Phase 0 conclusion**: Architecture and mutation inventory complete. Four blockers (S-1 delete-drop, S-2 destroyAll, S-3 GraphQL auth, S-4 mass assignment) plus retention-not-running must be resolved in implementation. **Awaiting approval to proceed to Phase 1.**
