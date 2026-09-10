# Attribute Module — QA Test Plan (CRUD)

## 1. Functionality
| Test | Expected |
|------|----------|
| List attributes with pagination | Paginated data with correct structure |
| Create attribute without values | 201, attribute returned |
| Create attribute with values | Values created and returned |
| Show attribute by ID | Attribute with values loaded |
| Show attribute by slug | Attribute with values loaded |
| Update attribute name | Name updated, slug regenerated |
| Update attribute values | Values synced (new created, missing deleted) |
| Update with empty values | All existing values deleted |
| Delete attribute | Attribute + values + pivot removed |

## 2. Validation
| Test | Expected |
|------|----------|
| Create with missing name | 422 |
| Create with missing name.en | 422 |
| Create with missing name.ar | 422 |
| Create with duplicate name | 422 |
| Create with short name (<2 chars) | 422 |
| Create with long name (>50 chars) | 422 |
| Create with invalid values format | 422 |
| Create with missing value.en | 422 |
| Update with same name (self) | 200 |
| Update with duplicate name (other) | 422 |

## 3. Authorization
| Test | Expected |
|------|----------|
| Guest lists attributes | 401 or 403 |
| Guest shows attribute | 401 or 403 |
| Guest creates attribute | 401 |
| Guest updates attribute | 401 |
| Guest deletes attribute | 401 |
| View-only user creates | 403 |
| View-only user updates | 403 |
| View-only user deletes | 403 |

## 4. Edge Cases
| Test | Expected |
|------|----------|
| Show non-existent attribute | 404 |
| Delete non-existent attribute | 404 |
| Update non-existent attribute | 404 |
| Create with empty values array | 200, no values |
| Attribute with no values | Empty values array |
| Special characters in name/value | Stored correctly |

## 5. Resource Structure
| Test | Expected |
|------|----------|
| List response structure | `{ data[], pagination }` |
| Show response structure | `{ id, name, slug, values[] }` |
| Name translated in list | Locale-aware string |
| Name raw in show | JSON object |

## 6. Cascade Behavior
| Test | Expected |
|------|----------|
| Delete attribute with values | All values cascade deleted |
| Delete attribute preserves products | Products unaffected |

## Missing Coverage
- Name min/max/unique validation not tested
- Partial update (only name.en) not tested
- Resource name format (list vs show) not verified
- Empty values array edge case not tested
