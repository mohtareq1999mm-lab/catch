# Activity Log — Implementation Plan

**Basis**: `activity-log-forensic-audit.md` (Phase 0). All blockers below reference that audit.

## Goals

Make `activity_log` a reliable, append-only forensic trail that survives subject deletion, attributes every mutation to an actor/source, and is retained for 90 days via a quarterly chunked prune.

## Architecture

```
Request → RequestIdMiddleware → ActivityContext (request-scoped singleton)
Mutation → Observer/Service/Listener
        → ActorResolver (actor vs executor)
        → ActivitySnapshot (subject_type/id, event, old, new, actor, context, reason, batch_uuid, import_id)
        → ActivityAuditService (canonical writer: redact + persist via Spatie Activity model)
           ├─ synchronous (critical CRUD / deletes / settings / relationships)
           └─ queued via LogActivityJob (business events, import/bulk batch)
```

- **`ActivitySnapshot`** — immutable value object; fully self-contained so a queued writer never re-fetches the subject.
- **`ActivityAuditService`** — the single writer. Persists via `Spatie\Activitylog\Models\Activity` directly (not `activity()->performedOn()`) so `subject_type`/`subject_id` survive even when the row is gone.
- **`ActorResolver`** — resolves human actor vs system; keeps `actor` (business actor) distinct from `executor` (job/command).
- **`ActivityContext`** — request/execution scope: `source, ip, user_agent, route, method, request_id, correlation_id, job, command, import_id, batch_uuid, reason`.
- **`ActivityRedactor`** — recursive redaction of sensitive keys across `old/new/context/properties`.
- **`RequestIdMiddleware`** — propagate or generate `X-Request-ID`; register in API group.

## Files to create

| Path | Purpose |
|---|---|
| `app/Audit/ActivitySnapshot.php` | value object |
| `app/Audit/ActorResolver.php` | actor/executor resolution |
| `app/Audit/ActivityContext.php` | scoped context singleton |
| `app/Audit/ActivityRedactor.php` | recursive redaction |
| `app/Audit/ActivityAuditService.php` | canonical writer |
| `app/Http/Middleware/RequestIdMiddleware.php` | request id |
| `app/Console/Commands/PruneActivityLog.php` | 90-day chunked retention |
| `database/migrations/2026_09_15_000001_add_activity_log_indexes.php` | composite indexes |
| `tests/Feature/ActivityLogForensicTest.php` | forensic regression tests |

## Files to change

| Path | Change |
|---|---|
| `app/Jobs/LogActivityJob.php` | snapshot-based; no re-fetch; never drops |
| `app/Observers/{Product,Category,Brand,Coupon,FlashSale,Promotion,Role,User,PickupLocation}Observer.php` | capture old/new at dispatch; synchronous `ActivityAuditService::record()` |
| `app/Listeners/{SendNewOrderNotification,SendOrderCancelledNotification,SendOrderStatusChangedNotification,SendPaymentFailedNotification,SendPaymentSucceededNotification,LogUserRolesUpdated}.php` | snapshot-based |
| `app/Listeners/LogInvoiceCreated.php` | activity log instead of `Log::info` |
| `app/Http/Kernel.php` | register `RequestIdMiddleware` in `api` group |
| `app/Console/Kernel.php` | schedule `activitylog:prune` quarterly |
| `packages/marvel/src/Http/Controllers/ProductController.php` | `destroyAll` permission+confirmation+batch log; `destroyBulk` batch log |
| `packages/marvel/src/Database/Repositories/ProductRepository.php` | `$request->only(...)`; relationship `sync` audit |
| `packages/marvel/src/Http/Controllers/SettingsController.php` | settings audit (old/new, redacted) |
| `packages/marvel/src/GraphQL/Mutations/ProductMutator.php` | permission check (defense-in-depth) |
| `packages/marvel/src/Enums/Permission.php` | `DELETE_ALL_PRODUCTS` |
| `resources/lang/{en,ar}/activity.php` | new keys |
| `tests/Feature/ActivityLogApiTest.php` | rewrite to positive tests |
| `api-desc/activity-log/bug-report.md` | mark issues resolved |

## Event coverage

- CRUD + `statusChanged` + `restored` + `forceDeleted` for observed models (now surviving deletion).
- Business: order created/cancelled/status, payment success/failure, role changed, invoice created, settings updated.
- Relationships: `product_categories_synced`, `product_brands_synced`, `product_tags_synced`, `product_flash_sales_synced` (semantic, old/new ids).
- Inventory: `inventory_decremented` (order stock deduction).
- Batch: `bulk_delete`, `destroy_all`, import `import_started`/`import_completed`/`import_failed`/`rollback`.

## Retention

- `ACTIVITY_LOG_RETENTION_DAYS=90`.
- `activitylog:prune` deletes `created_at < now()->subDays(90)` in chunks, idempotent, `withoutOverlapping()`, self-audit `activity_pruned`.

## Security changes

- `destroyAll` → `DELETE_ALL_PRODUCTS` permission + `confirm` input + batch log + soft-delete only.
- GraphQL `ProductMutator` enforces permission (super_admin or `*_product` permission).
- Product mass-assignment → `$request->only(...)` whitelist.
- Redaction applied centrally.

## Risks

- Synchronous activity writes on admin CRUD add ~1 insert/request (acceptable, low volume).
- Existing queued `LogActivityJob` payload signature changes — all callers updated in this change.
- Composite indexes must be validated on TiDB before deploy.

## Backward compatibility

- Historical rows without `actor/context/reason` remain valid; reader handles nulls.
- No API response shape changes (activity-log endpoint keeps `success/message/data/meta`).
