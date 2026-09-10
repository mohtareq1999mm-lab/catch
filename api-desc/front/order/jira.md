# Jira - Order Feature

## Epic: Order Management

### Story Points Estimate: 21

## User Stories

### US-001: View My Orders (Customer)
**As** a customer
**I want** to view my order history
**So that** I can track my purchases

**Acceptance Criteria:**
- `GET /api/v1/general/orders` returns authenticated user's orders
- Paginated; status filter accepts `pending|processing|completed|delivered|cancelled`
- Shows order number, status, total, date, invoice indicator

### US-002: View My Order Details (Customer)
**As** a customer
**I want** full details of one of my orders

**Acceptance Criteria:**
- `GET /api/v1/general/orders/{id}` returns own order only
- Ownership enforced at query level — another user's order ID returns `404`
- No `user_id` accepted from request

### US-003: Checkout (Customer)
**As** a customer
**I want** to place an order from my cart

**Acceptance Criteria:**
- `POST /api/v1/general/checkout`
- COD, online, pay-at-cashier supported; COD+pickup rejected (422)
- Order created as `pending` with price snapshots; completion happens via payment paths

### US-004: Change Order Status (Admin)
**As** an admin
**I want** to progress orders through their lifecycle

**Acceptance Criteria:**
- `PATCH /api/v1/orders/{id}/status` body `{ "status": "<db-status>" }`
- Permission `update-order-status`; valid values: `pending|processing|completed|delivered|cancelled`
- Forbidden transitions → 422 (`checkout.invalid_order_status_transition`); terminal states locked
- Completion syncs payment data + consumes coupon/promotion usage; cancellation stamps time, fails transaction, decrements promotion, restores inventory once
- COD/cashier marking via `POST /general/checkout/cod|cashier/{orderId}/mark-paid`
- Notifications/activity run asynchronously on `meem-medium` after the 200

### US-005: Order Event Notifications
**As** a customer
**I want** notifications on order updates

**Current reality (verified):**
- Cancellation → customer notification (queued)
- Generic transitions → activity log only; SMS/email chain currently unreachable (BUG-002)

## Bug Tickets

| Ticket | Description | Priority | Severity | Status |
|--------|-------------|----------|----------|--------|
| BUG-000 | Status filter ignored on list endpoint | High | High | **FIXED** 2026-07-23 |
| BUG-001 | Events fired inside status transaction without afterCommit | Medium | Medium | Open |
| BUG-002 | Marvel SMS/email listeners for generic transitions unreachable | High | Medium | Open |
| BUG-003 | Same-status PATCH duplicates activity-log entries | Low | Low | Open |
| BUG-004 | Legacy `Marvel\Enums\OrderStatus` strings (`order-*`) invalid as API values | Low | Low | Open (docs pinned) |
| BUG-005 | Docs drift: PUT route / transaction-qr route / old trait references | Medium | Medium | **Fixed** (docs v3) |
