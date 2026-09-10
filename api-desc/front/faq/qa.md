# QA - FAQ Feature

## Test Environment Setup

- **PHP Version:** 8.x
- **Laravel Version:** As defined in `composer.json`
- **Package:** `packages/marvel/`
- **Database:** MySQL with `RefreshDatabase` trait
- **Authentication:** Sanctum for admin endpoints
- **Translations:** Spatie Translatable for title/description

## Existing Test Coverage

**9 test files in `tests/Feature/Faqs/`:**

| Test File | Lines | Focus |
|-----------|-------|-------|
| `FaqCrudTest.php` | 163 | CRUD operations |
| `FaqValidationTest.php` | 117 | Validation rules |
| `FaqAuthorizationTest.php` | 231 | Permission-based access |
| `FaqAuthenticationTest.php` | 149 | Auth requirements |
| `FaqTranslationTest.php` | 139 | Translation storage/retrieval |
| `FaqSoftDeleteTest.php` | 125 | Soft delete behavior |
| `FaqResourceTest.php` | 169 | API resource JSON structure |
| `FaqReorderTest.php` | 111 | Reordering |
| `FaqRegressionTest.php` | 187 | Regression tests |

## Test Matrix (Supplemental)

### Public API Tests

| TC ID | Description | Input | Expected |
|-------|-------------|-------|----------|
| TC-FT-001 | Public FAQ listing | `GET /api/v1/general/faqs` | 200, all active FAQs |
| TC-FT-002 | Public FAQ shows only active | Inactive FAQ in DB | Not returned |
| TC-FT-003 | Public FAQ sorted by order | Multiple FAQs | Correct order |

### Admin CRUD Tests

| TC ID | Description | Expected |
|-------|-------------|----------|
| TC-CRUD-001 | Create FAQ with required fields | 201 |
| TC-CRUD-002 | Create with missing title | 422 |
| TC-CRUD-003 | Create with title < 3 chars | 422 |
| TC-CRUD-004 | Create with title > 1000 chars | 422 |
| TC-CRUD-005 | Update FAQ partial | 200 |
| TC-CRUD-006 | Update status | Status toggled |
| TC-CRUD-007 | Delete FAQ | 200, soft-deleted |

### Reorder Tests

| TC ID | Description | Expected |
|-------|-------------|----------|
| TC-RO-001 | Reorder with valid IDs | Order updated |
| TC-RO-002 | Reorder with missing faqs param | 422 |
| TC-RO-003 | Reorder with invalid IDs | 422 |
| TC-RO-004 | Reorder with single FAQ | Single item reordered |

### Authorization Tests

| TC ID | Description | Expected |
|-------|-------------|----------|
| TC-AUTH-001 | Guest access to admin list | 401 |
| TC-AUTH-002 | Guest access to create | 401 |
| TC-AUTH-003 | User without view-faqs permission | 403 |
| TC-AUTH-004 | User without create-faq permission | 403 |
| TC-AUTH-005 | User without update-faq permission | 403 |
| TC-AUTH-006 | User without delete-faq permission | 403 |
| TC-AUTH-007 | Public endpoint (no auth) | 200 |

### Translation Tests

| TC ID | Description | Expected |
|-------|-------------|----------|
| TC-TR-001 | Create FAQ with EN + AR | Both stored |
| TC-TR-002 | Resource returns translated string | String, not JSON |
| TC-TR-003 | Locale-sensitive retrieval | Correct language |

### Soft Delete Tests

| TC ID | Description | Expected |
|-------|-------------|----------|
| TC-SD-001 | Soft delete FAQ | deleted_at set |
| TC-SD-002 | Deleted FAQ absent from index | Not in list |
| TC-SD-003 | Deleted FAQ returns 404 on show | 404 |
| TC-SD-004 | Force delete permanently | Row removed |

### Edge Case Tests

| TC ID | Description | Expected |
|-------|-------------|----------|
| TC-EC-001 | Empty FAQ list | Empty data array |
| TC-EC-002 | Reorder with duplicate IDs | Handled gracefully |
| TC-EC-003 | Create with extra fields | Mass assignment protection |
| TC-EC-004 | Bulk soft delete | Multiple deleted |

## Manual Test Checklist

- [ ] Verify public FAQ page shows all active FAQs
- [ ] Verify admin can create FAQ with EN + AR titles
- [ ] Verify admin can update FAQ content
- [ ] Verify admin can soft-delete FAQ
- [ ] Verify drag-and-drop reorder works
- [ ] Verify permission enforcement for each CRUD operation
- [ ] Verify GraphQL queries return correct FAQ data
- [ ] Verify GraphQL mutations create/update/delete FAQs
- [ ] Verify role-based scoping (Super Admin sees all, Store Owner sees own shop)
