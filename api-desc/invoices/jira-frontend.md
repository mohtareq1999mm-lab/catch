# Dashboard Invoices — Frontend Jira Tasks

## Task 1: Admin Invoice Listing — Table with Filters/Sort

**Priority:** High
**Component:** Frontend — Admin Invoices Page
**Story Points:** 8

**API Endpoint:**
- `GET /api/v1/invoices?search=&status=&order_id=&user_id=&invoice_series=&currency=&from=&to=&sort_by=&sort_direction=&limit=&page=`

**Acceptance Criteria:**
- [ ] Table columns: checkbox, invoice_number (link), status badge (color-coded), customer/order, total/currency, payment_method, pdf_generated_at, actions
- [ ] Pagination from `AdminInvoiceCollection` envelope (page, per_page, total, next/prev)
- [ ] Search input (debounced 300ms) on `search`
- [ ] Status filter select (all, pending, generated, ready, failed, verified, downloaded, printed, corrected, cancelled, archived)
- [ ] Date range pickers for `from`/`to`
- [ ] Sortable headers for `created_at`, `total`, `status`, `invoice_number`
- [ ] Amount filters: `currency`, `invoice_series`
- [ ] Row click → detail drawer
- [ ] Loading skeleton, empty "No invoices", error with retry

---

## Task 2: Admin Invoice Detail Drawer/Page

**Priority:** High
**Component:** Frontend — Invoice Detail
**Story Points:** 5

**API Endpoints:**
- `GET /api/v1/invoices/{id}`
- `GET /api/v1/invoices/uuid/{uuid}`

**Acceptance Criteria:**
- [ ] Header: invoice_number, status badge, total/currency, payment gateway
- [ ] Timeline mini (last 10) when `timeline` relation present
- [ ] Snapshot sections: order, customer, addresses, items table, pricing_breakdown, payment, audit
- [ ] QR card: `qr_content` object + `verification_url` link + QR image (generate client-side from url)
- [ ] Hash row: truncated `snapshot_hash` / `verification_hash` with copy button
- [ ] Action buttons: View, Download, Regenerate (if failed/ready/generated), Correct, Cancel, Debit Note — disabled per status/permission
- [ ] Loading skeleton while fetching, 404 "Invoice not found"

---

## Task 3: PDF View (Inline Preview) Modal

**Priority:** High
**Component:** Frontend — PDF Preview
**Story Points:** 5

**API Endpoint:**
- `GET /api/v1/invoices/{uuid}/view` — auth header, throttle 30/min

**Acceptance Criteria:**
- [ ] "Preview" button triggers fetch→blob (header auth, not query)
- [ ] Modal with `<iframe src={blobUrl}>` or `<object>`
- [ ] Loading spinner while fetching, error toast on 404 "PDF not yet generated" → show Regenerate CTA
- [ ] Close revokes `URL.createObjectURL`
- [ ] Throttle: debounce clicks, show cooldown on 429 with Retry-After
- [ ] Non-owner without permission → 404 privacy (show "Not found")

---

## Task 4: PDF Download (Attachment) Button

**Priority:** High
**Component:** Frontend — PDF Download
**Story Points:** 3

**API Endpoint:**
- `GET /api/v1/invoices/{uuid}/download` — auth header, throttle 30/min, records `downloaded_at`

**Acceptance Criteria:**
- [ ] "Download" button triggers fetch→blob → `a.download` with filename from `Content-Disposition` or `invoice_number.pdf`
- [ ] Update row `downloaded_at` optimistically after success
- [ ] Handle 404 "PDF not yet generated" vs 404 privacy vs 429
- [ ] Cannot use plain `<a href>` (needs Authorization header)

---

## Task 5: Verify Authenticity — QR/Verify Page

**Priority:** Medium
**Component:** Frontend — Verify Page
**Story Points:** 3

**API Endpoint:**
- `GET /api/v1/invoices/verify/{uuid}` — auth, throttle 5/min, no permission required

**Acceptance Criteria:**
- [ ] Input or QR scan provides UUID → call verify
- [ ] Success `authentic:true` → green badge + invoice+order summary + qr_content link
- [ ] Tampered `409` → red "Tampered" banner + `authentic:false`
- [ ] 404 → "Invoice not found"
- [ ] Increment display: `verify_count`, `last_verified_at`
- [ ] Throttle: disable button + countdown on 429

---

## Task 6: Regenerate PDF — Admin Action

**Priority:** Medium
**Component:** Frontend — Regenerate
**Story Points:** 3

**API Endpoint:**
- `POST /api/v1/invoices/{id}/regenerate` — empty body, permission `regenerate-invoice`

**Acceptance Criteria:**
- [ ] Show on statuses `failed`, `ready`, `generated`; hide/disable otherwise with tooltip "Cannot regenerate in current status"
- [ ] Confirm dialog → POST → toast "PDF generation queued"
- [ ] Poll `GET /{id}` every 3s until `status==='ready'` with pdf_path, or `failed` → show `last_generation_error` + retry
- [ ] Handle 422 (wrong status), 403, 404
- [ ] Disable button while generating

---

## Task 7: Correct Invoice — Modal Form

**Priority:** Medium
**Component:** Frontend — Correction
**Story Points:** 5

**API Endpoint:**
- `POST /api/v1/invoices/{id}/correct` — body `{ reason, overrides }`, permission `correct-invoice`

**Acceptance Criteria:**
- [ ] Modal form: `reason` required textarea + `overrides` section (total, amount_paid, shipping_price, customer fields, addresses, notes)
- [ ] Client validation: reason required/max 500, numeric overrides min 0
- [ ] On submit → handle 422 field errors inline, 422 status-guard toast, 403, 404
- [ ] On success: navigate to new correction invoice detail (returned resource), toast "Invoice corrected"
- [ ] Disable form during submit

---

## Task 8: Cancel Invoice — Reason Dialog

**Priority:** Medium
**Component:** Frontend — Cancel
**Story Points:** 2

**API Endpoint:**
- `POST /api/v1/invoices/{id}/cancel` — body `{ reason }`, permission `cancel-invoice`

**Acceptance Criteria:**
- [ ] "Cancel" button → dialog with reason textarea required
- [ ] Guard: hide if status `cancelled`/`archived`
- [ ] On success: update detail status to `cancelled`, toast
- [ ] Handle 422, 403, 404

---

## Task 9: Issue Debit Note — Amount/Reason Form

**Priority:** Medium
**Component:** Frontend — Debit Note
**Story Points:** 3

**API Endpoint:**
- `POST /api/v1/invoices/{id}/debit-note` — body `{ amount, reason }`, permission `issue-debit-note`

**Acceptance Criteria:**
- [ ] Dialog: amount number input (min 0.01) + reason textarea
- [ ] Guard: only `generated/ready/verified/downloaded/printed` → else disable
- [ ] On success: show `debit_note_number` (e.g. DN-...), amount, append to debitNotes list
- [ ] Handle 422 validation/status, 403, 404

---

## Task 10: Global States — Loading/Empty/Error/Throttle

**Priority:** High
**Component:** Frontend — State Handling
**Story Points:** 3

**Acceptance Criteria:**
- [ ] Listing loading: skeleton rows (5)
- [ ] Listing empty: "No invoices match filters" + Clear CTA
- [ ] Detail loading: skeleton card, 404 page with "Not found"
- [ ] Form loading: skeleton + disabled submit
- [ ] Network error: toast "Network error, retry"
- [ ] 429 on any of view/download/verify: toast "Too many requests, try in Xs" reading `Retry-After`
- [ ] Permission 403: inline "Missing permission: X" + contact admin CTA
