# QA - Order Feature

Executed suites (verified passing):

- `tests/Feature/OrdersProductionHardenTest.php` — **38/38 OK**, 83 assertions
- `tests/Feature/OrderCreationFlowTest.php` — **17/17 OK**, 57 assertions

## Test Matrix

### Customer

| TC ID | Description | Expected |
|-------|-------------|----------|
| TC-ORD-101 | Customer list returns only own orders | data[] + links{} |
| TC-ORD-102 | Customer list includes `order_has_invoice` / `invoice_id` | Present |
| TC-ORD-103 | Customer list respects limit (15, 50, 100) | Correct count |
| TC-ORD-104 | Customer list caps limit > 100 | Capped to 100 |
| TC-ORD-105 | Customer invoice view returns 200 for owner (legacy uuid route) | 200 + CustomerInvoiceResource |
| TC-ORD-106 | Customer invoice view returns 403 for non-owner | 403 |
| TC-ORD-107 | Customer invoice view returns 404 for unknown uuid | 404 |
| TC-ORD-108 | **Order-ID invoice**: pending order → 404 | 404 |
| TC-ORD-109 | **Order-ID invoice**: processing → 200, same payload as legacy route | 200 + identical data |
| TC-ORD-110 | **Order-ID invoice**: lifecycle keeps ONE original; repeated GETs stable | Invoice ×1 |
| TC-ORD-111 | **Order-ID invoice**: foreign/missing order → clean 404, no leak | 404 |
| TC-ORD-112 | **Order-ID invoice**: correction present → returns latest doc | 200 = correction uuid |
| TC-ORD-108 | Unauthenticated customer requests return 401 | Both endpoints |

### Admin

| TC ID | Description | Expected |
|-------|-------------|----------|
| TC-ORD-001 | List returns paginated data array | data[] + links{} |
| TC-ORD-002 | List respects limit param (15, 50, 100) | Correct count |
| TC-ORD-003 | List rejects limit > 100 | Caps to 100 |
| TC-ORD-004 | List filters by status (5 DB values) | Only matching |
| TC-ORD-005 | List searches by name/email/phone | Matching records |
| TC-ORD-006 | List date range filter works | Date-bound results |
| TC-ORD-007 | Detail returns full order by ID | 200 + data |
| TC-ORD-008 | Detail returns full order by tracking number | 200 + data |
| TC-ORD-009 | Detail returns 404 for invalid ID | 404 |
| TC-ORD-010 | Detail includes financial/items/transactions | Conditional fields present |
| TC-ORD-011 | Unauthenticated returns 401 | Both endpoints |
| TC-ORD-012 | Forbidden returns 403 | Without permission |
| TC-ORD-013 | List does NOT include order_items/transactions | Absent from list items |
| TC-ORD-014 | Detail envelope shape | `{status, message, success, data}` |

### Status Change Endpoint (`PATCH /api/v1/orders/{id}/status`)

| TC ID | Description | Expected |
|-------|-------------|----------|
| TC-ORD-201 | Valid transition `pending → processing` | 200 + updated resource, status persisted |
| TC-ORD-202 | Valid transition `processing → completed` | 200; `payment_status` synced, `completed_at` set, tx→paid |
| TC-ORD-203 | Valid transition `completed → delivered` | 200; fulfillment_status = delivered |
| TC-ORD-204 | First-time cancellation `pending → cancelled` | 200; `cancelled_at` set, tx→failed, inventory restored |
| TC-ORD-205 | Invalid transition `completed → cancelled` | 422 with translated message |
| TC-ORD-206 | Invalid transition `delivered → any` | 422 (terminal) |
| TC-ORD-207 | Invalid transition `cancelled → completed` | 422 (terminal) |
| TC-ORD-208 | Invalid status value (`refunded`, `order-pending`, …) | 422 validation error |
| TC-ORD-209 | Missing `status` field | 422 validation error |
| TC-ORD-210 | Nonexistent order ID | 404 |
| TC-ORD-211 | User without `update-order-status` permission | 403 |
| TC-ORD-212 | Unauthenticated request | 401 |
| TC-ORD-213 | Same-status re-set (`pending → pending`) | 200; allowed by matrix; new `OrderStatusChanged` fired |
| TC-ORD-214 | Event assertion on success | `OrderStatusChanged` dispatched (+ `OrderCancelled` when cancelling) |
| TC-ORD-215 | Queue assertion on listeners | Queued to `meem-medium` |

### Payment-driven status paths

| TC ID | Description | Expected |
|-------|-------------|----------|
| TC-ORD-301 | COD mark-paid with pending transaction | 200; order→completed, tx→paid+paid_at |
| TC-ORD-302 | COD mark-paid without pending transaction | 422 |
| TC-ORD-303 | COD mark-paid already paid | 422 (idempotent guard) |
| TC-ORD-304 | Cashier mark-paid equivalents | As 301–303 |
| TC-ORD-305 | Callback missing paymentId | 400 |
| TC-ORD-306 | Callback success completes pending order once | Idempotent: second call is a no-op |
| TC-ORD-307 | Callback failure fires `PaymentFailed` | Event dispatched |
| TC-ORD-308 | Callback amount/currency mismatch blocks completion | Order stays pending |

## Manual Test Checklist

- [ ] Verify all admin filter parameters work individually and combined
- [ ] Verify pagination links are correct (customer has `last_page_url`/`first_page_url`)
- [ ] Verify admin detail endpoint resolves by both ID and tracking number
- [ ] Verify conditional fields only appear on admin show route
- [ ] Verify customer list is scoped to authenticated user
- [ ] Verify `order_has_invoice` reflects invoice existence and `invoice_id` is the uuid
- [ ] Verify customer invoice endpoint rejects non-owner with 403
- [ ] Verify all monetary values are numbers rounded to 2 decimals
- [ ] Verify every legal transition in the matrix via PATCH endpoint (5×5 grid minus diagonal repeats)
- [ ] Verify each illegal transition returns 422 and does NOT mutate the order
- [ ] Verify cancellation restores inventory exactly once (`inventory_restored_at`)
- [ ] Verify queued listeners land on `meem-medium` and workers consume them
