# QA - Content Page Feature

## Test Environment Setup

- **PHP Version:** 8.x
- **Laravel Version:** As defined in `composer.json`
- **Database:** MySQL with `DatabaseTransactions` trait
- **Permissions:** Spatie Permission seeded with page/section permissions
- **Translations:** Spatie Translatable for title fields

## Existing Test Coverage

**2 test files, ~1,196 lines total:**

| Test File | Lines | Focus |
|-----------|-------|-------|
| `tests/Feature/ContentPageSectionTypeApiTest.php` | 1,069 | Comprehensive: auth, permissions, CRUD, attach, reorder, translations, response structure for ContentPages, Sections, SectionTypes |
| `tests/Feature/CmsPageTest.php` | 127 | Public fetch by slug, editor CRUD, authorization |

## Test Matrix (Supplemental)

### Public API Tests

| TC ID | Description | Input | Expected |
|-------|-------------|-------|----------|
| TC-FT-001 | Public page listing | `GET /api/v1/general/pages` | 200, paginated |
| TC-FT-002 | Public page by slug | `GET /api/v1/general/pages/home` | 200, page with sections |
| TC-FT-003 | Public page by slug (inactive) | `GET /api/v1/general/pages/disabled-page` | 404 |
| TC-FT-004 | Public page by slug (not found) | `GET /api/v1/general/pages/nonexistent` | 404 |
| TC-FT-005 | CMS page by slug | `GET /api/v1/cms-pages/about` | 200, content sorted |
| TC-FT-006 | Puck page by path | `GET /api/v1/puck/page?path=/about` | 200 |

### Admin Content Pages Tests

| TC ID | Description | Expected |
|-------|-------------|----------|
| TC-ADM-001 | Create content page | 201 |
| TC-ADM-002 | Create with missing title | 422 |
| TC-ADM-003 | Update content page | 200 |
| TC-ADM-004 | Toggle active status | 200, status flipped |
| TC-ADM-005 | Attach sections to page | 200, sections synced |
| TC-ADM-006 | Attach with invalid section IDs | 422 |
| TC-ADM-007 | Attach with empty array | 200, all removed |
| TC-ADM-008 | Delete content page | 200 |

### Sections Tests

| TC ID | Description | Expected |
|-------|-------------|----------|
| TC-SEC-001 | Create section with valid type | 201 |
| TC-SEC-002 | Create section with invalid type | 422 |
| TC-SEC-003 | Update section configuration | 200 |
| TC-SEC-004 | Toggle section active status | 200 |
| TC-SEC-005 | Reorder sections | 200, order updated |
| TC-SEC-006 | Reorder with invalid IDs | 422 |
| TC-SEC-007 | Delete section | 200 |

### Section Types Tests

| TC ID | Description | Expected |
|-------|-------------|----------|
| TC-ST-001 | Create section type | 201 |
| TC-ST-002 | Create duplicate type | 422 |
| TC-ST-003 | Show by type string | 200 |
| TC-ST-004 | Update section type | 200 |
| TC-ST-005 | Delete section type | 200 |
| TC-ST-006 | Get settings for type | 200, structured object |
| TC-ST-007 | Update settings | 200 |

### Authorization Tests

| TC ID | Description | Expected |
|-------|-------------|----------|
| TC-AUTH-001 | Guest access to admin content pages | 401 |
| TC-AUTH-002 | Guest access to admin sections | 401 |
| TC-AUTH-003 | Guest access to admin section types | 401 |
| TC-AUTH-004 | User without view permission | 403 |
| TC-AUTH-005 | User without create permission | 403 |
| TC-AUTH-006 | User without update permission | 403 |
| TC-AUTH-007 | User without delete permission | 403 |
| TC-AUTH-008 | Non-editor cannot mutate CMS pages | 403 |

### Translation Tests

| TC ID | Description | Expected |
|-------|-------------|----------|
| TC-TR-001 | Create content page with EN + AR title | Both stored |
| TC-TR-002 | Resource returns translated string | String, not JSON |
| TC-TR-003 | Create section with EN + AR title | Both stored |

### Response Structure Tests

| TC ID | Description | Expected Fields |
|-------|-------------|-----------------|
| TC-RS-001 | Content page resource | id, title, slug, is_active, sections |
| TC-RS-002 | Section resource | id, type, title, is_active, endpoint, order, setting |
| TC-RS-003 | CMS page resource | id, slug, title, content, meta, created_at |
| TC-RS-004 | Section title hidden when title_visible=false | Field absent |
| TC-RS-005 | Section endpoint built correctly | `general/{type}?{params}` format |

### Edge Case Tests

| TC ID | Description | Expected |
|-------|-------------|----------|
| TC-EC-001 | Content page with no sections | Empty sections array |
| TC-EC-002 | Section with empty setting | Empty object |
| TC-EC-003 | Section type with no settings | Empty front/back |
| TC-EC-004 | Duplicate slug on content page | 422 |
| TC-EC-005 | Mass assignment protection | Extra fields ignored |
| TC-EC-006 | Reorder with single section | Works |

## Manual Test Checklist

- [ ] Verify public page renders all active sections in order
- [ ] Verify each section type fetches correct component data
- [ ] Verify admin can create page with translatable title
- [ ] Verify admin can attach/detach sections
- [ ] Verify section reorder updates order column
- [ ] Verify toggle active works for both pages and sections
- [ ] Verify content page returns 404 when inactive
- [ ] Verify section resource builds correct endpoint URL
- [ ] Verify section title visibility conditional rendering
- [ ] Verify Puck page upsert works (create + update by path)
- [ ] Verify permission enforcement for each CRUD operation
- [ ] Verify component data endpoints return valid data
