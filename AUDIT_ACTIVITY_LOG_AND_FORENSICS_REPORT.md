# Activity Log & Data-Deletion Forensic Audit

**Repository**: Catch / Meem Commerce (Laravel 10 / Marvel kernel)
**Audit date**: 2026-09-15
**Scope**: Audit only — no code was modified. No migrations, models, controllers, jobs, or responses were changed.

---

## 1. Executive Summary

The project already has a working `spatie/laravel-activitylog` installation (v4.12.3) wired through a **dual-path architecture**: Eloquent Observers for CRUD and Event Listeners for business operations, both funnelling into a single queued `LogActivityJob`.

**However, the single most important forensic requirement — "who deleted what and why" — is currently broken.** Deletions are dispatched to a queued job that re-fetches the subject using `find()` **without `withTrashed()`**. The subject is already gone (hard delete) or hidden (soft delete) by the time the job runs, so the job silently returns without writing a log. **In practice, essentially no `deleted` (and no `forceDeleted`) activity is ever persisted for any model.**

Other major gaps:

- Product/category/brand **bulk deletes** are possible via `destroyAll` / `destroyBulk` / `BulkDeleteCategoriesJob` with weak or no batch-level audit linkage.
- **Imports** mutate products using `saveQuietly()` (bypassing observers and Scout), so product create/update/status changes from imports are invisible to the activity log.
- A scheduled command **permanently force-deletes** soft-deleted products older than 30 days with **no surviving audit record**.
- **Settings** changes are not logged at all.
- Retention is configured (`60` days) but **`activitylog:clean` is never scheduled**, so it is not actually running.

Overall risk: **HIGH** for forensic completeness. The system logs creates and (most) updates reasonably well, but the delete/disappearance story is essentially un-auditable today.

---

## 2. Existing Activity Log Architecture

### 2.1 Package & version

| Item | Value | Evidence |
|---|---|---|
| Package | `spatie/laravel-activitylog` | `composer.json:35` (`"*"`) |
| Installed version | **4.12.3** | `composer.lock:9988` |
| Activity model | `Spatie\Activitylog\Models\Activity` (default — no custom model) | `config/activitylog.php:25` |
| Table | `activity_log` | migration `database/migrations/2026_07_05_080102_create_activity_log_table.php` |

### 2.2 Configuration (`config/activitylog.php`)

| Key | Value | Effect |
|---|---|---|
| `enabled` | `env('ACTIVITY_LOGGER_ENABLED', true)` | logging on by default |
| `delete_records_older_than_days` | `60` | used **only** when `activitylog:clean` runs (see §8 — it is not scheduled) |
| `default_log_name` | `default` | |
| `default_auth_driver` | `env('ACTIVITY_AUTH_DRIVER','sanctum')` | |
| `subject_returns_soft_deleted_models` | `true` | **declared but not honored** by `LogActivityJob` (see §3.2) |
| `default_except_attributes` | `password, remember_token, created_at, updated_at` | no meaningful redaction list otherwise |
| `default_except_methods` | `HEAD, OPTIONS, TRACE, CONNECT, GET` | inert — Spatie v4 has no built-in HTTP request logging; this key is not consumed |

The `LogsActivity` trait is **not** used on any model. Logging is implemented through custom Observers and Listeners instead.

### 2.3 Write path

```
Model event (created/updated/deleted/...) → Observer → LogActivityJob::dispatch(...)
Event (OrderCreated, PaymentSucceeded, UserRolesUpdated, ...) → Listener → LogActivityJob::dispatch(...)
                                                        │  queue: medium
                                                        ▼
                                      LogActivityJob::handle() → activity()->log()
```

- `app/Jobs/LogActivityJob.php` is the **sole** writer. It re-loads the subject and causer from the DB and calls Spatie's `activity()` helper.
- Observers are registered in `app/Providers/EventServiceProvider.php::$observers`.

### 2.4 Read path

`packages/marvel/src/Http/Controllers/ActivityLogController.php` exposes `GET /logs/activity` (filters: `log_name`, `event`, `causer_id`, `search`; always `latest()`; paginated). Authorized by `permission:view-activity-log`. Serialized by `packages/marvel/src/Http/Resources/ActivityLogResource.php`.

---

## 3. Current Coverage

### 3.1 Observers (CRUD logging)

Registered in `app/Providers/EventServiceProvider.php`:

