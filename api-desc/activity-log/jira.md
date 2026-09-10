# Jira - Activity Log Feature

## Epic: Audit Trail

### Story Points Estimate: 8

---

## User Stories

### US-001: View Activity Logs (Admin)
**As** an admin user
**I want** to view paginated activity logs with filters
**So that** I can audit changes made across the system

**Acceptance Criteria:**
- `GET /api/v1/logs/activity` with `view-activity-log` permission
- Filters: log_name, event, causer_id, search
- Paginated with standard meta
- Returns 401 for unauthenticated, 403 without permission

### US-002: Automatic Entity Logging
**As** a system
**I want** all CRUD operations on entities to be logged automatically
**So that** there is a complete audit trail

**Acceptance Criteria:**
- Logs on: User, Product, Category, Brand, Coupon, FlashSale, Promotion, Role, PickupLocation
- Events: created, updated, deleted, restored, force deleted, status changed
- Queued to medium queue for async processing

### US-003: Order Event Logging
**As** a system
**I want** order lifecycle events to be logged
**So that** order changes are auditable

**Acceptance Criteria:**
- Events: order_created, order_cancelled, order_status_changed, payment_succeeded, payment_failed
- Causer is the admin/customer who triggered the change

---

## Bug Tickets

| Ticket | Description | Priority | Severity |
|--------|-------------|----------|----------|
| BUG-001 | Duplicate route registration (lines 174 and 804) | Low | Low |
| BUG-002 | Silent failure when subject deleted before job runs | Medium | Medium |
| BUG-003 | Soft-deleted subjects not found by job (missing withTrashed) | Medium | Medium |
| BUG-004 | No date_from / date_to filters | Low | Medium |
| BUG-005 | No sort_by / sort_order parameters | Low | Low |
| BUG-006 | Some observers lack translation fallback text | Low | Low |
