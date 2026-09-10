# Category Import / Export — Frontend Jira Tasks

## Task 1: Admin Category Import — File Upload + Progress Modal

**Priority:** High
**Component:** Frontend — Admin Import UI
**Story Points:** 5

**Description:** Build the import UI: upload an Excel file, then show live progress until the import finishes.

**API Endpoints:**
- `POST /api/v1/categories/import` (multipart `file`)
- `GET /api/v1/categories/import/{import_id}` (poll)
- `GET /api/v1/categories/import/sample` (template download)

**Acceptance Criteria:**
- [ ] Drag-and-drop + file picker for `.xlsx` / `.xls` / `.ods` (max 20 MB client check)
- [ ] Upload as `multipart/form-data` with `file` field
- [ ] On 202, open progress modal and poll every 2s
- [ ] Progress bar from `data.progress` (0 → 100)
- [ ] Counters: processed/total, successful, failed
- [ ] **Loading state:** spinner + progress bar
- [ ] **Empty/disabled state:** disable submit without a file
- [ ] **Error state (422):** inline field errors (`errors.file`)
- [ ] **Error state (401/403):** toast, redirect to login / deny

---

## Task 2: Admin Category Import — Result & Error Report

**Priority:** High
**Component:** Frontend — Import Result
**Story Points:** 3

**Description:** Handle the terminal states and let the user download the error report.

**API Endpoints:**
- `GET /api/v1/categories/import/{import_id}/download-errors`

**Acceptance Criteria:**
- [ ] `completed` → success toast "Import finished successfully"
- [ ] `completed_with_errors` → warning "N rows failed" + download error report button
- [ ] `failed` → error state, suggest re-upload
- [ ] `cancelled` → info state
- [ ] Download error report as `.xlsx` (columns Sheet, Row, Name (EN), Name (AR), Parent Name (EN), Error Message)
- [ ] **Error state (404 'No errors found'):** hide download button

---

## Task 3: Admin Category Import — Cancel Action

**Priority:** Medium
**Component:** Frontend — Import Progress
**Story Points:** 2

**Description:** Allow the user to cancel a running import.

**API Endpoint:**
- `POST /api/v1/categories/import/{import_id}/cancel`

**Acceptance Criteria:**
- [ ] Cancel button visible only while `pending` / `processing`
- [ ] Confirmation dialog before cancelling
- [ ] On 200 → poll shows `cancelled`
- [ ] On 409 (already terminal) → hide cancel, show toast

---

## Task 4: Admin Category Export — Queue & Download

**Priority:** High
**Component:** Frontend — Admin Export UI
**Story Points:** 3

**Description:** Add an export button that queues an export and downloads the generated file.

**API Endpoints:**
- `GET /api/v1/categories/export`
- `GET /api/v1/categories/export/{export_id}`
- `GET /api/v1/categories/export/{export_id}/download`

**Acceptance Criteria:**
- [ ] Export button on the category listing toolbar
- [ ] On 202, poll status every 2s (spinner while `pending` / `processing`)
- [ ] On `completed` → trigger download
- [ ] On `failed` → error toast
- [ ] **Error state (409):** file not ready — keep polling or toast "not ready yet"

---

## Task 5: Category Import — Template Guidance UI

**Priority:** Low
**Component:** Frontend — Import Modal
**Story Points:** 2

**Description:** Help the user produce a valid file: download the template and show the expected columns.

**Acceptance Criteria:**
- [ ] "Download template" link calls `GET /categories/import/sample`
- [ ] Tooltip/help lists the 9 columns: `name_en`, `name_ar`, `details_en`, `details_ar`, `parent_name_en`, `status`, `is_featured`, `image_desktop_url`, `image_mobile_url`
- [ ] Note: do not add `id` / `slug` / `parent_id` / `parent_slug` columns
- [ ] Note: `name_en` is the identity — re-importing updates existing categories