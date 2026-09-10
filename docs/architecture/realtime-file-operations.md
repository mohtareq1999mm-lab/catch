# Realtime File Operations Architecture

Status: Approved

AI agents must read this document before modifying any import/export/bulk-delete
progress reporting or broadcasting code.

## Metadata

- Decision ID: ADR-002
- Architecture Area: Async file operations (imports / exports / bulk deletes)
- Status: Approved (implemented 2026-08-25)
- Related: ADR-001 Runtime Pricing (untouched)

---

## Decision

Long-running file operations notify the frontend in real time through the
existing Pusher/Broadcasting stack instead of relying on continuous polling.

```text
Database (`imports` table) = Source of Truth
Pusher                    = Realtime wake-up / notification
Status endpoints          = Recovery / Reconciliation
```

Pusher is NEVER the authoritative state store. If a Pusher event and the
database disagree, the database wins and the frontend must reconcile via
`GET .../{id}` status endpoints.

## Channel

Single user-scoped private channel:

```text
private-users.{userId}
```

Authorization already existed in `routes/channels.php`:

```php
Broadcast::channel('users.{id}', fn ($user, $id) => (int)$user->id === (int)$id);
```

One subscription carries all concurrent operations for the logged-in admin
(imports, exports, bulk delete). No operation-scoped channels are used.

SPA authorization endpoint (unchanged): `POST /api/v1/broadcasting/auth`
(`auth:sanctum`, registered in `packages/marvel/src/Rest/Routes.php`).

## Event Inventory

All events are emitted by `App\Events\FileOperationEvent`
(`ShouldBroadcastNow`, channel `private-users.{userId}`, payload via
`broadcastWith`). Event names follow the project convention
`{domain}.{operation}.{state}`:

| Constant | Wire name | Producer |
|---|---|---|
| PRODUCT_IMPORT_PROGRESS | `product.import.progress` | ProductImportService / ImportProductsJob |
| BRAND_IMPORT_PROGRESS | `brand.import.progress` | BrandImportService / ImportBrandsJob |
| CATEGORY_IMPORT_PROGRESS | `category.import.progress` | ImportCategoriesJob (terminal only; progress events keep legacy dual-channel contract via App\Events\CategoryImportProgress) |
| CATEGORY_EXPORT_COMPLETED | `category.export.completed` | ExportCategoriesJob |
| CATEGORY_EXPORT_FAILED | `category.export.failed` | ExportCategoriesJob |
| BRAND_EXPORT_COMPLETED | `brand.export.completed` | ExportBrandsJob |
| BRAND_EXPORT_FAILED | `brand.export.failed` | ExportBrandsJob |
| CATEGORY_BULK_DELETE_PROGRESS | `category.bulk-delete.progress` | BulkDeleteCategoriesJob (per chunk) |
| CATEGORY_BULK_DELETE_COMPLETED | `category.bulk-delete.completed` | BulkDeleteCategoriesJob |
| CATEGORY_BULK_DELETE_CANCELLED | `category.bulk-delete.cancelled` | BulkDeleteCategoriesJob::markCancelled |
| CATEGORY_BULK_DELETE_FAILED | `category.bulk-delete.failed` | BulkDeleteCategoriesJob |

Category import keeps its pre-existing wire contract untouched
(channels `admin.notifications` + `users.{id}`, payload keys
`import_id`/`type`). The terminal signal is additive on the same event name
with extra `status`/`kind`/`has_errors` keys plus the legacy
`import_id`/`type` keys preserved.

## Payload Contract

Safe whitelisted fields ONLY (built explicitly in
`app/Traits/BroadcastsFileOperationProgress.php`; never derived from request
or exception data):

```json
{
  "kind": "product-import",
  "id": 123,
  "status": "processing",
  "progress": 65,
  "processed_rows": 650,
  "success_rows": 640,
  "failed_rows": 10,
  "total_rows": 1000
}
```

Terminal variant:

```json
{
  "kind": "category-export",
  "id": 58,
  "status": "completed",
  "has_errors": false,
  "progress": 100,
  "total_rows": 1200,
  "processed_rows": 1200,
  "success_rows": 1200,
  "failed_rows": 0
}
```

`kind` values: `product-import`, `brand-import`, `category-import`,
`category-export`, `brand-export`, `category-bulk-delete`.

NEVER broadcast: filesystem paths, disk names, storage layout, secrets,
credentials, raw error arrays, exception traces, internal server details.
Detailed row errors are fetched through the existing
`download-errors` endpoints.

## Timing & Ordering Rules

1. Terminal events are emitted strictly AFTER the corresponding DB update
   (DB → Pusher, never before).
2. Terminal events fire at most once per process
   (`broadcastFileOperationTerminal()` guard). Retry attempts re-entering a
   terminal DB state early-return without broadcasting.
