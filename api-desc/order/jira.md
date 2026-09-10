# Jira - Order Feature

## Epic: Order Management — Customer + Admin (read + status transitions)

### Story Points Estimate: 13

## User Stories

### US-001 (Admin): View Order List
**As** an admin
**I want** to view a paginated list of all orders with filters
**So that** I can manage and review orders effectively

**Acceptance Criteria:**
- Paginated list with configurable limit (15–100)
- Filter by status, user, email, date range
- Search by name/email/phone
- Filter by product, promotion, shipping method
- List returns minimal items (no items/transactions)

### US-002 (Admin): View Order Detail
**As** an admin
**I want** to view full order details including items, transactions, and pricing
**So that** I can process and verify orders

**Acceptance Criteria:**
- Load order by ID or tracking number
- Display customer info, items, pricing breakdown
- Display transactions and payment status
- Show pick-up location if fulfillment is pickup

### US-003 (Admin): Change Order Status
**As** an admin
**I want** to move an order through its lifecycle (`pending → processing → completed → delivered`, or cancel)
**So that** customers see accurate progress

**Acceptance Criteria:**
- `PATCH /api/v1/orders/{id}/status` with body `{ "status": "<db-status>" }`
- Requires `update-order-status` permission
- Valid values: `pending`, `processing`, `completed`, `delivered`, `cancelled` (NOT `order-*` enum strings)
- Forbidden transitions return 422; terminal states cannot change
- Completion syncs payment status/timestamps/transaction; cancellation stamps `cancelled_at`, fails transaction, decrements promotion, restores inventory
- Success returns updated `Marvel\OrderResource`

### US-004 (Customer): My Orders List & Detail
**As** a customer
**I want** to view my own orders and their details
**So that** I can track my purchases

**Acceptance Criteria:**
- Scoped to authenticated user only (404 for others' orders)
- Show order number, status, totals, invoice indicator
- Filter by status

### US-005 (Customer): View Order Invoice
**As** a customer
**I want** to view the invoice of my order
**So that** I can review invoice details and verify it

**Acceptance Criteria:**
- Owner-only access (403 otherwise)
- Returns invoice JSON + snapshot + verification URL

### US-006: Order Event Notifications
**As** a customer
**I want** to receive notifications when my order status changes
**So that** I stay informed

**Current reality (verified):**
- Generic transitions fire `App\Events\OrderStatusChanged` → activity log only (queued `meem-medium`)
- Cancellation additionally fires user notification + inventory restore
- The Marvel SMS/email listener set for generic changes is orphaned (see BUG-006)

## Bug Tickets

| Ticket | Description | Priority | Severity | Status |
|--------|-------------|----------|----------|--------|
| BUG-001 | Promotion-name filter uses subquery without index (`promotions.name`) | Medium | Medium | Open |
| BUG-002 | `CustomerInvoiceResource.download_url` points to non-existent route | High | Medium | Open (docs workaround documented) |
| BUG-003 | Customer order list never emits `invoice_summary` (only indicator fields) | Info | Low | Open |
| BUG-005 | Events dispatched inside `changeOrderStatus()` transaction without `afterCommit` — queued listeners may read pre-commit state under race | Medium | Medium | Open |
| BUG-006 | Marvel SMS/email listeners for `OrderStatusChanged`/`PaymentSuccess`/`PaymentFailed` are unreachable (no dispatch site for those event classes; no registrations) | High | Medium | Open |
| BUG-007 | Same-status PATCH re-fires events producing duplicate activity-log rows | Low | Low | Open |
| ~~BUG-004~~ | ~~No explicit orderBy~~ — model global scope orders by `created_at DESC`; docs corrected | — | — | Closed (not a bug) |
