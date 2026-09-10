# Dashboard Invoices — Test Cases (for QA execution)

| # | Endpoint | Method | Auth | Steps | Expected |
|---|----------|--------|------|-------|----------|
| TC01 | `/` | GET | Sanctum + view-invoices | List default | 200 paginated |
| TC02 | `/` | GET | Guest | No token | 401 |
| TC03 | `/` | GET | Auth missing permission |  | 403 |
| TC04 | `/` | GET | Sanctum + view-invoices | `?status=ready&limit=5` | 200 filtered 5 |
| TC05 | `/{id}` | GET | Sanctum + view-invoice | Valid id | 200 AdminInvoiceResource |
| TC06 | `/{id}` | GET | Guest |  | 401 |
| TC07 | `/{id}` | GET | whereNumber | `id=abc` | 404 routing |
| TC08 | `/{id}` | GET | Unknown id | 99999 | 404 |
| TC09 | `/uuid/{uuid}` | GET | Sanctum + view-invoice | Valid uuid | 200 same as id |
| TC10 | `/uuid/{uuid}` | GET | Malformed uuid | not-uuid | 404 |
| TC11 | `/{uuid}/view` | GET | Owner | Valid uuid, pdf exists | 200 inline PDF |
| TC12 | `/{uuid}/view` | GET | Non-owner no permission |  | 404 privacy |
| TC13 | `/{uuid}/view` | GET | Non-owner with view-invoice-download |  | 200 |
| TC14 | `/{uuid}/view` | GET | pdf_path null |  | 404 PDF not yet generated |
| TC15 | `/{uuid}/view` | GET | 31st request in 1 min |  | 429 |
| TC16 | `/{uuid}/download` | GET | Owner |  | 200 attachment + downloaded_at set |
| TC17 | `/{uuid}/download` | GET | File missing on disk |  | 404 |
| TC18 | `/verify/{uuid}` | GET | Sanctum | Authentic | 200 authentic true verify_count++ |
| TC19 | `/verify/{uuid}` | GET | Sanctum | Tampered | 409 |
| TC20 | `/verify/{uuid}` | GET | Unknown uuid |  | 404 |
| TC21 | `/verify/{uuid}` | GET | 6th in 1 min |  | 429 |
| TC22 | `/{id}/regenerate` | POST | Sanctum + regenerate-invoice | failed invoice | 200 pdf_generating |
| TC23 | `/{id}/regenerate` | POST | Wrong status | cancelled | 422 |
| TC24 | `/{id}/correct` | POST | Sanctum + correct-invoice | {reason, overrides} | 200 correction |
| TC25 | `/{id}/correct` | POST | Missing reason |  | 422 |
| TC26 | `/{id}/cancel` | POST | Sanctum + cancel-invoice | {reason} | 200 cancelled |
| TC27 | `/{id}/cancel` | POST | Missing reason |  | 422 |
| TC28 | `/{id}/debit-note` | POST | Sanctum + issue-debit-note | {amount, reason} | 201 |
| TC29 | `/{id}/debit-note` | POST | amount 0 |  | 422 |
| TC30 | `/{id}/debit-note` | POST | Wrong status | cancelled | 422 |
