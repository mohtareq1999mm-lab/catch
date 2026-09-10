# QA - Slider Feature

## Test Matrix

| TC ID | Endpoint | Expected |
|-------|----------|----------|
| TC-SLD-001 | GET /sliders | Paginated, ordered, filterable |
| TC-SLD-002 | POST /sliders (valid) | 200 + slider with image URLs |
| TC-SLD-003 | POST /sliders (no image) | 422 |
| TC-SLD-004 | POST /sliders (no title) | 422 |
| TC-SLD-005 | POST /sliders (large image) | 422 |
| TC-SLD-006 | GET /sliders/{id} | 200 + resource |
| TC-SLD-007 | GET /sliders/{id} (404) | 404 |
| TC-SLD-008 | PUT /sliders/{id} | 200 + updated |
| TC-SLD-009 | DELETE /sliders/{id} | 200, soft deleted |
| TC-SLD-010 | PATCH /sliders/change-status | Status toggled |
| TC-SLD-011 | PUT /sliders/reorder | Order updated |
| TC-SLD-012 | Unauthenticated | 401 |
| TC-SLD-013 | No permission | 403 |
| TC-SLD-014 | GET /general/sliders | Active only |
| TC-SLD-015 | GET /general/sliders/{slug} | Enriched products |

## Manual Test Checklist

- [ ] Verify image uploads for desktop and mobile
- [ ] Verify reorder persists on refresh
- [ ] Verify status toggle affects public display
- [ ] Verify product associations sync correctly
- [ ] Verify title translations display per locale
