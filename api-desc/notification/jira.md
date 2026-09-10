# Jira - Notification Feature (Phase 1 / 2 / 3)

## Epic: End-User Notification Program

### Story Points Estimate: 8

## User Stories

### US-001: Receive Order/Payment Notifications
**As** a user
**I want** to be notified about my order lifecycle (created, paid, delivered, cancelled, refunded) and payment status
**So that** I can track my purchases in real time

### US-002: Receive Coupon Notifications
**As** a user
**I want** to be notified when a coupon is assigned/available to me or used
**So that** I know about discounts I can use

### US-003: Receive Promotion / Flash Sale Alerts
**As** a user
**I want** to be notified when a promotion or flash sale becomes available
**So that** I can act on limited-time offers

### US-004: Receive Price / Stock / Review Alerts (Wishlist)
**As** a user
**I want** to be notified when a wishlisted product drops in price, goes on discount, returns to stock, or my review is approved/rejected
**So that** I can make timely decisions

### US-005: Receive Ending-Soon & Abandoned-Cart Reminders
**As** a user
**I want** reminders before a promotion/flash sale ends and for an abandoned cart
**So that** I don't miss opportunities

### US-006: Stable Notification Type Contract
**As** a frontend developer
**I want** notifications to expose a stable `type` business identifier (e.g. `price.drop`)
**So that** the client is decoupled from PHP class names

## Bug Tickets

| Ticket | Description | Priority | Severity | Status |
|--------|-------------|----------|----------|--------|
| BUG-001 | `notifications.type` stored the PHP FQCN instead of a stable business id | High | Medium | FIXED |
| BUG-002 | No real-time delivery for end users (REST only) | High | High | FIXED |
| BUG-003 | Wishlist fan-out could notify admins / wrong recipients | Medium | High | FIXED |

## Technical Debt

| TD-001 | No dedicated analytics on notification open-rate |
| TD-002 | Realtime payload repeats `{en,ar}`; could be resolved server-side like REST |
