# Category Module — QA Test Cases

## Test Files

13 test files in `tests/Feature/Categories/`:

| File | Lines | Focus |
|------|-------|-------|
| `CategoryCrudTest.php` | 180 | CRUD operations |
| `CategoryValidationTest.php` | 135 | Validation rules |
| `CategoryAuthorizationTest.php` | 168 | Granular permissions |
| `CategoryAuthenticationTest.php` | 152 | Unauthenticated access |
| `CategorySoftDeleteTest.php` | 150 | Soft delete behavior |
| `CategoryTranslationTest.php` | 129 | Multi-locale translations |
| `CategoryRelationshipTest.php` | 177 | Parent-child relationships |
| `CategoryResourceTest.php` | 200 | Response structure |
| `CategoryPivotUniqueTest.php` | 187 | Pivot unique constraint |
| `CategoryFeaturedTest.php` | 180 | Featured toggle |
| `CategoryMediaTest.php` | 33 | Media interface |
| `CategoryMediaLifecycleTest.php` | 247 | Media upload/lifecycle |
| `CategoryRegressionTest.php` | 248 | Bug regression tests |

---

## API Functionality Tests

| # | Test | Description | Expected |
|---|------|-------------|----------|
| F1 | List categories | GET /categories | 200, pagination |
| F2 | Create category | POST /categories | 200, category returned |
| F3 | Show category by ID | GET /categories/{id} | 200, with children + products |
| F4 | Update category | PUT /categories/{id} | 200, updated |
| F5 | Delete category | DELETE /categories/{id} | 200, soft deleted |
| F6 | Toggle featured | PUT /categories/feature | 200, is_featured toggled |
| F7 | Featured categories | GET /featured-categories | 200, ordered by count |

---

## Validation Tests

| # | Test | Description | Expected |
|---|------|-------------|----------|
| V1 | Create without name | Missing name | 422 |
| V2 | Create with duplicate name | Existing unique name | 422 |
| V3 | Create without image-desktop | Missing file | 422 |
| V4 | Create with invalid parent_id | Non-existent category | 422 |
| V5 | Update with invalid parent_id | Non-existent category | 422 |
| V6 | Update with cycle parent | Assign descendant as parent | 422 |
| V7 | Update with self parent | parent_id = own ID | 422 |
| V8 | Update with non-array name | String instead of object | 422 |
| V9 | Feature toggle with invalid ID | Non-existent ID | 422 |
| V10 | Partial update | Only send status | 200, only status changes |

---

## Authorization Tests

| # | Test | Description | Expected |
|---|------|-------------|----------|
| A1 | Guest view categories | No auth on GET /categories | 401 |
| A2 | Guest create | No auth on POST | 401 |
| A3 | Guest update | No auth on PUT | 401 |
| A4 | Guest delete | No auth on DELETE | 401 |
| A5 | Guest toggle feature | No auth on PUT /feature | 401 |
| A6 | View-only cannot create | Has view permission only | 403 |
| A7 | View-only cannot update | Has view permission only | 403 |
| A8 | View-only cannot delete | Has view permission only | 403 |
| A9 | View-only cannot toggle feature | Has view permission only | 403 |
| A10 | No permission at all | Authenticated, no permissions | 403 |
| A11 | Public featured categories | No auth required | 200 |

---

## Soft Delete Tests

| # | Test | Description | Expected |
|---|------|-------------|----------|
| D1 | Soft delete sets deleted_at | DELETE /categories/{id} | Soft deleted |
| D2 | Soft-deleted excluded from index | LIST after delete | Not in results |
| D3 | Show soft-deleted returns 404 | GET soft-deleted | 404 |
| D4 | Force delete removes permanently | forceDelete() | Removed |
| D5 | Multiple soft deletes | Delete same twice | Second throws 404 |
| D6 | Pivot preserved on soft delete | Check category_product | Count unchanged |

---

## Parent-Child Relationship Tests

| # | Test | Description | Expected |
|---|------|-------------|----------|
| R1 | Create child category | parent_id = valid ID | level = parent.level + 1 |
| R2 | Children returned with parent | GET /categories/{id} | Children array present |
| R3 | Delete parent with children | Parent has children | 400 (cannot delete) |
| R4 | Level auto-calculation | Create root (level 1), child (level 2) | Correct levels |
| R5 | Descendant level update | Change parent, descendants update | Levels recalculated |

---

## Featured Toggle Tests

| # | Test | Description | Expected |
|---|------|-------------|----------|
| T1 | Toggle from false to true | PUT /feature | is_featured = true |
| T2 | Toggle from true to false | PUT /feature again | is_featured = false |
| T3 | Double toggle reverts | Toggle twice | Original state |
| T4 | Featured filter works | GET /categories?feature-category=true | Only featured |

---

## Translation Tests

| # | Test | Description | Expected |
|---|------|-------------|----------|
| L1 | Create with English + Arabic | Bilingual name | Stored as JSON |
| L2 | Resource returns translated name | GET with locale | Translated value |
| L3 | Resource does not return raw JSON | Response name is string | Not JSON object |
| L4 | Locale-aware details | GET with locale | Correct translation |

---

## Media Tests

| # | Test | Description | Expected |
|---|------|-------------|----------|
| M1 | Category implements HasMedia | Interface check | True |
| M2 | Upload desktop + mobile images | Valid files | Media collections |
| M3 | Update removes old image | Replace image | Old cleared, new assigned |
| M4 | Soft delete preserves media | Delete category | Media not deleted |
| M5 | Force delete removes media | forceDelete() | Media removed |

---

## Pivot Unique Constraint Tests

| # | Test | Description | Expected |
|---|------|-------------|----------|
| P1 | Duplicate category-product | Same pair twice | Unique violation |
| P2 | Sync deduplicates | Sync with duplicates | Single row |
| P3 | Direct insert duplicate | DB insert duplicate | Exception |
| P4 | Soft delete keeps pivot | Delete category, check pivot | Pivot preserved |

---

## Resource Structure Tests

| # | Test | Description | Expected |
|---|------|-------------|----------|
| S1 | Paginated response structure | Has data, links, meta | Correct structure |
| S2 | Expected fields in index | id, name, slug, image, etc. | All present |
| S3 | Details omitted in index | List response | No details field |
| S4 | Details included in show | Single response | Has details field |
| S5 | Children included when loaded | show response | Has children array |

---

## Missing Coverage

- [ ] Cycle detection on create (setting parent_id to self)
- [ ] Cycle detection on update (reparenting to descendant)
- [ ] Concurrent parent reassignment
- [ ] Feature toggle with non-integer ID
- [ ] Create category with both null and valid parent_id
- [ ] Navbar resource max level depth test
- [ ] `details` as plain string vs JSON object behavior
- [ ] Large hierarchy performance (1000+ categories, depth 10)
