# QA - Pickup Location Feature

## Test Matrix

| TC ID | Endpoint | Expected |
|-------|----------|----------|
| TC-PL-001 | GET /pickup-locations | Paginated list, ordered, filterable |
| TC-PL-002 | POST /pickup-locations | 200 + resource |
| TC-PL-003 | GET /pickup-locations/{id} | 200 + resource |
| TC-PL-004 | PUT /pickup-locations/{id} | 200 + updated resource |
| TC-PL-005 | DELETE /pickup-locations/{id} | 200, soft deleted |
| TC-PL-006 | GET /general/pickup-locations | Only active, no auth |
| TC-PL-007 | GET /general/pickup-locations/{id} | Active only, 404 if inactive |
| TC-PL-008 | POST (no store_name) | 422 |
| TC-PL-009 | POST (no address) | 422 |
| TC-PL-010 | POST (no phone) | 422 |
| TC-PL-011 | POST (invalid email) | 422 |
| TC-PL-012 | POST (negative display_order) | 422 |
| TC-PL-013 | Unauthenticated access | 401 admin, 200 public |
| TC-PL-014 | Customer access | 403 on CRUD |
| TC-PL-015 | Soft-deleted show | 404 |
| TC-PL-016 | Working hours structure | Validates day+open+close |
| TC-PL-017 | PUT (partial update — single field) | 200, only changed field updates |
| TC-PL-018 | PUT (invalid email) | 422 |
| TC-PL-019 | PUT (negative display_order) | 422 |
| TC-PL-020 | PUT (working_hours.day.ar + day.en) | 200, accepts translatable day names |
| TC-PL-021 | POST (is_default: true, first default) | 200, location becomes default |
| TC-PL-022 | POST (is_default: true, when another default exists) | 200, previous default cleared |
| TC-PL-023 | PUT (is_default: true) switches default | 200, only one default remains |
| TC-PL-024 | PUT (update fields of current default) | 200, is_default preserved |
| TC-PL-025 | POST (is_default: non-boolean) | 422 |
| TC-PL-026 | DELETE default location | 200, next-lowest-id promoted to default |
| TC-PL-027 | DELETE non-default location | 200, current default unchanged |
| TC-PL-028 | GET /general/pickup-locations includes is_default | 200, is_default present |
| TC-PL-029 | GET /pickup-locations includes is_default | 200, is_default present |
| TC-PL-030 | Concurrent default switches | Only one default after both requests |
| TC-PL-031 | Public show soft-deleted location | 404 envelope `{status:404, success:false}` (plain apiResponse, not exception handler) |
| TC-PL-032 | Create/update/delete dispatches LogActivityJob | Queued job with correct action (`created`/`updated`/`deleted`) |
| TC-PL-033 | Status-only change dispatches `statusChanged` job with old/new values | Job payload contains old + new status |
| TC-PL-034 | Admin list cache flushed after create/update/delete | Immediate fresh read reflects write |
| TC-PL-035 | Public list `?default=1` | 200, paginator containing only the `is_default = true` branch |
| TC-PL-036 | Public list `?default=0` / omitted | 200, all active branches (filter ignored) |
| TC-PL-037 | Public list `?default=1` with no active default | 200, empty `data.data[]` (no error) |

## Manual Test Checklist

- [ ] Verify ordering by display_order ASC
- [ ] Verify search finds by store_name
- [ ] Verify public list excludes inactive and soft-deleted
- [ ] Verify checkout saves pickup snapshot to order
- [ ] Verify pickup_location shows in order detail for pickup orders
- [ ] Verify uploaded coordinates render on map
- [ ] Verify setting a branch as default clears the previous default in the admin list
- [ ] Verify the public list marks the default branch (is_default: true)
- [ ] Verify checkout preselects the default branch
- [ ] Verify deleting the default promotes another branch