| Observer | Model | SoftDeletes? | Logged events |
|---|---|---|---|
| `ProductObserver` | Product | ✅ | created, updated, statusChanged, deleted, restored, forceDeleted |
| `CategoryObserver` | Category | ✅ | created, updated, statusChanged, deleted (restored only flushes cache — not logged) |
| `BrandObserver` | Brand | ✅ | created, updated, statusChanged, deleted (restored not logged) |
| `CouponObserver` | Coupon | ❌ | created, updated, statusChanged, deleted |
| `FlashSaleObserver` | FlashSale | ✅ | created, updated, statusChanged, deleted, restored, forceDeleted |
| `PromotionObserver` | Promotion | ❌ | created, updated (tracked fields), statusChanged, deleted |
| `RoleObserver` | Role | ❌ | created, updated, deleted |
| `UserObserver` | User | ✅ | created (only if authed), updated, statusChanged, deleted, restored, forceDeleted |
| `PickupLocationObserver` | PickupLocation | ✅ | created, updated, statusChanged, deleted (no restored/forceDeleted) |
| `ContentPageObserver` / `SectionObserver` / `SectionTypeObserver` / `SectionTypeSettingObserver` / `StaticPageObserver` / `StaticSectionObserver` | CMS/Static content | varies | created/updated/deleted (minor) |

`MediaCleanupObserver` is also registered on Product, Category, Brand, FlashSale, User, Banner, Review, Shop, Slider — it deletes related media rows/files on `deleting`/`forceDeleting`.

### 3.2 Business-event logging (Listeners)

| Listener | Event | Log event name | Notes |
|---|---|---|---|
| `SendNewOrderNotification` | `OrderCreated` | `order_created` | subject = Order |
| `SendOrderCancelledNotification` | `OrderCancelled` | order cancelled | subject = Order |
| `SendOrderStatusChangedNotification` | `OrderStatusChanged` | `order_status_changed` | subject = Order |
| `SendPaymentFailedNotification` | `PaymentFailed` | payment failed | |
| `SendPaymentSucceededNotification` | `PaymentSucceeded` | payment succeeded | |
| `LogUserRolesUpdated` | `UserRolesUpdated` | `roleUpdated` | subject = User, captures old/new roles |
| `LogInvoiceCreated` | `InvoiceCreated` | — | uses `Log::info()` **not** the activity log |

### 3.3 What actually lands in the DB

- `created`, `updated` (with old/new `properties` JSON) and `statusChanged` for the observed models **do** get written.
- Order lifecycle events (`order_created`, `order_status_changed`, etc.) **do** get written (Order is not soft-deleted during these events, so `find()` succeeds).
- **`deleted` and `forceDeleted` do NOT reliably land** — see §4.1 (critical bug).

---

## 4. Missing Coverage

### 4.1 CRITICAL — deletions are not actually logged

`app/Jobs/LogActivityJob.php:27`:

```php
$subject = app($this->subjectType)::find($this->subjectId);
if (!$subject) return;
```

- **Soft-deleted models** (Product, Category, Brand, FlashSale, User, PickupLocation): `find()` applies the SoftDeleting global scope, so a just-soft-deleted row is *not found* → the `deleted` log is dropped.
- **Hard-deleted models** (Coupon, Promotion, Role): the row no longer exists → the `deleted` log is dropped.
- `forceDeleted` (Product, FlashSale, User) and the scheduled purge (`PurgeOldSoftDeletedProducts`) are likewise dropped.

