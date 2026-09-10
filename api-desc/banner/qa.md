# QA - Banner Feature

## Test Matrix

| TC ID | Endpoint | Expected |
|-------|----------|----------|
| TC-BAN-001 | GET /banners | Paginated, ordered, with products |
| TC-BAN-002 | GET /banners?active=true | Only active |
| TC-BAN-003 | POST /banners (valid) | 200, banner with image URLs |
| TC-BAN-004 | POST /banners (no image) | 422 |
| TC-BAN-005 | POST /banners (no title) | 422 |
| TC-BAN-006 | POST /banners (large image) | 422 |
| TC-BAN-007 | GET /banners/{id} | 200 + resource |
| TC-BAN-008 | GET /banners/{id} (404) | 404 |
| TC-BAN-009 | PUT /banners/{id} | 200 + updated |
| TC-BAN-010 | DELETE /banners/{id} | 200, soft deleted |
| TC-BAN-011 | PUT /banner/change-status | Status toggled |
| TC-BAN-012 | PUT /banner/change-status (invalid id) | 422 |
| TC-BAN-013 | POST /banner/reorder | Order updated |
| TC-BAN-014 | POST /banner/reorder (invalid id) | 422 |
| TC-BAN-015 | Unauthenticated | 401 |
| TC-BAN-016 | No permission | 403 |

## Manual Test Checklist

- [ ] Verify image uploads work for desktop and mobile
- [ ] Verify images are served correctly via MediaLibrary
- [ ] Verify reorder persists (refresh and check order)
- [ ] Verify status toggle changes display on public page
- [ ] Verify product associations sync correctly
- [ ] Verify title/description translations display per locale
