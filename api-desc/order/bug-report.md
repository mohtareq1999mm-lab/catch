# Bug Report - Order Feature

All issues below were verified directly against the source code during the status-lifecycle audit.

## Issue 1 (LOW): No Explicit Order By on List — RESOLVED BY GLOBAL SCOPE

- **File:** `packages/marvel/src/Database/Models/Order.php:110-112`
- **Description:** The admin/customer list controllers apply no `orderBy`, but the model registers a global scope `orderBy('created_at', 'desc')`. Ordering is therefore deterministic.
- **Impact:** None. Documentation previously claimed undefined ordering; corrected.

## Issue 2 (MEDIUM): Promotion Name Subquery Performance

- **File:** `packages/marvel/src/Http/Controllers/Order/OrderController.php:37-41`
- **Description:** `promotion_name` filter uses `whereIn('promotion_code', Promotion::where('name','like',...)->select('code'))` — a correlated subquery on a LIKE match with no index on `promotions.name`.
- **Impact:** Slow query on large promotion tables.

## Issue 3 (HIGH, docs-facing): CustomerInvoiceResource.download_url points to non-existent route

- **File:** `app/Http/Resources/Invoice/CustomerInvoiceResource.php`
- **Description:** `download_url` is generated as `/api/v1/general/invoices/{uuid}/download`, but that route is not registered. The real download endpoint is `GET /api/v1/invoices/{uuid}/download` (registered in `packages/marvel/src/Rest/Routes.php:393`).
- **Impact:** Any frontend that follows `download_url` will hit a 404. Frontend should construct the download URL from the invoice uuid instead.

## Issue 4 (INFO): Customer order list does not include invoice_summary

- **File:** `app/Services/General/OrderService.php::orderListRelations()`
- **Description:** `paginateForUser()` eager loads `latestInvoice` but not `invoices`, so the `invoice_summary` block in `App\Http\Resources\Order\OrderResource` is never emitted by the list endpoint. Only `order_has_invoice` / `invoice_id` are exposed.
- **Impact:** Frontend cannot show invoice number/total inline from the list; it must open the invoice view endpoint.

## Issue 5 (MEDIUM): Order events dispatched inside the DB transaction (no afterCommit) — PARTIALLY MITIGATED

- **File:** `app/Services/General/OrderService.php`
- **Status:** Mitigated for the high-risk side effects: `RestoreProductInventory`, `GenerateInvoiceListener` already declare `public $afterCommit = true`. Plain activity-log/notification listeners still dispatch pre-commit (low risk: they re-fetch by ID). Full `DB::afterCommit()` alignment deferred.

## Issue 6 (HIGH, docs-facing): Parallel/orphaned Marvel order event listeners — PARTIALLY RESOLVED

- **Status:** Delivery notifications now work via the new app-level `App\Events\OrderDelivered` → `SendUserOrderDeliveredNotification` (dispatched by `changeOrderStatus` on first-time delivery). The generic status-changed SMS/email chain and Marvel payment chains remain orphaned (unchanged, documented below).

## Issue 7 (LOW): Same-status transitions are permitted and re-fire events — UNCHANGED

- **File:** `app/Services/General/OrderService.php:494-500, 552`
- **Confidence:** VERIFIED
- **Description:** Every status lists itself as an allowed target (`pending→pending`, etc.), so repeating the same PATCH succeeds and dispatches another `OrderStatusChanged` (+ duplicate activity-log entry). Payment side effects are guarded by idempotency flags, so no financial duplication occurs.
- **Impact:** Cosmetic/log noise only.

## Legacy notes (pre-audit)

- ~~No explicit orderBy~~ — resolved, see Issue 1.
- Status filter ignored on `/api/v1/general/orders` — fixed 2026-07-23 (see front/order docs).
