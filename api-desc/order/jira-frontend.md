# Jira - Order Feature (Frontend)

## Epic: Order Management UI — Customer App + Admin Dashboard

### Story Points Estimate: 10

---

## User Stories

### FE-US-001 (Admin): Orders Data Table
**As** an admin
**I want** a filterable, paginated orders table
**So that** I can browse and search orders

**Acceptance Criteria:**
- Fetches `GET /api/v1/orders` with query params
- Columns: Order #, Customer, Status, Date
- Filters: status dropdown (`pending|processing|completed|delivered|cancelled`), date range, text search
- Pagination controls
- Loading skeleton + error state

### FE-US-002 (Admin): Order Detail Page
**As** an admin
**I want** to see full order information
**So that** I can process and verify

**Acceptance Criteria:**
- Fetches `GET /api/v1/orders/{id}`
- Sections: customer info, items table, pricing breakdown, transactions
- Pickup location details if applicable
- Back to list navigation

### FE-US-003 (Admin): Status Change Control
**As** an admin
**I want** a status action on the detail page
**So that** I can progress orders

**Acceptance Criteria:**
- Dropdown shows ONLY legal next statuses per the matrix below
- Submits `PATCH /api/v1/orders/{id}/status`
- On 422 show the backend message (transition violation) and refresh current state
- After 200, refetch order; note that notifications/activity happen asynchronously

```text
Legal next statuses (frontend guard):
pending    → processing | completed | cancelled
processing → completed  | cancelled
completed  → delivered
delivered  → (terminal)
cancelled  → (terminal)
```

### FE-US-004 (Customer): My Orders List & Invoice View
**As** a customer
**I want** to see my orders and open an invoice
**So that** I can track my purchases

**Acceptance Criteria:**
- Fetches `GET /api/v1/general/orders` (filter by status tabs using DB status values)
- Shows order number, status, totals, invoice indicator (from `order_has_invoice`)
- **Invoice page fetches `GET /api/v1/general/orders/{orderId}/invoice`** (canonical) — handle 404 (pending order or foreign order)
- Download button must build its URL from the returned invoice payload's uuid (`GET /api/v1/invoices/{uuid}/download`) — do NOT follow `download_url`

---

## Frontend Tasks

| ID | Description | h | Component |
|----|-------------|---|-----------|
| FE-T-001 | Create OrdersTable with filters (admin) | 6 | `OrdersTable.vue` |
| FE-T-002 | Create OrderDetailPage (admin) | 5 | `OrderDetailPage.vue` |
| FE-T-003 | Create API service | 1 | `services/orderApi.js` |
| FE-T-004 | Create filter components | 3 | `OrderFilters.vue` |
| FE-T-005 | Create customer My Orders page | 4 | `MyOrders.vue` |
| FE-T-006 | Create customer invoice view page | 4 | `OrderInvoiceView.vue` |
| FE-T-007 | Create StatusChangeControl with transition guard + 422 handling | 3 | `OrderStatusControl.vue` |

## API Routes

### Customer

| Method | Endpoint | Auth | Usage |
|--------|----------|------|-------|
| GET | `/api/v1/general/orders` | Sanctum | My orders |
| GET | `/api/v1/general/orders/{id}` | Sanctum + owner | Detail |
| GET | `/api/v1/general/orders/{orderId}/invoice` | Sanctum (owner-scoped) | Invoice view — canonical |
| GET | `/api/v1/general/orders/{orderId}/invoice` | Sanctum (owner-scoped) | Invoice view — canonical (uuid route removed 2026-08-22) |

### Admin

| Method | Endpoint | Permission | Usage |
|--------|----------|------------|-------|
| GET | `/api/v1/orders` | view-orders | Data table |
| GET | `/api/v1/orders/{id}` | view-order | Detail page |
| PATCH | `/api/v1/orders/{id}/status` | update-order-status | Status change |

> There is **no** `PUT /api/v1/orders/{id}` endpoint. Status changes go exclusively through `PATCH .../status`.
