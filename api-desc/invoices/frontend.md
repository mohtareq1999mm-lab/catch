# Dashboard Invoices — Frontend Integration Guide

> Admin dashboard consumes `GET /api/v1/invoices/*`. All calls require `Authorization: Bearer <sanctum_token>` and permission-guarded roles. PDFs stream as binary — not JSON.

---

## 1. List Invoices (Admin Table)

**Endpoint:** `GET /api/v1/invoices`

**Use:** Dashboard invoice list with filters/sort/pagination. Powers search bar, status tabs, date range.

**Request:**
```js
const params = new URLSearchParams({
  search: 'INV-2026', status: 'ready', order_id: '101', user_id: '5',
  invoice_series: 'INV', currency: 'EGP', from: '2026-08-01', to: '2026-08-31',
  sort_by: 'total', sort_direction: 'desc', limit: '20', page: '1'
});
fetch(`/api/v1/invoices?${params}`, { headers: { Authorization: `Bearer ${token}`, Accept: 'application/json' }})
  .then(r=>r.json()).then(({data})=> { /* data.data = rows, data.total = count */ });
```

**Pagination:** Use `data.current_page`, `data.last_page`, `data.next_page_url`, `data.total`. Always cap `limit` at 100 client-side.

**Filters UX:** Debounce `search` (300ms). Enum select for `status` (pending, generating, generated, pdf_generating, ready, failed, verified, downloaded, printed, corrected, cancelled, archived). Date pickers for `from`/`to`.

**Empty state:** `data.data.length === 0` → show "No invoices match filters" + Clear filters CTA.

**Error:** `401` → redirect login, `403` → show "Missing view-invoices permission".

---

## 2. Show Invoice Detail (by ID or UUID)

**Endpoints:**
- `GET /api/v1/invoices/{id}` (numeric)
- `GET /api/v1/invoices/uuid/{uuid}`

**Use:** Detail drawer / page. Prefer `uuid` route when navigating from list row's `uuid` to avoid exposing sequential IDs.

**Request:**
```js
fetch(`/api/v1/invoices/${id}`, { headers: { Authorization: `Bearer ${token}` }})
fetch(`/api/v1/invoices/uuid/${uuid}`, { headers: { Authorization: `Bearer ${token}` }})
```

**Response fields:** Use `AdminInvoiceResource` keys: `invoice_number`, `status` badge, `total`/`currency`, `payment_method`, `snapshot_hash`, `pdf_generated_at`, `verify_count`, `is_correction`, `correction_reason`, `cancelled_at`, plus `snapshot` (frozen order), `qr_content`, `verification_url`, `view_url`, `download_url`.

**Snapshot:** Render `data.snapshot` with versioned sections: order, customer, addresses, items, pricing_breakdown, payment, audit. Treat as read-only.

**Timeline:** If `?include=timeline` not needed — resource includes last 10 when `timeline` relation loaded (admin show does not auto-load timeline; request separately if needed via dedicated timeline endpoint or expand param if added).

---

## 3. View PDF Inline (Preview)

**Endpoint:** `GET /api/v1/invoices/{uuid}/view` (add `whereUuid`, `throttle:30,1`)

**Use:** Preview button / modal iframe. Does NOT mark as downloaded.

**Request (fetch→blob required because auth is header, not query):**
```js
async function previewInvoice(uuid) {
  const res = await fetch(`/api/v1/invoices/${uuid}/view`, {
    headers: { Authorization: `Bearer ${token}` },
  });
  if (!res.ok) {
    if (res.status === 404) { /* check JSON body: is pdf_path null? */ }
    throw new Error('Preview failed');
  }
  const blob = await res.blob(); // application/pdf
  const url = URL.createObjectURL(blob);
  // <iframe src={url} /> or window.open(url,'_blank')
  return url; // revoke with URL.revokeObjectURL(url) on unmount
}
```

**Headers:** Server returns `Content-Disposition: inline`. Browser renders natively.

**Throttle:** 30/min per IP+user; debounce preview clicks, cache blob URL for session.

**Errors:** `404` with `{ message:"PDF not yet generated" }` → show "PDF generating" + Regenerate CTA; `404` ownership → show "Not found" (do not leak).

---

## 4. Download PDF

**Endpoint:** `GET /api/v1/invoices/{uuid}/download`

**Use:** Download button. Marks `downloaded_at` on first click via `recordDownloaded`.

**Request:**
```js
async function downloadInvoice(uuid, filename) {
  const res = await fetch(`/api/v1/invoices/${uuid}/download`, {
    headers: { Authorization: `Bearer ${token}` },
  });
  if (!res.ok) {
    const body = await res.json().catch(()=>null);
    if (body?.message === 'PDF not yet generated') { /* show pending */ }
    throw new Error(body?.message || 'Download failed');
  }
  const blob = await res.blob();
  const url = URL.createObjectURL(blob);
  const a = document.createElement('a');
  a.href = url; a.download = filename || `${uuid}.pdf`; a.click();
  URL.revokeObjectURL(url);
}
```

