# Test Cases - Notification Feature

## Current Coverage

**File:** `tests/Feature/NotificationTest.php` (845 lines, 38 tests)

| Category | Tests | Coverage |
|----------|-------|----------|
| Authentication (unauthenticated) | 6 | All 6 endpoints return 401 |
| Authorization (non-admin / no permission) | 3 | List, mark, delete return 403 |
| Permission (view-only) | 5 | Mark/delete blocked, list allowed |
| Fetch notifications | 4 | Pagination, scoping, empty state |
| Unread | 2 | Filters read, empty state |
| Mark as read | 3 | Success, idempotent, 404 |
| Mark all as read | 2 | Count, none unread |
| Delete single | 2 | Success, 404 |
| Delete all | 3 | Count, empty, user-scoped |
| Event-driven creation | 3 | Order, Contact, AdminLogin |
| JSON structure | 6 | Response format for all endpoints |

## What's Covered

✅ Unauthenticated blocked on all 6 endpoints
✅ Non-admin blocked (users without permissions)
✅ View-only permission blocked for write operations
✅ Pagination on list
✅ User-scoping (own notifications only)
✅ Empty state (no notifications)
✅ Mark as read idempotent
✅ 404 for non-existent notification
✅ Mark all as read count
✅ Delete all user-scoped
✅ Event → notification creation (3 event types)
✅ Full JSON response structure validation
✅ `AdminLoggedIn` event listener registration

## Recommended Additional Tests

| # | Test | Priority |
|---|------|----------|
| FT-001 | Real-time notification (Pusher/WebSocket) delivery | Medium |
| FT-002 | Rate limit on notification endpoints | Low |
| FT-003 | Export notification history | Low |
