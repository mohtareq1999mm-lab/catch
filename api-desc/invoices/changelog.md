# Dashboard Invoices — Changelog

## [1.1.0] — 2026-09-07 — Reviewed Group Investigation

### Documented
- 10-route admin group under `Route::prefix('invoices')->middleware('auth:sanctum')` → `/api/v1/invoices/*` (snippet under review).
- Inline `view` route `GET /{uuid}/view` (whereUuid, throttle:30,1) — streams inline, no download bookkeeping — previously absent from admin group.
- Division of permission enforcement: constructor middleware for collection/show/mutations, inline owner/permission for PDF streams (`InvoiceController::pdfFileResponse:280`).
- Verified controller mapping: `app/Http/Controllers/Api/InvoiceController.php:26` (single controller for both admin and customer prefixes).

### Divergence Detected (vs production `Routes.php:391-403`)
- `view` missing from production admin group; PDF streams currently only via signed `v1/general/invoices/view/{uuid}` + authenticated `/{uuid}/download`. Snippet adds symmetric `view` to admin — recommend adding with `whereUuid` + `throttle:30,1`.
- `verify/{uuid}` and `uuid/{uuid}` in snippet lack `whereUuid` — production customer side enforces `whereUuid`; add for consistency.
- Throttle values confirmed: `download`/`view` 30,1 — `verify` 5,1.

### Added Docs
- `README.md` — overview, key files, permissions, route table
- `api.md` — per-endpoint spec with query/body/200/4xx examples + curl
- `backend.md` — architecture, route definitions, controller flow, services, model, state machine, resources
- `flow.md` — 10 flows + state machine diagram + auto-generation flow
- `database.md` — schema, indexes, migrations, query patterns
- `frontend.md` — dashboard integration guide (list, detail, view/download blob, verify, regenerate poll, correct/cancel/debit-note)
- `qa.md` — functionality/validation/auth/edge/throttle/state tests
- `changelog.md` — this file
- `jira.md` — tasks

---

## [1.0.0] — 2026-08-22 — Prior Invoice Docs (in `api-desc/invoice/`)

### Added
- `InvoiceResource` restored → `verify` returns full invoice data on authentic path.
- `ready → pdf_generating` transition legalized in `InvoiceStatus` (INV-002 fix); controller `regenerate` allowlist now aligned.
- Signed customer PDF routes `v1/general/invoices/view/{uuid}` + `download/{uuid}` (signed middleware).
- Canonical customer endpoints `my-invoices`, `orders/{orderId}/invoice` (owner-scoped).

### Fixed
- `whereNumber` on `{id}` routes → malformed ID no longer reaches controller (TypeError/500 → 404).
- `ModelNotFoundException` in `correct`/`cancel` rethrown → handler 404 envelope (never 500).

## Known Issues

1. **Snippet missing `whereUuid` on verify/uuid** — malformed UUID would reach controller and throw 500 via `firstOrFail` vs clean 404 at routing.
2. **No `permission` on `verify`** — intentional but inconsistent with `permission:view-invoice` on `uuid/{uuid}`; both touch same resource but different auth posture.
3. **`pdfFileResponse` uses `request()->user()` not `$request`** — subtle global helper vs injected request; works but less testable.
4. **`download_url` in `AdminInvoiceResource` builds `url('/api/v1/invoices/' + uuid + '/download')`** — matches this group; customer resources historically built non-existent `/general/.../download` — confusion until unified.
5. **`view` added to admin group without diff** — ensure frontend dashboard uses header-auth fetch→blob (cannot use plain `<a href>`).
