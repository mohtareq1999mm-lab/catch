# QA - Category Feature

## Test Environment Setup

- **PHP Version:** 8.x
- **Laravel Version:** As defined in `composer.json`
- **Package:** `packages/marvel/`
- **Database:** MySQL with RefreshDatabase trait
- **Media:** Spatie Media Library (local disk)
- **Queue:** Sync driver for activity log testing

## Existing Test Coverage

**12 test files in `tests/Feature/Categories/`:**

| Test File | Cases | Coverage |
|-----------|-------|----------|
| `CategoryCrudTest.php` | CRUD operations | Create, list, show, update, soft-delete, 404 |
| `CategoryValidationTest.php` | Validation rules | Missing name, partial update, invalid parent_id, non-array name |
| `CategoryAuthenticationTest.php` | Auth checks | Unauthenticated blocked for CRUD/toggle, public endpoints accessible, super admin access |
| `CategoryAuthorizationTest.php` | Permission checks | View-only user, granular create/update/delete/toggle permissions |
| `CategoryTranslationTest.php` | Multi-language | Creation with en+ar, translated resource output, locale-sensitive details |
| `CategorySoftDeleteTest.php` | Soft delete behavior | Soft delete, absent from index, 404 on show, force delete, multiple deletes |
| `CategoryRelationshipTest.php` | Parent-child relations | Tree structure, children returned, no cascade on parent delete, invalid parent_id |
| `CategoryResourceTest.php` | Resource structure | Paginated structure, expected fields, details omitted in index, present in show, featured endpoint |
| `CategoryPivotUniqueTest.php` | Pivot uniqueness | No duplicate on sync, unique constraint violation, shared products, cascade on force delete |
| `CategoryMediaTest.php` | Media attachment | HasMedia interface, two collections (desktop/mobile) |
| `CategoryMediaLifecycleTest.php` | Media lifecycle | Upload, update removes old, soft delete preserves, force delete removes, independent collections |
| `CategoryFeaturedTest.php` | Featured toggle | Public endpoint, toggle requires update permission, toggle works bidirectionally, validates id |
| `CategoryRegressionTest.php` | Regression checks | SoftDelete trait, translated resources, featured public, translation keys exist, dead routes, slug handling |

## Test Matrix (Supplemental)

### Functional Tests

| TC ID | Description | Input | Expected |
|-------|-------------|-------|----------|
| TC-FT-001 | Public category listing | `GET /api/v1/general/categories` | 200, paginated list |
| TC-FT-002 | Public category search | `?search=shoe` | Filtered results matching "shoe" |
| TC-FT-003 | Public category by slug | `GET /api/v1/general/categories/shoes` | 200, single category with children + products |
| TC-FT-004 | Public category by slug (invalid) | `GET /api/v1/general/categories/nonexistent` | 404 |
| TC-FT-005 | Parent-only filter | `?parentOnly=true` | Only root categories (parent_id IS NULL) |

### Security Tests

| TC ID | Description | Expected |
|-------|-------------|----------|
| TC-SEC-001 | Unauthenticated CRUD access | 401 for create/update/delete |
| TC-SEC-002 | Unauthorized (view-only) create | 403 |
| TC-SEC-003 | Featured toggle without permission | 403 |
| TC-SEC-004 | Public featured endpoint (no auth) | 200 (public) |

### Edge Case Tests

| TC ID | Description | Expected |
|-------|-------------|----------|
| TC-EC-001 | Circular reference on parent_id update | 422 with cycle error |
| TC-EC-002 | Self-parenting | 422 (same ID as parent) |
| TC-EC-003 | Deep hierarchy (level > 10) | Correct level calculation |
| TC-EC-004 | Category with 1000+ products | Should paginate properly |
| TC-EC-005 | Maximum field lengths (name, details) | Proper validation |
| TC-EC-006 | Slug constraint | Unique slug enforced |
| TC-EC-007 | Upload invalid image type | 422 validation error |
| TC-EC-008 | Upload oversize image (>2MB) | 422 validation error |

## Manual Test Checklist

- [ ] Verify public category listing loads all active categories
- [ ] Verify category detail page shows children and products
- [ ] Verify admin can create category with multi-language name
- [ ] Verify admin can upload desktop and mobile images
- [ ] Verify admin can set parent category and correct level is calculated
- [ ] Verify admin cannot set self as parent (circular ref)
- [ ] Verify admin can update category fields
- [ ] Verify admin can soft-delete category
- [ ] Verify soft-deleted category does not appear in public listing
- [ ] Verify admin can force-delete category (permanently removes)
- [ ] Verify featured categories toggle works
- [ ] Verify featured categories endpoint is publicly accessible
- [ ] Verify details field excluded from listing API
- [ ] Verify details field present in detail API
- [ ] Verify activity log entries created for all CRUD operations
