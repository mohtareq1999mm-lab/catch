# Jira - Order Feature (Frontend)

## Epic: Frontend Order UI

### Story Points Estimate: 13

---

## User Stories

### FE-US-001: My Orders Page (Customer)
**As** a customer
**I want** to view my order history in a clean list
**So that** I can track my purchases and check status

**Acceptance Criteria:**
- Fetches `GET /api/v1/general/orders?status={filter}&page={n}&limit={n}` on mount
- Status filter values (only these five): `pending`, `processing`, `completed`, `delivered`, `cancelled`
- Displays: order number, date, status badge, total, payment method, invoice indicator
- Status badges: pending=yellow, processing=blue, completed=green, delivered=teal, cancelled=red
- Click row → order detail (FE-US-002)
- Pagination with `page`/`limit`; loading skeleton; empty state; error state with retry

---

### FE-US-002: Order Detail Page (Customer)
**As** a customer
**I want** full details of a single order

**Acceptance Criteria:**
- Fetches `GET /api/v1/general/orders/{id}`
- 404 handling = "not accessible" → navigate back to list (also covers other users' orders)
- Shows order number, date, status, items with snapshots
- Price breakdown: subtotal, shipping, discounts, total
- Payment + delivery info; invoice link built from `invoice_id` uuid (`GET /api/v1/invoices/{uuid}/download`)
- Status timeline driven by the machine below

```text
Order status timeline (display order):
pending → processing → completed → delivered
(any state may jump to cancelled)
```

---

### FE-US-003: Checkout Page (Customer)
Multi-step checkout submitting `POST /api/v1/general/checkout`.
After success: COD/cashier orders stay **pending** until staff marks payment or user completes gateway flow — do not show "completed" from the checkout response alone.

---

### FE-US-004: Admin Status Change Control
**As** an admin
**I want** a guarded status action on the admin order page

**Acceptance Criteria:**
- Offer only legal next statuses:

```text
pending    → processing | completed | cancelled
processing → completed  | cancelled
completed  → delivered
delivered  → (terminal — hide control)
cancelled  → (terminal — hide control)
```

- Submit `PATCH /api/v1/orders/{id}/status` (permission `update-order-status`)
- 422 → show backend transition message; 200 → refetch order
- Show an "async" hint: notifications/activity are processed after the response on the backend queue

---

## Frontend Jest Tests

### FE-TS-001: MyOrdersPage - GET /api/v1/general/orders

| # | Test | Mock Setup | Assertion |
|---|------|-----------|-----------|
| 1 | `displays only authenticated user's orders` | 3 orders for user A | Only user A's orders rendered |
| 2 | `redirects to login if 401` | 401 | Login prompt/navigation |
| 3 | `renders correct order data` | `{ data:[{order_number,status,total,...}] }` | All fields in table |
| 4 | `handles empty order history` | `{ data: [], links:{total:0} }` | Empty state message |
| 5 | `paginates through results` | 2 pages | Page nav works |
| 6 | `filters by status tab` | filtered mock for "completed" | Only completed shown |
| 7 | `sends only valid status values` | intercept params | No `order-*` / `refunded` values ever sent |

### FE-TS-002: AdminStatusControl - PATCH /api/v1/orders/{id}/status

| # | Test | Assertion |
|---|------|-----------|
| 1 | `offers only legal next statuses per matrix` | Dropdown contents match guard |
| 2 | `hides control for terminal states` | delivered/cancelled → no action |
| 3 | `submits PATCH with db-status value` | Body exactly `{ status }` |
| 4 | `surfaces 422 transition message` | Backend message displayed |
| 5 | `refetches order after 200` | GET detail called again |

---

## Frontend Tasks

| Task ID | Description | Estimate (h) | Component |
|---------|-------------|-------------|-----------|
| FE-T-001 | Create MyOrdersPage | 6 | `MyOrdersPage.vue` |
| FE-T-002 | Create OrderDetailPage | 5 | `OrderDetailPage.vue` |
| FE-T-003 | Create OrderStatusBadge | 2 | `OrderStatusBadge.vue` |
| FE-T-004 | Create CheckoutPage (multi-step) | 10 | `CheckoutPage.vue` |
| FE-T-005 | Create AdminOrderListPage | 8 | `AdminOrderListPage.vue` |
| FE-T-006 | Create AdminOrderDetailPage | 6 | `AdminOrderDetailPage.vue` |
| FE-T-007 | Create OrderStatusTimeline | 3 | `OrderStatusTimeline.vue` |
| FE-T-008 | Create PriceBreakdown component | 2 | `PriceBreakdown.vue` |
| FE-T-009 | Create API service layer (orderApi) | 3 | `services/orderApi.js` |
| FE-T-010 | Create guarded AdminStatusControl | 3 | `AdminStatusControl.vue` |

## Backend Bugs Affecting Frontend

| Bug | Impact on frontend | Status |
|-----|--------------------|--------|
| Generic status changes produce no SMS/email (orphaned Marvel listeners) | Don't build UX that promises email/SMS on every transition; cancellation does notify asynchronously | Open (BUG-002 backend) |
| Events fire pre-commit without afterCommit | Rare stale payloads in notification content possible | Open (BUG-001 backend) |
| Same-status PATCH re-fires events | Duplicate activity entries; harmless to UI | Open (BUG-003 backend) |

## API Routes for Frontend Integration

| Method | Endpoint | Auth | Usage |
|--------|----------|------|-------|
| GET | `/api/v1/general/orders?status=&limit=&page=` | Sanctum | My orders |
| GET | `/api/v1/general/orders/{id}` | Sanctum | My order details (owner only) |
| POST | `/api/v1/general/checkout` | Sanctum | Place order |
| POST | `/api/v1/general/checkout/cod/{orderId}/mark-paid` | Sanctum + permission | Staff COD completion |
| POST | `/api/v1/general/checkout/cashier/{orderId}/mark-paid` | Sanctum + permission | Staff cashier completion |
| PATCH | `/api/v1/orders/{id}/status` | Sanctum + `update-order-status` | Admin transitions |
