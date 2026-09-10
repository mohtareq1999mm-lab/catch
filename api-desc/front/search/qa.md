# QA - Search Feature

## Status: NOT IMPLEMENTED

| TC ID | Description | Expected | Actual |
|-------|-------------|----------|--------|
| TC-SR-001 | Search endpoint exists | 200 | 404 (route missing) |
| TC-SR-002 | Search returns results | data array | N/A |
| TC-SR-003 | Search rate limiting | 429 after limit | N/A |

## Manual Test Checklist

- [ ] Search endpoint returns 200 OK
- [ ] Search results include products by name/sku
- [ ] Search results include categories by name
- [ ] Search results include brands by name
- [ ] Search results include pages by title
- [ ] Pagination works on results
- [ ] Rate limiting blocks excessive requests
- [ ] Special characters handled safely
