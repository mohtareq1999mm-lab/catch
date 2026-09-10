# Order Feature - API Investigation

## Feature Name

Order Lifecycle — list, detail, invoice view, checkout, payment completion, and **status transitions** for two consumers (customer frontend app + admin dashboard).

## Description

Two separate endpoint groups share the name "orders" but have **different controllers, permissions, and response shapes**:

- **Customer** (`/api/v1/general/orders`, `App\Http\Controllers\Api\General\OrderController`):
  - `GET /api/v1/general/orders` — authenticated user's own orders (paginated, `App\Http\Resources\Order\OrderCollection` / `OrderResource`)
  - `GET /api/v1/general/orders/{id}` — owner-only detail (`OrderResource`), 404 for other users' orders
  - `GET /api/v1/general/orders/{orderId}/invoice` — canonical owner-scoped invoice view by Order ID (`CustomerInvoiceResource`); legacy uuid route removed 2026-08-22
  - `POST /api/v1/general/checkout` + `checkout/cod|cashier/{id}/mark-paid` + public gateway callbacks
- **Admin** (`/api/v1/orders`, `Marvel\Http\Controllers\Order\OrderController`):
  - `GET /api/v1/orders` — paginated list, permission `view-orders`
  - `GET /api/v1/orders/{id}` — detail by ID or tracking number, permission `view-order`
  - `PATCH /api/v1/orders/{id}/status` — status transition, permission `update-order-status`

Both wrap responses in the standard envelope `{ status, message, success, data }` via `Marvel\Traits\ApiResponse`.

## Architecture

```
[Customer App]
    |
    |--- GET  /api/v1/general/orders                    (auth:sanctum + throttle:authenticated)
    |--- GET  /api/v1/general/orders/{id}               (owner-only, 404 otherwise)
    |--- GET  /api/v1/general/orders/{orderId}/invoice   (canonical, owner-scoped)
    |--- POST /api/v1/general/checkout
    |--- POST /api/v1/general/checkout/cod/{id}/mark-paid      (update-order-status)
    |--- POST /api/v1/general/checkout/cashier/{id}/mark-paid  (update-order-status)
    |--- ANY  /api/v1/general/checkout/callback                (public, gateway)
    |
    v
[App\Http\Controllers\Api\General\OrderController]
    |--- index()   -> OrderService::paginateForUser() -> forUser(userId) + status filter
    |--- show()    -> OrderService::getOrderForUser() -> owner-scoped query
    |--- invoice() -> Invoice::whereUuid()->firstOrFail(); owner check; CustomerInvoiceResource
    |--- checkout()/markCodAsPaid()/markCashierPaid()/callbacks -> OrderService + PaymentCheckoutHandler
    |
    v
[App\Http\Resources\Order\*]                     [Admin Dashboard]
                                                    |--- GET   /api/v1/orders          (view-orders)
                                                    |--- GET   /api/v1/orders/{id}     (view-order)
                                                    |--- PATCH /api/v1/orders/{id}/status (update-order-status)
                                                    |
                                                    v
                                                  [Marvel\Http\Controllers\Order\OrderController]
                                                    |--- index()/show() -> direct Eloquent + relations
                                                    |--- updateStatus() -> OrderStatusUpdateRequest
                                                    |                      -> OrderService::changeOrderStatus()
                                                    |
                                                    v
                                                  [Marvel\Http\Resources\Order\*]

[Shared core: App\Services\General\OrderService]
    |--- $allowedOrderTransitions / $allowedFulfillmentTransitions   // single source of truth
    |--- changeOrderStatus(): DB::transaction + validation + events
    |--- markCodAsPaid() / markCashierPaid()
    |
    v
[Events: OrderCreated, OrderStatusChanged, OrderCancelled, PaymentSucceeded, PaymentFailed]
    |
    v
[Queued listeners on meem-medium: activity log, customer notifications, RestoreProductInventory]
```

## Key Endpoints

### Customer

| Method | URI | Controller Method | Auth |
|--------|-----|-------------------|------|
| GET | `/api/v1/general/orders` | `index` | Sanctum |
| GET | `/api/v1/general/orders/{id}` | `show` | Sanctum + owner |
| GET | `/api/v1/general/orders/invoice/{uuid}` | `invoice` | Sanctum + owner |
| POST | `/api/v1/general/checkout` | `checkout` | Sanctum |
| POST | `/api/v1/general/checkout/cod/{orderId}/mark-paid` | `markCodAsPaid` | Sanctum + `update-order-status` |
| POST | `/api/v1/general/checkout/cashier/{orderId}/mark-paid` | `markCashierPaid` | Sanctum + `update-order-status` |
| ANY | `/api/v1/general/checkout/callback` | `checkoutCallback` | Public (gateway) |
| ANY | `/api/v1/general/checkout/error-callback` | `checkoutErrorCallback` | Public (gateway) |

### Admin

| Method | URI | Controller Method | Permission |
|--------|-----|-------------------|------------|
| GET | `/api/v1/orders` | `index` | `view-orders` |
| GET | `/api/v1/orders/{id}` | `show` | `view-order` |
| PATCH | `/api/v1/orders/{id}/status` | `updateStatus` | `update-order-status` |

## Status Machine (verified from source)

```text
pending ──→ processing ──→ completed ──→ delivered (terminal)
   │             │              │
   └─────────────┴──────────────┴──→ cancelled (terminal)

Same-status re-sets are allowed. Any other transition → 422.
Matrix lives in app/Services/General/OrderService.php ($allowedOrderTransitions).
```

## Key Files

| Layer | Path |
|-------|------|
| Controller (customer) | `app/Http/Controllers/Api/General/OrderController.php` |
| Controller (admin) | `packages/marvel/src/Http/Controllers/Order/OrderController.php` |
| Resource (customer) | `app/Http/Resources/Order/*` |
| Resource (admin) | `packages/marvel/src/Http/Resources/Order/*` |
| Service (status authority) | `app/Services/General/OrderService.php` (`changeOrderStatus`, transition matrices) |
| Service (creation) | `app/Services/Checkout/OrderCreationService.php` |
| FormRequest (status) | `packages/marvel/src/Http/Requests/OrderStatusUpdateRequest.php` |
| Model | `packages/marvel/src/Database/Models/Order.php` |
| Enum (Permission) | `packages/marvel/src/Enums/Permission.php` |
| Routes (admin) | `packages/marvel/src/Rest/Routes.php` (lines 165–167, loaded under `api/v1`) |
| Routes (customer) | `routes/api.php` (under `v1/general`) |
| Event registrations | `app/Providers/EventServiceProvider.php` |

## Tech Stack

- **Laravel** with Eloquent ORM
- **Sanctum** authentication
- **Spatie permissions** (`view-orders`, `view-order`, `update-order-status`) — admin only
- **API Resources** (`OrderCollection`, `OrderResource` in both app and Marvel namespaces)
- **Pagination** with `?limit=` (default 15, max 100)
- **Event-driven lifecycle** with queued listeners on the `meem-medium` queue (`meem-high` is referenced by legacy Marvel listeners that are currently not dispatched by app flows — see bug-report)
