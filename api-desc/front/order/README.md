# Order Feature - API Investigation

## Feature Name

Order Management — full lifecycle: checkout → payment → status transitions → fulfillment/delivery or cancellation.

## Description

Complete order lifecycle management spanning a customer API (view orders, checkout, view invoices) and an admin API (list, detail, **status transitions**, payment marking). Status changes are centralized in `App\Services\General\OrderService` with enforced transition matrices, lifecycle events, and queued listeners on `meem-medium`.

## Architecture Overview

```
[Customer App]
    |--- GET  /api/v1/general/orders                       (Auth: My Orders)
    |--- GET  /api/v1/general/orders/{id}                  (Auth: owner-only detail)
    |--- GET  /api/v1/general/orders/{orderId}/invoice     (Auth: canonical invoice by Order ID)
    |--- POST /api/v1/general/checkout                     (Auth: Create Order, pending)
    |--- ANY  /api/v1/general/checkout/callback            (Public: gateway success)
    |--- ANY  /api/v1/general/checkout/error-callback      (Public: gateway failure)
    |
[Admin Dashboard]
    |--- GET   /api/v1/orders                              (view-orders)
    |--- GET   /api/v1/orders/{id}                         (view-order)
    |--- PATCH /api/v1/orders/{id}/status                   (update-order-status)
    |--- POST  /api/v1/general/checkout/cod/{id}/mark-paid      (update-order-status)
    |--- POST  /api/v1/general/checkout/cashier/{id}/mark-paid  (update-order-status)
    |
    v
[OrderController (General)] / [Marvel OrderController (admin)]
    |
    v
[App\Services\General\OrderService]         ← single source of truth for status
    |-- $allowedOrderTransitions / $allowedFulfillmentTransitions
    |-- changeOrderStatus(): transaction + validation + events
    |-- markCodAsPaid() / markCashierPaid()
    |
    v
[Events: OrderCreated, OrderStatusChanged, OrderCancelled, PaymentSucceeded, PaymentFailed]
    |
    v
[Queued listeners meem-medium: activity logs, customer notifications, RestoreProductInventory]
    |
    v
[orders, order_products, transactions tables + activity_log]
```

## Key Endpoints

### Customer API (General)

| Method | URI | Auth |
|--------|-----|------|
| GET | `/v1/general/orders` | `auth:sanctum` |
| GET | `/v1/general/orders/{id}` | `auth:sanctum` (owner only) |
| GET | `/v1/general/orders/{orderId}/invoice` | `auth:sanctum` (owner-scoped; 404 while pending) — canonical |
| GET | `/v1/general/orders/{orderId}/invoice` | `auth:sanctum` (owner-scoped; 404 while pending) — canonical (uuid route removed 2026-08-22) |
| POST | `/v1/general/checkout` | `auth:sanctum` |
| POST | `/v1/general/checkout/cod/{orderId}/mark-paid` | `auth:sanctum` + `update-order-status` |
| POST | `/v1/general/checkout/cashier/{orderId}/mark-paid` | `auth:sanctum` + `update-order-status` |
| ANY | `/v1/general/checkout/callback` | Public (gateway) |
| ANY | `/v1/general/checkout/error-callback` | Public (gateway) |
| GET | `/v1/general/checkout/promotions` | `auth:sanctum` |

### Admin API

| Method | URI | Permission |
|--------|-----|-----------|
| GET | `/v1/orders` | `view-orders` |
| GET | `/v1/orders/{id}` | `view-order` |
| PATCH | `/v1/orders/{id}/status` | `update-order-status` |

> No `PUT /v1/orders/{id}` and no `/v1/general/checkout/transaction-qr/*` route exists.

## Order Status Machine (verified)

```text
pending ──→ processing ──→ completed ──→ delivered (terminal)
   │             │              │
   └─────────────┴──────────────┴──→ cancelled (terminal)

Same-status re-set allowed. All other transitions rejected with 422.
```

Payment-driven completions reuse the same service (`mark-paid`, gateway callbacks).

## Key Files

| Layer | Path |
|-------|------|
| Controller (General/customer) | `app/Http/Controllers/Api/General/OrderController.php` |
| Controller (Admin) | `packages/marvel/src/Http/Controllers/Order/OrderController.php` |
| Model (Order) | `packages/marvel/src/Database/Models/Order.php` |
| Model (OrderProduct) | `packages/marvel/src/Database/Models/OrderProduct.php` |
| Model (Transaction) | `packages/marvel/src/Database/Models/Transaction.php` |
| Service (status authority) | `app/Services/General/OrderService.php` |
| Service (Creation) | `app/Services/Checkout/OrderCreationService.php` |
| FormRequest (status) | `packages/marvel/src/Http/Requests/OrderStatusUpdateRequest.php` |
| Routes (admin) | `packages/marvel/src/Rest/Routes.php:165-167` (under `api/v1`) |
| Routes (customer) | `routes/api.php` (under `v1/general`) |
| Event wiring | `app/Providers/EventServiceProvider.php` |
| Tests | `tests/Feature/OrdersProductionHardenTest.php` (38 tests, passing) |
| Tests | `tests/Feature/OrderCreationFlowTest.php` (17 tests, passing) |

## Tech Stack

- **Laravel** with Eloquent ORM
- **Event-Driven Architecture** — verified registrations in `app/Providers/EventServiceProvider.php`; listeners queued
- **Queues:** `meem-medium` for all active order listeners (`meem-high` referenced only by unreachable legacy Marvel listeners)
- **Payment Gateway Integration** — MyFatoorah via factory + callbacks; COD & cashier QR flows
- **Soft Deletes** on orders
- **Transaction-based checkout** — inventory reservation, row locks, idempotency flags
