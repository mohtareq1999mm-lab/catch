# Frontend - Order Feature

## Status

**No dedicated frontend Vue/React components** found in `resources/js/`. The frontend is a separate SPA.

## Consumption Patterns

### 1. My Orders Page (Customer)

```
GET /api/v1/general/orders?status=pending&limit=15&page=1

Supports `status` filter with ONLY these values:
pending | processing | completed | delivered | cancelled
plus `limit` and `page` pagination.

Response:
{
  "status": 200,
  "message": "Data fetched successfully",
  "success": true,
  "data": {
    "data": [
      {
        "id": 1,
        "order_number": "ORD-00000001",
        "status": "pending",
        "subtotal": 100.0,
        "total": 120.00,
        "currency": "EGP",
        "payment_method": "cod",
        "fulfillment_type": "delivery",
        "created_at": "2026-07-20T10:00:00+00:00",
        "order_has_invoice": false,
        "invoice_id": null
      }
    ],
    "links": { "current_page": 1, "per_page": 15, "total": 75, "last_page": 5 }
  }
}
```

    ### 2. My Order Details Page (Customer)

    ```
    GET /api/v1/general/orders/{id}

    Ownership is enforced server-side: the order must belong to the authenticated user,
    otherwise 404 is returned (a user can never fetch another user's order by ID).

    Response envelope contains the full App\OrderResource:
    status fields of interest: status (5 DB values), payment_method, fulfillment_type,
    order_items[] with price snapshots, order_has_invoice / invoice_id.
    ```

    Invoice download: build the URL from `invoice_id` → `GET /api/v1/invoices/{uuid}/download`.
    Do **not** follow `download_url` inside CustomerInvoiceResource (points at an unregistered route).

### 3. Admin Order Management

```
GET   /api/v1/orders                 ?status=&search=&limit=&page=...   (view-orders)
GET   /api/v1/orders/{id}            id or tracking number              (view-order)
PATCH /api/v1/orders/{id}/status     { "status": "<db-status>" }        (update-order-status)
```

There is **no** `PUT /api/v1/orders/{id}`. Status changes go exclusively through PATCH.

Admin status control should offer only legal next statuses:

```text
pending    → processing | completed | cancelled
processing → completed  | cancelled
completed  → delivered
delivered  → (terminal)
cancelled  → (terminal)
```

On 422 display the backend message (`checkout.invalid_order_status_transition` includes from/to).
On 200 refetch the order; notifications/activity logs are processed asynchronously on queue `meem-medium`.

## What a Frontend Implementation Would Need

```
MyOrdersPage.vue
  Fetches: GET /api/v1/general/orders
  Renders: Order table with status badges
  Features: Filter by status tabs (DB status values), pagination
  Actions: Click row → order detail

MyOrderDetailPage.vue
  Fetches: GET /api/v1/general/orders/{id}
  Renders: Full order summary, items, payment & shipping info
  Features: Invoice link via invoice_id (uuid)

AdminOrderListPage.vue
  Fetches: GET /api/v1/orders (admin auth)
  Features: Advanced filters, export

AdminOrderDetailPage.vue
  Fetches: GET /api/v1/orders/{id}
  Features: Order items, transactions, StatusChangeControl (PATCH), invoice download
```

### API Service Layer

```javascript
export const orderApi = {
  myOrders(params)            // GET  /api/v1/general/orders
  myOrder(id)                 // GET  /api/v1/general/orders/{id}
  checkout(data)              // POST /api/v1/general/checkout
  markCodPaid(orderId)        // POST /api/v1/general/checkout/cod/{orderId}/mark-paid
  markCashierPaid(orderId)    // POST /api/v1/general/checkout/cashier/{orderId}/mark-paid
  list(params)                // GET  /api/v1/orders          (admin)
  show(id)                    // GET  /api/v1/orders/{id}     (admin)
  updateStatus(id, status)    // PATCH /api/v1/orders/{id}/status  { status }  (admin)
}
```

> Removed from earlier drafts: `update(id, data)` via PUT (no such route) and `getTransactionQr` (no such route/method).
