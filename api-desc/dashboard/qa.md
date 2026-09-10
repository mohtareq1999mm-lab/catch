# QA - Dashboard Feature

## Test Matrix

| TC ID | Description | Expected |
|-------|-------------|----------|
| TC-DB-001 | Overview returns 7 KPI fields | All present |
| TC-DB-002 | Revenue returns 12-month breakdown | All months |
| TC-DB-003 | Order stats has 4 period groups | today, weekly, monthly, yearly |
| TC-DB-004 | All 16 endpoints return 200 | Smoke test |
| TC-DB-005 | Unauthenticated returns 401 | All endpoints |
| TC-DB-006 | Rate limit exceeded returns 429 | After 60 req/min |
| TC-DB-007 | Empty database returns valid structure | Zero values, not errors |
| TC-DB-008 | Invalid endpoint returns 404 | Unknown route |

## Manual Test Checklist

- [ ] Verify all 16 endpoints return correct structure
- [ ] Verify throttle:analytics rate limiting works
- [ ] Verify cache refreshes after 300 seconds
- [ ] Verify unauthenticated access returns 401
