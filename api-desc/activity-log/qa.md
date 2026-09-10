# QA - Activity Log Feature

## Test Matrix

| TC ID | Description | Input | Expected |
|-------|-------------|-------|----------|
| TC-AL-001 | Unauthenticated access | No token | 401 |
| TC-AL-002 | Access without permission | Token, no `view-activity-log` | 403 |
| TC-AL-003 | Fetch all logs | Authenticated admin | 200, paginated |
| TC-AL-004 | Filter by log_name | `?log_name=products` | Only products logs |
| TC-AL-005 | Filter by event | `?event=created` | Only created events |
| TC-AL-006 | Filter by causer_id | `?causer_id=1` | Only user 1's actions |
| TC-AL-007 | Search | `?search=Product` | Matching entries |
| TC-AL-008 | Combined filters | `?log_name=products&event=created` | Both applied |
| TC-AL-009 | Custom per_page | `?per_page=50` | 50 results |
| TC-AL-010 | Empty database | No logs | data=[], total=0 |
| TC-AL-011 | Large result set | 1000+ logs | Paginated correctly |

## Manual Test Checklist

- [ ] Verify CRUD on Products appears in activity log
- [ ] Verify CRUD on Users appears in activity log
- [ ] Verify CRUD on Categories appears in activity log
- [ ] Verify CRUD on Brands appears in activity log
- [ ] Verify CRUD on Coupons appears in activity log
- [ ] Verify CRUD on FlashSales appears in activity log
- [ ] Verify CRUD on Promotions appears in activity log
- [ ] Verify CRUD on Roles appears in activity log
- [ ] Verify CRUD on PickupLocations appears in activity log
- [ ] Verify order lifecycle events appear (created, cancelled, status changed)
- [ ] Verify payment events appear (succeeded, failed)
- [ ] Verify permission enforcement
- [ ] Verify filters return correct results
- [ ] Verify pagination works correctly