This is already documented internally as a known bug (`api-desc/activity-log/bug-report.md`, Issues #2 and #3) but was **not fixed**. The config key `subject_returns_soft_deleted_models => true` is a red herring — it only affects Spatie's own subject resolution, not the custom job's `find()`.

**Consequence**: the core requirement "if products suddenly disappear, I can investigate exactly how and why" is not met today.

### 4.2 Entities with no logging at all

- **Settings** — `SettingsController::update()` calls `Settings::first()->update($data)`; no observer exists (`packages/marvel/src/Http/Controllers/SettingsController.php:73`). Settings includes tax config, minimum order amount, site options — high-value config, unlogged.
- **Order / OrderProduct / Transaction / Invoice / Refund / Review / Import / Media / DigitalAsset** — no CRUD observers. Orders get *business-event* logging (status/created/payment) but no attribute-level update diff, and no delete logging.
- **ProductVariant** — deleted via query builder (`ProductRepository.php:156`) which bypasses observers.
- **Attribute / AttributeValue / Tag / Type / Shipping / Shop** etc. — no observers.

### 4.3 Queries/operations that bypass observers (invisible even where observers exist)

| Location | Operation | Effect |
|---|---|---|
| `ProductRepository.php:156` | `ProductVariant::where('product_id', …)->delete()` | variant delete bypasses observers |
| `ProductImportService.php:420,424,430,440,498,502,512` | `saveQuietly()` | import create/update bypasses observers **and Scout** |
| `RefreshProductPricingCommand.php:31,41` | `updateQuietly()` | bulk price refresh bypasses observers |
| `CategoryHierarchyService.php:90` | `saveQuietly()` | descendant level updates bypass observers |
| `CouponReservationService.php:85,94`, `ExpireCouponReservations.php:35` | `where()->delete()` | query-builder delete bypasses observers |
| `SendFcmNotificationJob.php:50` | `DeviceToken::whereIn(...)->delete()` | bypasses observers |
| `Order.php:144` | `saveQuietly()` (order number backfill) | |

### 4.4 Missing context

- **No request/HTTP context** is captured: no IP, user-agent, route, HTTP method, or correlation/request ID.
- **No causer for system/console/queue actors**: observers call `Auth::id()` at dispatch time; for jobs, commands, scheduler, and imports the causer is `null` (import has its own `created_by`, but that is not bridged into `activity_log`).
- **No batch/correlation IDs** in `activity_log` (`batch_uuid` column exists but is never populated).
- **No "why"/reason** field — only the event description.
- **`description` translatable key fallback inconsistency**: some observers use `__('key') ?: 'fallback'`, others don't; a missing key (e.g. `activity.flash_sale_restored`) leaks the raw key into the description.

---

## 5. Complete Mutation Inventory

### 5.1 CREATE

| Entity | Path | Logged? |
|---|---|---|
| Product | `ProductController::store` → `ProductRepository::storeProduct` | ✅ observer `created` |
| Product via import | `ProductImportService::processProductRow` (`saveQuietly`) | ❌ |
| Product via GraphQL | `ProductMutator::store` → `ProductController@ProductStore` | ✅ |
| Category/Brand/Coupon/Promotion/FlashSale/Role/User/PickupLocation | respective controllers → repositories | ✅ observers |
| Order | `OrderCreationService` / `OrderRepository::storeOrder` | ✅ `OrderCreated` event |
| Invoice | `GenerateInvoiceListener` | ⚠️ `Log::info` only (not activity log) |
| Settings | n/a (singleton) | n/a |
| Import record | `ProductImportController::import` → `Import::create` | ❌ (but `imports.created_by` records creator) |

### 5.2 UPDATE

| Entity | Path | Logged? |
|---|---|---|
| Product | `ProductController::update` → `ProductRepository::updateProduct` (`$product->update($data)`) | ✅ observer `updated` (old/new) |
| Product (silent) | import `saveQuietly`, `RefreshProductPricingCommand::updateQuietly` | ❌ |
| Product variants | `ProductRepository::updateProduct` (variant delete + recreate) | ⚠️ variant delete bypasses observers |
| Category/Brand/Coupon/Promotion/FlashSale/Role/User | respective repositories | ✅ observers (old/new) |
| Category hierarchy | `CategoryHierarchyService::updateDescendantLevels` (`saveQuietly`) | ❌ |
| Order status | `OrderRepository::updateOrder` → `changeOrderStatus` | ✅ `OrderStatusChanged` event + `order_status_history` table |
| Order (other fields) | `OrderRepository::updateOrder` | ⚠️ only status path logged; generic update diff not logged |
| Settings | `SettingsController::update` | ❌ |
| Currency/CurrencyRate | `CurrencyService`, `CurrencyRateService` | ❌ (no observers) |
| Bulk status (country/governorate/shipping price) | `bulkStatus()` repos | ❌ |

### 5.3 DELETE (the critical section)

| Entity | Path | Type | Reversible | Logged? |
|---|---|---|---|---|
| Product | `ProductController::destroy` → `destroyProduct` → `$product->delete()` | soft | ✅ (restore possible) | ❌ (dropped) |
| Product (all) | `ProductController::destroyAll` | soft (all) | ✅ | ❌ (dropped) |
| Product (bulk) | `ProductController::destroyBulk` | soft | ✅ | ❌ (dropped) |
| Product (GraphQL) | `ProductMutator::destroy` → `destroyProduct` | soft | ✅ | ❌ |
| Product (purge) | `PurgeOldSoftDeletedProducts` (scheduled daily 02:30) → `forceDelete()` | **permanent** | ❌ | ❌ (dropped) |
| Product (import rollback) | `ProductImportService::rollbackCreatedData` → `forceDelete()` | **permanent** | ❌ | ❌ |
| Category | `CategoryController::destroy` | soft | ✅ | ❌ (dropped) |
| Category (bulk) | `BulkDeleteCategoriesJob` → `$category->delete()` | soft | ✅ | ❌ (dropped); tracked in `imports` table only |
| Brand/Coupon/Promotion/Role/User | respective `destroy()` | soft (Brand/User) / hard (Coupon/Promotion/Role) | mixed | ❌ (dropped) |
| ProductVariant | `ProductRepository:156` | **hard (query builder)** | ❌ | ❌ |
| CouponReservation | `CouponReservationService` / `ExpireCouponReservations` | hard (query builder) | ❌ | ❌ |
| Cart/CartItem | `CartInventoryService` | hard | ❌ | ❌ (no observer) |
| DeviceToken | `SendFcmNotificationJob:50` | hard (query builder) | ❌ | ❌ |
| Import files | `PruneImports` | file deletion only (rows preserved) | n/a | n/a |

### 5.4 Pivot / relationship mutations

- `ProductRepository::syncRelation` uses `categories()->sync()`, `brands()->sync()`, `tags()->sync()`, `flash_sales()->sync()/detach()`. Relationship attach/detach is **not** logged and pivot `sync`/`detach` does not fire model observers.
- Cascades: pivot FKs `coupon_product`, `slider_product`, `banner_product`, `product_tag` have `onDelete('cascade')` on `product_id` (and their other FK). Deleting a coupon/brand/banner/tag hard-deletes pivot rows that link products — this **removes product associations** (a product can disappear from a category/search facet without the product row being touched).

---

## 6. Product Disappearance Forensic Analysis

A product can "disappear from the storefront" in several independent ways. **Database deletion is only one of them.**

### 6.1 Distinguishing the three disappearance classes

1. **DATABASE DELETION** — the `products` row is soft-deleted (`deleted_at` set) or hard-deleted (row gone).
2. **APPLICATION FILTERING** — row still exists but is excluded by query scopes: `status=0`, out of stock, inactive category/brand, item-type mismatch, `FastShippingScope`, channel/cache.
3. **SEARCH INDEX DISAPPEARANCE** — row exists and is "active", but Meilisearch/Scout no longer returns it (stale index, `shouldBeSearchable()` returns false, failed sync).

### 6.2 Path table

| Path | Can affect products? | Current logging? | Risk | Evidence |
|---|---|---|---|---|
| API delete (soft) | ✅ | ❌ (dropped) | HIGH | `ProductController.php` `destroyProduct()` |
| Bulk delete (`destroyBulk`) | ✅ | ❌ | HIGH | `ProductController.php` `destroyBulk()` |
| Delete all (`destroyAll`) | ✅ (all) | ❌ | CRITICAL | `ProductController.php` `destroyAll()` |
| GraphQL delete | ✅ | ❌ | HIGH | `ProductMutator.php::destroy()` |
| Scheduled purge (forceDelete) | ✅ (permanent) | ❌ | CRITICAL | `PurgeOldSoftDeletedProducts.php:31`, `Kernel.php` schedule |
| Import overwrite/rollback | ✅ | ❌ (`saveQuietly`/`forceDelete`) | HIGH | `ProductImportService.php` |
| Bulk category delete (cascade pivot) | ⚠️ association loss | ❌ | MEDIUM | `BulkDeleteCategoriesJob.php`, pivot cascade migrations |
| Soft delete (restore gap) | ✅ | ❌ | MEDIUM | SoftDeletes; no explicit product restore endpoint found |
| Status change (status=0) | ✅ (filtered) | ✅ (`statusChanged`) | MEDIUM | `ProductObserver.php` |
| Stock/in_stock change | ✅ (filtered) | ⚠️ generic `updated` | MEDIUM | `ProductObserver.php`, `Product::shouldBeSearchable()` |
| Category/brand deactivation | ✅ (filtered via `scopeActive`) | ⚠️ category/brand `statusChanged` | MEDIUM | `Product::scopeActive()` |
| Search indexing | ✅ (index only) | ❌ | MEDIUM | `Product::shouldBeSearchable()`, import `saveQuietly` bypasses Scout |
| Cascade FK | ⚠️ pivots only (not product row) | ❌ | LOW | pivot migrations |
| Raw SQL | none found on products | n/a | LOW | — |

### 6.3 Detailed vectors

**A. Physical DB deletion**
- `destroyProduct()` calls `$product->delete()` (soft). `destroyAll()` iterates **every** product (`Product::chunk(100, …)`) — a single authenticated `DELETE_PRODUCT` holder can soft-delete the entire catalog with no confirmation and no batch audit record.
- `products:purge-old-deleted --days=30` runs **daily at 02:30** (`app/Console/Kernel.php`) and `forceDelete()`s any product soft-deleted >30 days. This is permanent and, due to §4.1, leaves no log.
- Import cancellation (`ProductImportService::rollbackCreatedData`) `forceDelete()`s products created during that import (and force-deletes their variants, detaches relations, clears media).

**B. Soft deletion**
- Product uses `SoftDeletes` (`Product.php:35`). Default queries exclude trashed rows, so a soft-deleted product vanishes from every endpoint immediately. No dedicated product restore endpoint was found in the controller; if none exists, a soft-deleted product is only recoverable manually before the 30-day purge.

**C. Status changes (filtered, not deleted)**
- `Product::shouldBeSearchable()` returns `false` unless `status === 1` and stock is available; `scopeActive()` filters `status=1`, stock, and active category/brand. So an admin setting `status=0`, or a category/brand being deactivated, or `in_stock`/`stock_quantity` dropping to zero, makes a product vanish from the storefront **and** from Meilisearch while the row persists. The `statusChanged` event is logged, but stock/availability transitions are only captured as a generic `updated` diff.

**D. Imports**
- `ProductImportService::processProductRow` matches existing rows **by SKU** and overwrites them with `fill($data)->saveQuietly()` — so an import can silently change `status`, `stock_quantity`, prices, etc., or reset relations (`flushPendingSyncs`, `sync`), without any observer firing.
- `saveQuietly()` also bypasses Scout, so search index can go stale relative to DB after an import.
- Import cancellation force-deletes all products created by that import.

**E. Queue jobs**
- `ImportProductsJob` (tries=3), `ImportProductImagesJob`, `BulkDeleteCategoriesJob`, `RefreshProductPricingCommand` (bulk `updateQuietly`), `LogActivityJob`. A failed/retried import can leave partial product mutations; cancellation rollback is the only compensating action.

**F. Scheduled tasks** (`app/Console/Kernel.php`)
- Mutating products: `products:purge-old-deleted` (permanent delete). Others (`imports:prune`, `queue:prune-failed`, `coupons:*`, `cart:*`, `payments:reconcile`, `currency:sync-rates`) don't mutate products directly, but `imports:prune` deletes import files (reducing later forensic evidence).

**G. Database-level behavior**
- No `ON DELETE CASCADE` foreign key deletes product *rows*; cascade is confined to pivot tables (`coupon_product`, `slider_product`, `banner_product`, `product_tag`), so deleting a coupon/brand/banner/tag removes product *associations*, not the product itself.
- No DB triggers or scheduled DB procedures found.

**H. Search/index layer**
- `Product` implements `Laravel\Scout\Searchable` with a custom `shouldBeSearchable()` gate. Inactive/out-of-stock products are excluded from Meilisearch. Because imports use `saveQuietly()`, a status change via import is never synced to the index; conversely an explicit `save()` on a deactivated product removes it from the index. Either way, a product can be present in the DB but absent from search.

---

## 7. Recommended Audit Architecture

### 7.1 Fix the delete-logging bug first (foundation)

Change `LogActivityJob::handle()` to load the subject with `withTrashed()` (and fall back to a minimal placeholder for hard-deleted rows so the `subject_type`/`subject_id` are still recorded):

```php
$subject = app($this->subjectType)::withTrashed()->find($this->subjectId);
if (!$subject) {
    // record a subject-less activity instead of silently dropping
    activity($this->logName)->withProperties([...])->event($this->event)->log($this->description);
    return;
}
```

Capture old values **at dispatch time** (serialize `$product->getAttributes()` / `getOriginal()`) rather than re-reading after the fact, because the row may be gone.

### 7.2 Capture causer/actor at dispatch

Observers currently read `Auth::id()`. Add an **ActorResolver** that determines the actor from, in order: authenticated user → command/job context → explicit system marker. Persist a `system` causer (or `causer=null` + a `system` property) for console/scheduler/queue/import operations.

### 7.3 Request/context capture

Add to `properties` (not as new columns initially): `ip`, `user_agent`, `route`, `method`, `request_id` (from a middleware-generated `X-Request-ID`), `correlation_id`, `command`/`job` name, `import_id`, `source` (`api`|`graphql`|`console`|`queue`|`scheduler`|`import`|`export`|`webhook`).

### 7.4 Layered logging strategy

| Layer | Mechanism | Purpose |
|---|---|---|
| Model | Observers (fixed to persist old/new + delete) | universal CRUD for audited models |
| Service | explicit `activity()` calls in domain services | business operations with semantic descriptions |
| Job/Batch | batch activity + `batch_uuid` / `import_id` | one entry per import/job + child rows for high-value targets only |
| Request | middleware that stamps request context into a scoped container | WHO/WHERE |

### 7.5 Batch vs per-row for import/export

For an import of N products:
- Write **one** batch activity (log_name `imports`, event `import_started`/`import_completed`, `properties.import_id`, `created_by`, `success/failed`, `source`).
- Do **not** write N per-product `updated` rows from `saveQuietly`. Instead, write per-product rows **only** for genuinely risky mutations (deletions, status→inactive, price changes) or on an opt-in basis. `batch_uuid` links them.
- Support the query: *"Show everything that happened during Import #123"* via `where('properties->import_id', 123)` or a dedicated `import_id` index.

### 7.6 Sensitive-data redaction

Extend `default_except_attributes` and enforce per-model `$logExcept`-style exclusion: never log `password`, tokens, `remember_token`, payment credentials, `provider_access_token`, webhook secrets, and any `*_token`/`*_secret`/`*_key` fields. Do a normalization pass over `old`/`new` properties to strip these before persist.

### 7.7 Transaction/consistency

- **Preferred**: synchronous logging inside the same DB transaction as the business operation (so a rollback rolls the log back too). The current design dispatches a queued job *after* commit, which introduces the very race that drops delete logs. For low-volume core models, log synchronously; keep queued logging only for high-volume/batch paths.
- If queued logging is retained: write `subject_id`, `subject_type`, `event`, and a **snapshot of old/new values** into the job payload (not a re-fetch), so the log can be written even if the subject is later deleted.

---

## 8. Retention Architecture

**Requirement**: keep ~latest 6 months, rolling.

- Set `delete_records_older_than_days = 180`.
- **Schedule** `activitylog:clean` (currently unscheduled) — e.g. daily at off-peak: `$schedule->command('activitylog:clean')->dailyAt('04:00')->withoutOverlapping()`.
- Spatie's clean command deletes by `created_at < now()->subDays(180)`. To avoid one huge DELETE, schedule it more frequently (daily) and/or add a chunked custom prune command.
- **Indexes**: add `(log_name, event, created_at)` and `(causer_type, causer_id, created_at)` composite indexes for retention and filter queries.
- **Timezone**: run prune in UTC (like the existing `currency:sync-rates` does).
- **Failure/retry**: `withoutOverlapping()`; on failure, next run resumes (idempotent by `created_at` cutoff).
- **Auditability of the retention itself**: log a `system` activity entry when pruning runs: `event=activity_pruned`, `properties=['pruned_before'=>cutoff, 'count'=>n]`, `causer=null`/system. This distinguishes "old logs pruned" from "someone deleted business data".

---

## 9. Security Findings

| # | Severity | Location | Problem | Exploit / Impact | Evidence | Recommended fix |
|---|---|---|---|---|---|---|
| S-1 | **CRITICAL** | `app/Jobs/LogActivityJob.php:27` | Deletion/force-delete logs silently dropped | Attacker (or bug) deletes data and it is untraceable | `find()` without `withTrashed()`; `if(!$subject) return` | `withTrashed()` + snapshot payload + subject-less fallback |
| S-2 | **HIGH** | `ProductController.php` `destroyAll()` | Deletes **all** products with only `DELETE_PRODUCT` permission; no confirmation, no batch audit | A compromised/rogue admin erases the entire catalog | chunk loop over `Product::count()` | Require explicit confirm + dedicated permission + batch activity + soft-delete-only guard |
| S-3 | **HIGH** | GraphQL mutators (`ProductMutator::destroy`, etc.) | Mutations call controller methods via `Shop::call()` — bypass HTTP middleware (permission) unless Lighthouse `@can`/`@guard` is present | Permission bypass for GraphQL mutations | `ProductMutator.php` | Verify/enforce `@can`/`@guard` directives in GraphQL schema; add service-level `authorize()` |
| S-4 | **HIGH** | `ProductRepository::storeProduct/updateProduct` | `$request->except([...])` mass-assigns fillable fields not covered by FormRequest rules (`sku`, `stock_quantity`, `reserved_quantity`, `sold_quantity`) | Privilege-appropriate user sets stock/price/inventory arbitrarily | `Product.php` `$fillable` vs `ProductUpdateRequest` rules | Whitelist `$request->only(...)`; add missing rules |
| S-5 | **MEDIUM** | FormRequests (`ProductCreateRequest`, `ProductUpdateRequest`, `BulkDeleteProductsRequest`) | `authorize()` returns `true`; no per-record ownership/scope check (IDOR by design for multi-shop) | Any holder of the permission can mutate/delete any record id | `authorize(): true` | Add policy checks (`$this->authorize()`) for shop-scoped roles |
| S-6 | **MEDIUM** | `SettingsController::update` | Settings (tax, min order, site options) mutate with no activity log and only `UPDATE_SETTINGS` permission | Silent config changes | `SettingsController.php:73` | Add settings audit (log old/new options) |
| S-7 | **MEDIUM** | `LogActivityJob` + observers use `Auth::id()` at dispatch | System/job/console mutations record `causer=null` | Automated deletions untraceable to trigger | all observers | ActorResolver + system actor marker |
| S-8 | **LOW** | `api-desc/activity-log/bug-report.md` Issue 1 | `GET /logs/activity` registered twice | Harmless redundancy | `Routes.php` | dedupe route |
| S-9 | **LOW** | `PruneImports` deletes import files | Import files (evidence) removed after 14 days, rows preserved | Reduced forensic evidence | `PruneImports.php` | archive before delete, or extend retention |

*(No hardcoded credentials, no raw-SQL injection with user input, and no command injection were found in the audited paths. Webhook signature handling and CSRF were not exhaustively audited in this pass and remain recommended follow-ups.)*

---

## 10. Implementation Plan

### Phase A — Foundation
1. Fix `LogActivityJob` to load subjects with `withTrashed()`, carry old/new snapshots in the payload, and write a subject-less fallback when the row is gone.
2. Introduce `ActorResolver` (auth → job/command → `system`) and thread actor + request context into the job payload.
3. Add `request_id` middleware; stamp context for API/GraphQL/console/queue.

### Phase B — Core model auditing
4. Complete observer coverage: add `restored`/`forceDeleted` logging to Category/Brand; add `forceDeleted` to Coupon/Promotion/Role (or confirm soft-delete migration intent).
5. Add observers (or explicit service logging) for Settings, ProductVariant, Order/OrderProduct attribute diffs.
6. Normalize redaction (extend `default_except_attributes` + `*_token`/`*_secret`/`*_key` filter).

### Phase C — Business-operation auditing
7. Convert `LogInvoiceCreated` from `Log::info` to activity log; add payment/refund/ownership-transfer business activities.
8. Add semantic logging in services for pricing changes, inventory reservation/restore, and role/permission changes.

### Phase D — Queue/import/export auditing
9. Add batch activity for each import/export/bulk-delete (batch_uuid + import_id), linking child rows.
10. Ensure import overwrite/rollback mutations are recorded (deletion + status-inactive rows at minimum).
11. Make `RefreshProductPricingCommand` and other `updateQuietly` paths either log a batch summary or switch to observable saves.

### Phase E — Security fixes
12. Add confirmation + dedicated permission + batch audit to `destroyAll`.
13. Enforce GraphQL authorization (`@can`/`@guard` or service-level policy checks).
14. Whitelist mass-assignment (`$request->only(...)`) in product store/update; add missing validation rules.
15. Add `Settings` audit logging.

### Phase F — Retention/pruning
16. Set `delete_records_older_than_days=180`; schedule `activitylog:clean`; add composite indexes; log the prune itself as a system activity.

### Phase G — Tests
17. See §11.

---

## 11. Test Strategy

| # | Test | Proves |
|---|---|---|
| T-1 | Create a product/category/coupon → assert an `activity_log` row with `event=created`, correct `subject_type/id`, causer | create is logged |
| T-2 | Update a product → assert `event=updated` with `properties.old`/`new` containing the changed attribute | update is logged + old/new preserved |
| T-3 | Soft-delete a product → assert `event=deleted` **exists** (regression for S-1) | delete is logged |
| T-4 | `restore()` a product → assert `event=restored` | restore is logged |
| T-5 | `forceDelete()` (and run `products:purge-old-deleted`) → assert a log survives with the deleted subject id | permanent delete is logged |
| T-6 | Bulk delete (`destroyBulk`/`destroyAll`/`BulkDeleteCategoriesJob`) → assert batch activity + linked child rows | bulk operations are logged |
| T-7 | Run a product import → assert a batch activity with `import_id`, and that created/updated products are traceable to that id | imports are traceable |
| T-8 | Dispatch a job that mutates a model → assert `source=queue` and correct actor | jobs are traceable |
| T-9 | Run a scheduled command that mutates data → assert `source=console`/`system` actor | automated operations are traceable |
| T-10 | Attempt delete/update without permission → assert 403 | unauthorized operations rejected |
| T-11 | Mutate a model containing `password`/`*_token`/`*_secret` → assert those keys are absent from `properties` | sensitive fields not logged |
| T-12 | Run retention prune → assert only rows older than 180 days removed, and a `activity_pruned` system entry is written | retention correctness + self-audit |
| T-13 | Attempt to modify/delete `activity_log` via the API → assert no write endpoint exists (immutable audit) | audit logs not casually modifiable |

---

## 12. Final Verdict

**PARTIALLY READY**

The existing activity-log infrastructure (Spatie 4.12.3 + observer/listener dual path) is a solid foundation and already covers creates and most updates. It is **not** production-forensic-ready because:

- **Blocker 1 (S-1):** deletions/force-deletions are silently dropped — the primary forensic requirement is unmet.
- **Blocker 2 (S-2/S-3):** bulk-delete-all and GraphQL authorization gaps expose high-impact destructive operations that are also unlogged.
- **Blocker 3 (S-4):** product mass-assignment allows unvalidated field writes.
- **Blocker 4 (§8):** retention is configured but not actually scheduled.

These are all fixed in Phase A/E/F before the system can be considered a complete auditability solution.

---

## TOP 10 RISKS

1. **Deletion logs are silently dropped** — `LogActivityJob` `find()` without `withTrashed()` means products (and other models) vanish with no record (`app/Jobs/LogActivityJob.php:27`).
2. **`destroyAll` wipes the entire catalog** with a single `DELETE_PRODUCT` permission, no confirmation, no batch audit (`ProductController.php`).
3. **Scheduled purge permanently force-deletes** soft-deleted products (>30 days) with no surviving log (`PurgeOldSoftDeletedProducts.php`, `Kernel.php`).
4. **Imports mutate products invisibly** — `saveQuietly()` bypasses observers and Scout; imports can overwrite status/price/stock and force-delete on cancel (`ProductImportService.php`).
5. **GraphQL mutations may bypass permission middleware** (`ProductMutator.php` via `Shop::call`).
6. **Product mass assignment** — `$request->except([...])` lets unvalidated fillable fields (`sku`, `stock_quantity`, `reserved_quantity`, `sold_quantity`) be set (`ProductRepository.php`).
7. **Settings changes are unlogged** (`SettingsController.php:73`).
8. **Retention is not running** — `activitylog:clean` is never scheduled (`delete_records_older_than_days=60` is inert).
9. **Bulk category delete** removes categories (and cascades pivot associations) with no activity-log record (`BulkDeleteCategoriesJob.php`).
10. **No system/queue/console actor attribution** — automated mutations have `causer=null` and no request context.

## TOP 10 IMPLEMENTATION ACTIONS

1. Fix `LogActivityJob` to `withTrashed()` + snapshot old/new in payload + subject-less fallback (S-1).
2. Add `ActorResolver` and stamp request/command/job/import context into every activity.
3. Gate `destroyAll` with confirmation + dedicated permission + batch activity; soft-delete only.
4. Enforce GraphQL authorization (`@can`/`@guard` or service-level policy checks).
5. Whitelist product mass-assignment (`$request->only(...)`) and add missing validation rules.
6. Add observers/service logging for Settings, ProductVariant, and Order attribute diffs.
7. Add batch activities (batch_uuid + import_id) for import/export/bulk-delete with child-row linking.
8. Enable and schedule `activitylog:clean` at 180 days + composite indexes + prune self-audit entry.
9. Redact sensitive fields (`password`, `*_token`, `*_secret`, `*_key`) in `old`/`new` properties.
10. Write the regression test suite (§11), prioritizing T-3/T-5/T-6/T-7/T-11/T-12.