3. `failed()` hooks emit failure only when they actually transition
   `processing → failed`. A single retry is not a terminal failure.
4. Progress is monotonic-ish but not guaranteed ordered; clients clamp
   non-increasing progress and let terminal states win.
5. Duplicate delivery is expected to be tolerated by consumers
   (last-write-wins).

## Failure Isolation

Every broadcast is wrapped so that Pusher unavailability can never fail the
underlying operation (`dispatchFileOperationEvent()` catches Throwable,
logs `file-operation.event.broadcast_failed`, reports). Verified by
`BroadcastFailureIsolationTest`.

Gating (existing conventions):
- `config('app.env') === 'testing'` → disabled.
- `config('shop.pusher.enabled') === false` (`PUSHER_ENABLED`) → disabled.
- Operation continues normally when disabled.

## Frontend Contract

Phased polling replacement (frontend repo):

1. Phase 1 — keep the 2s loop, stop instantly when the matching event
   (`kind` + `id`) arrives.
2. Phase 2 — Pusher primary; low-frequency safety poll ONLY while socket
   disconnected / reconnecting / state uncertain.
3. Phase 3 — remove continuous loop; status endpoint remains for
   reconciliation.

Reconciliation rule:

```text
DB status > cached frontend state > Pusher history
```

Reconcile on: page mount, socket connect, socket reconnect, browser refresh,
and whenever a persisted active operation id exists. Route every event by
`(kind, id)`; one operation's events must not mutate another operation's UI.

States: `pending`, `processing`, `completed`,
`completed_with_errors`, `failed`, `cancelled`. Terminal always wins;
never regress displayed progress (80% → 40%) — clamp upward only.

## Security Model

- Channel auth: owner-only (`users.{id}`); foreign users denied at the
  broadcaster level (`AccessDeniedHttpException`).
- Unauthenticated clients cannot obtain channel authorization (sanctum on
  `/api/v1/broadcasting/auth`).
- The former unauthenticated `/test-pusher` debug route (Pusher key/cluster
  disclosure + anonymous admin-channel trigger) has been REMOVED
  (`routes/web.php`). Regression-pinned by `FileOperationSecurityTest`.
- Payload whitelist enforced by `assertPayloadIsSafe()` in tests.

## Test Map

| Suite | Covers |
|---|---|
| tests/Unit/FileOperationEventContractTest | channel/event/payload contract |
| tests/Feature/FileOperations/ProductImportBroadcastTest | product import progress/terminal/once-only/no-owner/log-after-dispatch |
| tests/Feature/FileOperations/BrandImportBroadcastTest | real dispatch (false log removed), finalize purity |
| tests/Feature/FileOperations/ExportBroadcastTest | export completed/failed, retry silence |
| tests/Feature/FileOperations/BulkDeleteBroadcastTest | chunk progress, completed/cancelled/failed exactly-once |
| tests/Feature/FileOperations/BroadcastFailureIsolationTest | Pusher outage ≠ operation failure |
| tests/Feature/FileOperations/FileOperationSecurityTest | channel IDOR, debug-route removal, unauthenticated auth |

Shared harness: `tests/Stubs/RecordingPusher.php` (real broadcaster, recorded
transport), `tests/Stubs/ThrowingPusher.php` (outage simulation),
base class `tests/Feature/FileOperations/FileOperationBroadcastTestCase.php`.

## Deferred Decisions (explicitly OUT of scope of ADR-002)

### G3 — Product Export async conversion
`GET /products/export` remains SYNCHRONOUS (`ProductsExport::download()`).
`ExportProductsJob` remains dormant/dead code. Requires separate architectural
decision before activation.

### G4 — Ownership scoping of operation endpoints
Status/cancel/download endpoints remain permission-wide (no `created_by`
check) as before. Hardening requires explicit security review.

### G7 — Signal-file storage scaling
Cancel/progress signal files stay on local disk (`storage/app/imports/`).
This constrains horizontal scaling (single shared filesystem assumed).

## Infrastructure Status

Verified on disk (2026-08-25): meem-high supervisor worker runs
`--timeout=1200 --stopwaitsecs=1230`; database connection
`retry_after=1560` (> highest job timeout on that connection). These values
were raised by the system-wide queue-standardization work recorded in
production-history.md and are compatible with all import/export jobs.

Residual observation (pre-existing, documented, not addressed here):
`ImportProductsJob/ImportBrandsJob/ImportCategoriesJob` declare job-level
`$timeout=1500`, which overrides the worker CLI default per-job.
`stopwaitsecs=1230` is therefore shorter than that specific job ceiling, so a
supervisor restart during an exceptionally long product import can SIGKILL
the worker after ~1230s (the job then retries per its `backoff` policy).
Queue behavior intentionally preserved in this pass.