**Server also sets** `Content-Disposition: attachment`. For direct `<a download>` without JS, token must be sent via header — cannot use plain `<a href>` unless backend adds query-token support (it doesn't). So fetch→blob is required.

**UI:** After success, update row's `downloaded_at` optimistically.

---

## 5. Verify Authenticity

**Endpoint:** `GET /api/v1/invoices/verify/{uuid}` (`throttle:5,1`)

**Use:** QR scan page or admin verify button.

**Request:**
```js
fetch(`/api/v1/invoices/verify/${uuid}`, { headers: { Authorization: `Bearer ${token}` }})
  .then(r=> r.status === 409 ? r.json().then(b=> ({tampered:true,...b})) : r.json())
  .then(({data})=> {
    if (data.tampered) { /* show red Tampered */ }
    else if (data.authentic) { /* green Authentic + render data.invoice + data.order */ }
  });
```

**Rate limit:** 5/min — throttle UI clicks, show countdown on 429.

**Auth note:** Even though this is under `invoices` prefix, any authenticated user may call it (no permission). Consider moving to public verify with token-less QR in future.

---

## 6. Regenerate PDF

**Endpoint:** `POST /api/v1/invoices/{id}/regenerate` (empty body)

**Use:** Admin action on `failed` or stale `ready`/`generated` invoices.

**Request:**
```js
fetch(`/api/v1/invoices/${id}/regenerate`, {
  method: 'POST',
  headers: { Authorization: `Bearer ${token}`, Accept: 'application/json' }
}).then(r=>r.json()).then(({data})=> {
  // data.status === 'pdf_generating' — poll or subscribe via timeline
});
```

**Polling:** After 200, poll `GET /api/v1/invoices/${id}` every 3s until `status==='ready'` and `pdf_path` present, or `status==='failed'` → show `last_generation_error` + retry.

**Allowed statuses:** `failed`, `ready`, `generated` → other statuses → `422`.

---

## 7. Correct Invoice

**Endpoint:** `POST /api/v1/invoices/{id}/correct`

**Use:** Admin correction modal (wrong total, address, etc.). Creates new invoice, archives old as `corrected`.

**Request:**
```js
fetch(`/api/v1/invoices/${id}/correct`, {
  method: 'POST',
  headers: { Authorization: `Bearer ${token}`, 'Content-Type':'application/json' },
  body: JSON.stringify({
    reason: 'Wrong total charged',
    overrides: {
      total: 95.0, amount_paid: 95.0, shipping_price: 0,
      customer: { name: 'Corrected Name', email: 'corrected@example.com' },
      billing_address: { city: 'Cairo' },
      notes: 'Adjusted per ticket #123'
    }
  })
}).then(r=>r.json())
```

**Validation:** `reason` required. `overrides` optional deep object. On success, navigate to new `data.id`/`uuid` and show "Correction created: INV-...".

**Status guard:** Only `generated`/`ready`/`verified`/`downloaded`/`printed` may be corrected → else 422.

---

## 8. Cancel Invoice

**Endpoint:** `POST /api/v1/invoices/{id}/cancel`

**Use:** Admin cancel with reason.

**Request:**
```js
fetch(`/api/v1/invoices/${id}/cancel`, {
  method: 'POST',
  headers: { Authorization: `Bearer ${token}`, 'Content-Type':'application/json' },
  body: JSON.stringify({ reason: 'Order refunded' })
})
```

**Validation:** `reason required max:500`. Status guard excludes `cancelled`/`archived`/`pending`.

---

## 9. Issue Debit Note

**Endpoint:** `POST /api/v1/invoices/{id}/debit-note`

**Use:** Admin debit note for additional charges.

**Request:**
```js
fetch(`/api/v1/invoices/${id}/debit-note`, {
  method: 'POST',
  headers: { Authorization: `Bearer ${token}`, 'Content-Type':'application/json' },
  body: JSON.stringify({ amount: 25.0, reason: 'Additional shipping' })
})
```

**Validation:** `amount min:0.01`, `reason max:500`. Status guard same as correct.

---

## Common Frontend Patterns

**Auth:** All 10 require Sanctum bearer — include on every request. Handle `401` globally (redirect login, clear token).

**Permissions:** Hide buttons if user lacks permission (peek `user.permissions` from `me` endpoint or handle `403` with toast "You don't have permission: view-invoices").

**Throttle UX:** Catch `429` → read `Retry-After` header → show "Too many requests, try in Xs".

**Error mapping:**
| Status | UI |
|--------|----|
| 404 id/uuid | "Invoice not found" (also covers owner-check privacy) |
| 404 pdf_path | "PDF not yet generated" + show status + Regenerate button |
| 422 | Inline field errors (`errors.reason`) or toast for status guard |
| 409 verify | Red "Tampered" banner |
| 500 | Generic retry + report |

**Performance:** List uses `with(['order','user'])` only — fast. Detail loads heavier (`order.orderItems`, `transaction`). Do not request detail for list rows.

**Caching:** Do not cache `verify` (verify_count increments) or PDF binary. List may be cached 30s client-side; invalidate on correct/cancel/debit-note/regenerate.

**Security:** Never render `verification_hash`/`snapshot_hash` as clickable links; show as truncated monospace. Signed customer PDF routes (`v1/general/invoices/view/{uuid}`) are separate — dashboard `view`/`download` are header-auth only.
