# Dashboard Invoices — Bug Report

| # | Severity | Title | Location | Status |
|---|----------|-------|----------|--------|
| B1 | Medium | Missing `whereUuid` on `verify/{uuid}` and `uuid/{uuid}` in reviewed snippet | `Routes.php` (snippet) | Open |
| B2 | Low | `view` route absent from production admin group despite controller method existing | `Routes.php:391-403` vs `InvoiceController:273` | Open — snippet adds it |
| B3 | Low | `cancel` uses inline `validate` while `correct`/`debit-note` use FormRequest | `InvoiceController:182` | Open |
| B4 | Medium | `download_url` in `AdminInvoiceResource` points to `/invoices/{uuid}/download` (this group) but customer resource historically built non-existent `/general/.../download` | `AdminInvoiceResource:50` | Fixed in admin, legacy customer confusion remains |
| B5 | Low | `pdfFileResponse` uses `request()->user()` global helper instead of injected `Request` | `InvoiceController:285` | Open |
| B6 | High | Prior `InvoiceStatus` missing `ready → pdf_generating` edge → 500 on regenerate | `InvoiceStatus.php:20` | Fixed (INV-002) |
| B7 | High | Prior `{id}` routes lacked `whereNumber` → TypeError 500 on `abc` | `Routes.php` | Fixed (INV-001) |
| B8 | Medium | `verify` documented as public `throttle:60,1` but is `auth:sanctum` + `throttle:5,1` | Docs vs `InvoiceController:202` | Documented contradiction |

## Details

**B1** — Add `->whereUuid('uuid')` to both routes. Without it, `firstOrFail()` on malformed UUID throws `ModelNotFoundException` → handler 404 (works) but bypasses routing-level 404 and may log unnecessary exception. Cleaner to reject at routing.

**B2** — `InvoiceController::view` is production-ready (streams inline, no side effects). Not routed under admin prefix, only signed `v1/general/invoices/view/{uuid}`. Dashboard snippet correctly adds it; merge the addition.

**B3** — Extract to `CancelInvoiceRequest` for consistency.
