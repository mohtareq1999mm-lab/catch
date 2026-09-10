# Brand Import / Export — Frontend Jira Tasks

## Task 1: Admin Brand — Import Upload Dialog

**Priority:** High
**Component:** Frontend — Admin Brands Page
**Story Points:** 5

**API Endpoint:** `POST /api/v1/brands/import` (multipart `file`, permission `import-brand`)

**Description:** Add an "Import Brands" button that opens a file picker / drop zone and uploads to the brand import endpoint.

**Acceptance Criteria:**
- [ ] "Import Brands" CTA on the brands listing header
- [ ] Dialog with drop zone + file picker (accept `.xlsx,.xls,.ods`, max 20 MB)
- [ ] Client-side validation: wrong extension → inline error; >20 MB → "File too large"
- [ ] Upload as `FormData` (`file`); show spinner + "Queuing import…" while `POST` is in flight
- [ ] On 202 → close dialog, store `import_id`, start polling `GET /brands/import/{id}`
- [ ] On 422 → render `errors.file` under the input
- [ ] On 401/403 → show "Missing import-brand permission" / redirect to login

---

## Task 2: Admin Brand — Import Progress Drawer

**Priority:** High
**Component:** Frontend — Progress UI
**Story Points:** 5

**API Endpoint:** `GET /api/v1/brands/import/{id}`

**Description:** Show live progress for a queued brand import.

**Acceptance Criteria:**
- [ ] Drawer/modal appears after successful upload with `import_id`
- [ ] Poll every 2 s until terminal; display `progress` (0→100), `processed_rows/total_rows`, `successful_rows`, `failed_rows`
- [ ] Status badge: `pending` → `processing` → `completed` / `completed_with_errors` / `failed` / `cancelled` / transient `cancelling`
- [ ] `progress` reaches 100 only for `completed`/`completed_with_errors`
- [ ] On `completed_with_errors` → show "Finished with N errors" + "Download error report" CTA
- [ ] On `failed` → show `errors[0].error_message` (system row) + retry CTA
- [ ] On `cancelled` / `cancelling` → banner "Cancelling…"
- [ ] Optionally subscribe to `brand.import.progress` on `private:users.{id}` for push wake-up (then poll once)

---

## Task 3: Admin Brand — Cancel Import

**Priority:** Medium
**Component:** Frontend — Import Controls
**Story Points:** 3

**API Endpoint:** `POST /api/v1/brands/import/{id}/cancel`

**Description:** Let the user abort a pending/processing import.

**Acceptance Criteria:**
- [ ] "Cancel import" button visible while `pending`/`processing` (disabled in terminal)
- [ ] Confirmation dialog: "Canceling will soft-delete brands created in this import. Continue?"
- [ ] On 200 → poll reflects `cancelling` → `cancelled`
- [ ] On 409 (already terminal) → toast "Import cannot be cancelled…"
- [ ] After `cancelled`, refresh listing to reflect rollback (soft-deleted brands hidden)

---

## Task 4: Admin Brand — Download Error Report

**Priority:** Medium
**Component:** Frontend — Error Report
**Story Points:** 3

**API Endpoint:** `GET /api/v1/brands/import/{id}/download-errors` (binary `.xlsx`)

**Description:** Provide a downloadable workbook of failed rows for correction and re-upload.

**Acceptance Criteria:**
- [ ] CTA visible when `failed_rows > 0` (or `error_count > 0`)
- [ ] Click → `fetch` with `Authorization`, trigger download of `failed_brand_import_rows_{id}.xlsx`
- [ ] Workbook columns: `Sheet`, `Row`, `Name (EN)`, `Name (AR)`, `Error Message`
- [ ] Error empty → 404 `IMPORT_NO_ERRORS` → hide CTA with tooltip "No errors"
- [ ] Loading spinner while download streams; error toast on network failure

---

## Task 5: Admin Brand — Download Sample Template

**Priority:** Medium
**Component:** Frontend — Import Help
**Story Points:** 2

**API Endpoint:** `GET /api/v1/brands/import/sample` (binary `brand-import-sample.xlsx`)

**Description:** Help users generate a correct import file.

**Acceptance Criteria:**
- [ ] "Download template" link/button on import dialog + help tooltip
- [ ] Click → authenticated `GET`, download `brand-import-sample.xlsx`
- [ ] Docs: surface the 7-column contract (`name_en`/`name_ar`/`details_en`/`details_ar`/`status`/`image_desktop_url`/`image_mobile_url`) and note sheet `brands` must not be renamed, `id`/`slug` must be omitted
- [ ] 404 `IMPORT.SAMPLE_NOT_FOUND` → toast "Sample unavailable"

---

## Task 6: Admin Brand — Export All Brands

**Priority:** High
**Component:** Frontend — Admin Brands Page
**Story Points:** 5

**API Endpoints:** `GET /api/v1/brands/export` → `GET /api/v1/brands/export/{id}` → `GET /api/v1/brands/export/{id}/download`

**Description:** Let admins export all brands to Excel (same 7 columns as import).

**Acceptance Criteria:**
- [ ] "Export Brands" button on listing header (permission `export-brand`)
- [ ] Click → `GET /brands/export` → 202 with `export_id` + toast "Export queued"
- [ ] Poll `GET /brands/export/{id}` every 2 s until `completed` / `failed` (show spinner + "Preparing export…")
- [ ] On `completed` → enable "Download export" → `GET /download` → binary `brands-export-*.xlsx`
- [ ] On `failed` → toast with retry CTA
- [ ] Download before `completed` → 409 `EXPORT_NOT_READY` is handled (button stays disabled with tooltip)
- [ ] No query filters (exports all `id`-ordered); export file can be edited and re-imported

---

## Task 7: Admin Brand — Realtime Progress via Echo/Pusher

**Priority:** Medium
**Component:** Frontend — Realtime
**Story Points:** 3

**Events:** `brand.import.progress`, `brand.export.completed`, `brand.export.failed` on `private:users.{id}`

**Description:** Replace tight polling with a push-driven wake-up (progress still polled once after push).

**Acceptance Criteria:**
- [ ] `laravel-echo` subscribed to `private:users.{userId}` for `brand.import.progress` / `brand.export.*`
- [ ] On event → single `GET /brands/import/{id}` or `/brands/export/{id}` fetch for truth (payload is wake-up only)
- [ ] Graceful fallback: if no event in 3 s, continue polling at 2 s
- [ ] Connection error → fallback to polling with toast "Realtime unavailable, polling"

---

## Task 8: Admin Brand — Loading / Empty / Error States

**Priority:** High
**Component:** Frontend — Shared States
**Story Points:** 3

**Description:** Handle non-happy states for import/export surfaces.

**Acceptance Criteria:**
- [ ] **Import upload:** disabled button + skeleton during `POST`
- [ ] **Polling empty:** `total_rows≈0` → "Empty file" warning
- [ ] **Completed** → toast "Brand import finished" + listing refresh (new/updated brands visible)
- [ ] **Completed with errors** → yellow banner with `failed_rows` count + error download CTA
- [ ] **Failed** → red banner with `errors[0].error_message`
- [ ] **Export pending/processing** → spinner + "Preparing export…"
- [ ] **Export 409** → disabled download button + "Export still processing"
- [ ] **Network error** → "Network error, please try again" with retry button on all file-operation calls
