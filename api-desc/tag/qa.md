# Tag Module — QA Test Cases

## Test Files

No test files currently exist for the Tag module.

---

## API Functionality Tests

| # | Test | Description | Expected |
|---|------|-------------|----------|
| F1 | List tags | GET /tags?language=en | 200, pagination |
| F2 | Create tag | POST /tags with name | 201, tag returned |
| F3 | Show tag by ID | GET /tags/{id} | 200, tag returned |
| F4 | Show tag by slug | GET /tags/{slug} | 200, tag returned |
| F5 | Update tag | PUT /tags/{id} | 200, updated |
| F6 | Delete tag | DELETE /tags/{id} | 200, true |

---

## Validation Tests

| # | Test | Description | Expected |
|---|------|-------------|----------|
| V1 | Create without name | Missing name field | 422 |
| V2 | Create with duplicate name | Existing unique name (same locale) | 422 |
| V3 | Create with non-array name | String instead of object | 422 |
| V4 | Update with duplicate name | Name taken by another tag | 422 |
| V5 | Update with non-array name | String instead of object | 422 |
| V6 | Partial update | Only send icon | 200, only icon changes |
| V7 | Create with invalid image | Non-image file | 422 |

---

## Authorization Tests

| # | Test | Description | Expected |
|---|------|-------------|----------|
| A1 | Guest list tags | No auth on GET /tags | 401 |
| A2 | Guest create | No auth on POST | 401 |
| A3 | Guest show | No auth on GET /tags/{id} | 401 |
| A4 | Guest update | No auth on PUT | 401 |
| A5 | Guest delete | No auth on DELETE | 401 |
| A6 | View-only cannot create | Has view permission only | 403 |
| A7 | View-only cannot update | Has view permission only | 403 |
| A8 | View-only cannot delete | Has view permission only | 403 |
| A9 | No permission at all | Authenticated, no permissions | 403 |

---

## Translation Tests

| # | Test | Description | Expected |
|---|------|-------------|----------|
| L1 | Create with English + Arabic | Bilingual name | Stored as JSON |
| L2 | Resource returns translated name | GET with locale | Translated value |
| L3 | Resource does not return raw JSON | Response name is string | Not JSON object |

---

## Media Tests

| # | Test | Description | Expected |
|---|------|-------------|----------|
| M1 | Upload image on create | Valid image file | Media stored |
| M2 | Upload icon on create | Valid icon file | Media stored |
| M3 | Update replaces image | Replace existing image | Old cleared, new assigned |
| M4 | Delete does not clean media | Delete tag | Media NOT deleted (known issue) |

---

## Resource Structure Tests

| # | Test | Description | Expected |
|---|------|-------------|----------|
| S1 | Paginated response structure | Has data, page, meta | Correct structure |
| S2 | Expected fields in index | id, name, slug, image, icon | All present |
| S3 | Expected fields in show | id, name, slug, image, icon | All present |

---

## Edge Cases

| # | Test | Description | Expected |
|---|------|-------------|----------|
| E1 | Show non-existent ID | GET /tags/99999 | 404 |
| E2 | Update non-existent ID | PUT /tags/99999 | 404 |
| E3 | Delete non-existent ID | DELETE /tags/99999 | 404 |
| E4 | Create with empty name object | `{"name": {}}` | 422 |
| E5 | Create with extra fields | Unknown fields in request | Ignored, 201 |

---

## Missing Coverage

- [ ] No tests exist at all
- [ ] Soft delete tests (not applicable — hard delete only)
- [ ] Concurrent tag operations
- [ ] Slug auto-generation edge cases (empty name, special characters)
- [ ] Image upload file type validation
- [ ] Icon upload file type validation
- [ ] Pagination boundary tests (page 0, negative page, excessive limit)
