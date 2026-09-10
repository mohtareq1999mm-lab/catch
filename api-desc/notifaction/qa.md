# QA - Notification Feature

## Test Matrix

| TC ID | Endpoint | Expected |
|-------|----------|----------|
| TC-NOT-001 | GET /admin/notifications | Paginated list, own only |
| TC-NOT-002 | GET /admin/notifications (empty) | Empty data, meta.total=0 |
| TC-NOT-003 | GET /admin/notifications/unread | Only unread |
| TC-NOT-004 | GET /admin/notifications/unread (all read) | Empty array, total=0 |
| TC-NOT-005 | PATCH /admin/notifications/{id}/read | read_at populated |
| TC-NOT-006 | PATCH /admin/notifications/{id}/read (already read) | Idempotent, 200 |
| TC-NOT-007 | PATCH /admin/notifications/{id}/read (not found) | 404 |
| TC-NOT-008 | PATCH /admin/notifications/read-all | All read, marked_count |
| TC-NOT-009 | DELETE /admin/notifications/{id} | Hard delete, 200 |
| TC-NOT-010 | DELETE /admin/notifications/{id} (not found) | 404 |
| TC-NOT-011 | DELETE /admin/notifications | All own deleted |
| TC-NOT-012 | DELETE /admin/notifications (other user's) | Not affected |
| TC-NOT-013 | Unauthenticated | 401 on all 6 |
| TC-NOT-014 | User without view-notifications permission | 403 on all 6 |
| TC-NOT-015 | View-only permission | 403 on mark/delete |

## Manual Test Checklist

- [ ] Verify a new notification appears after creating an order
- [ ] Verify notifications are user-scoped (admin A cannot see admin B's)
- [ ] Verify notification structure has all 10 fields
- [ ] Verify mass operations (read-all, delete-all) affect correct user only
