# QA - Shipping Feature

## Test Matrix

### Countries (8 endpoints)

| TC ID | Endpoint | Expected |
|-------|----------|----------|
| TC-SHP-001 | GET /countries | Paginated list, searchable, filterable by status |
| TC-SHP-002 | POST /countries | 201 + resource with validated data |
| TC-SHP-003 | GET /countries/{id} | 200 + resource with governorates |
| TC-SHP-004 | PUT /countries/{id} | 200 + updated resource |
| TC-SHP-005 | DELETE /countries/{id} | 200, cascades delete |
| TC-SHP-006 | GET /countries/{id}/governorates | 200 with nested governorates |
| TC-SHP-007 | POST /countries/change-status | 200, updated count |
| TC-SHP-008 | POST /countries (invalid) | 422, validation errors |

### Governorates (8 endpoints)

| TC ID | Endpoint | Expected |
|-------|----------|----------|
| TC-SHP-009 | GET /governorates | Paginated, filterable by country_id/status |
| TC-SHP-010 | POST /governorates | 201 with shipping price support |
| TC-SHP-011 | GET /governorates/{id} | 200 with country/cities/shippingPrice |
| TC-SHP-012 | PUT /governorates/{id} | 200, shipping price upsert |
| TC-SHP-013 | DELETE /governorates/{id} | 200 (or 400 if has cities) |
| TC-SHP-014 | GET /governorates/{id}/cities | 200 with nested cities |
| TC-SHP-015 | PUT /governorates/change-status | 200 |
| TC-SHP-016 | PUT /governorates/{id}/fast-shipping | 200, toggles boolean |

### Cities (5 endpoints)

| TC ID | Endpoint | Expected |
|-------|----------|----------|
| TC-SHP-017 | GET /cities | Paginated, filterable by governorate_id |
| TC-SHP-018 | POST /cities | 201 |
| TC-SHP-019 | GET /cities/{id} | 200 |
| TC-SHP-020 | PUT /cities/{id} | 200 |
| TC-SHP-021 | DELETE /cities/{id} | 200 |

### Cross-Cutting

| TC ID | Test | Expected |
|-------|------|----------|
| TC-SHP-022 | Unauthenticated | 401 on all endpoints |
| TC-SHP-023 | No permission | 403 on all endpoints |
| TC-SHP-024 | Translatable names | Returns correct locale |
| TC-SHP-025 | JSON search | Matches EN and AR names |
| TC-SHP-026 | Route ordering: `PUT /change-status` before `PUT /{id}` | Returns 200, not 500 |

## Manual Test Checklist

- [ ] Verify all 20 endpoints return correct HTTP codes
- [ ] Verify hierarchy: Country → Governorate → City cascade delete
- [ ] Verify governorate delete blocked when cities exist
- [ ] Verify shipping price upsert on create/update
- [ ] Verify search works with English and Arabic names
- [ ] Verify permission middleware rejects unauthorized users
- [ ] Verify translatable names return in correct locale
