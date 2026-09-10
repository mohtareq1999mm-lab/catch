# QA - Fast Shipping Feature

## Test Matrix

| TC ID | Description | Expected |
|-------|-------------|----------|
| TC-FS-001 | Status endpoint active hours | enabled=true, available=true |
| TC-FS-002 | Status endpoint outside hours | available=false, available_again_at present |
| TC-FS-003 | Status endpoint disabled | enabled=false |
| TC-FS-004 | Products list returns eligible only | No ineligible products |
| TC-FS-005 | Products list with search | Filtered by term |
| TC-FS-006 | Checkout COD success | 201, order created |
| TC-FS-007 | Checkout online success | 201, transaction created |
| TC-FS-008 | Checkout governorate disabled | 422 error |
| TC-FS-009 | Checkout outside hours | 422 error |
| TC-FS-010 | Checkout insufficient stock | 422 error |
| TC-FS-011 | Checkout mixed cart | 422 error |
| TC-FS-012 | Checkout empty cart | 422 error |
| TC-FS-013 | Orders list for user | User's fast orders only |
| TC-FS-014 | Unauthenticated checkout | 401 |
| TC-FS-015 | Unauthenticated orders | 401 |
| TC-FS-016 | Public status endpoint | 200, no auth needed |
| TC-FS-017 | X-Channel header filters products | Correct scope applied |
| TC-FS-018 | Missing header defaults to home | All products shown |
| TC-FS-019 | Cache isolation by channel | Different cache keys |

## Manual Test Checklist

- [ ] Verify status reflects current time vs configured hours
- [ ] Verify products page only shows eligible products
- [ ] Verify checkout flow end-to-end (COD + online)
- [ ] Verify governorate restriction works
- [ ] Verify product toggle updates eligibility
- [ ] Verify X-Channel header changes entire browsing experience
- [ ] Verify English translations display correctly (known gap)
