# Test Coverage — Pages Module

---

## Existing Tests

**File:** `tests/Feature/ContentPageSectionTypeApiTest.php` (1070 lines, 63 tests)

The existing test suite covers:
- Guest 401 for admin endpoints
- Permission-based 403 for all 12 permissions
- CRUD for content pages, sections, section types
- Attach/detach sections
- Toggle active for pages and sections
- Reorder sections
- Section type settings (get/update)
- Translation flow (Arabic/English)
- Response JSON structure
- Mass assignment protection
- Unique translation validation (via stub)

---

## Recommended Additional Tests

### Public API Tests

| # | Test Name | Type | Description |
|---|-----------|------|-------------|
| 1 | `test_product_type_returns_all_keys` | Feature | Returns 9 keys |
| 2 | `test_product_type_english_labels` | Feature | Default lang → English labels |
| 3 | `test_product_type_arabic_labels` | Feature | lang: ar → Arabic labels |
| 4 | `test_public_index_returns_only_active_sections` | Feature | Inactive sections excluded |
| 2 | `test_public_show_by_slug` | Feature | Slug lookup works |
| 3 | `test_public_show_returns_404` | Feature | Invalid slug → 404 |
| 4 | `test_public_no_auth_required` | Feature | 200 without token |

### Content Page Tests

| # | Test Name | Type | Description |
|---|-----------|------|-------------|
| 5 | `test_create_page_auto_generates_slug` | Feature | Slug from title.en |
| 6 | `test_create_page_defaults_to_active` | Feature | is_active = true by default |
| 7 | `test_update_page_title_regenerates_slug` | Feature | Check if slug changes |
| 8 | `test_delete_page_cascades` | Feature | sections.content_page_id set to null |
| 9 | `test_attach_sections_updates_content_page_id` | Feature | FK set correctly |
| 10 | `test_attach_empty_detaches_all` | Feature | Empty array → all null |
| 11 | `test_toggle_active_flips_status` | Feature | true→false, false→true |
| 12 | `test_index_paginates` | Feature | Default 15 per page |

### Section Tests

| # | Test Name | Type | Description |
|---|-----------|------|-------------|
| 13 | `test_create_section_auto_sets_endpoint` | Feature | endpoint = general/{type} |
| 14 | `test_create_section_sets_order_auto` | Feature | SortableTrait auto-order |
| 15 | `test_create_section_with_custom_setting` | Feature | JSON setting stored |
| 16 | `test_update_section_clears_cache` | Regression | If cache implemented |
| 17 | `test_reorder_updates_order_column` | Feature | Order matches array index |
| 18 | `test_section_settings_fallback_to_type` | Feature | No own setting → type default |
| 19 | `test_section_title_hidden_when_not_visible` | Feature | title_visible=false → title=null |
| 20 | `test_dynamic_endpoint_generated_correctly` | Feature | back params in query string |

### Section Type Tests

| # | Test Name | Type | Description |
|---|-----------|------|-------------|
| 21 | `test_create_section_type` | Feature | Type created |
| 22 | `test_create_duplicate_section_type` | Feature | 422 unique |
| 23 | `test_update_section_type` | Feature | Type updated |
| 24 | `test_delete_section_type` | Feature | Type deleted |
| 25 | `test_get_settings_empty` | Feature | No settings → front/back empty |
| 26 | `test_update_settings_replaces_all` | Feature | Old settings deleted, new created |
| 27 | `test_update_settings_none` | Feature | Only front provided → back = [] |
| 28 | `test_get_settings_not_found` | Feature | Invalid type → 404 |

### Response Structure Tests

| # | Test Name | Type | Description |
|---|-----------|------|-------------|
| 29 | `test_content_page_resource_structure` | Feature | id, title, slug, is_active, sections |
| 30 | `test_section_resource_structure` | Feature | id, type, title, is_active, endpoint, order, setting |
| 31 | `test_section_resource_title_null_when_hidden` | Feature | title_visible=false |
| 32 | `test_admin_includes_inactive_sections` | Feature | Admin shows all sections |
| 33 | `test_public_excludes_inactive_sections` | Feature | Public filters inactive |

### Authentication & Authorization Tests

| # | Test Name | Type | Description |
|---|-----------|------|-------------|
| 34 | `test_unauthenticated_admin_401` | Feature | No token → 401 |
| 35 | `test_customer_role_403` | Feature | Customer → 403 |
| 36 | `test_missing_permission_403` | Feature | No create permission → 403 |
| 37 | `test_all_12_permissions_enforced` | Feature | Each permission tested |

### Translation Tests

| # | Test Name | Type | Description |
|---|-----------|------|-------------|
| 38 | `test_create_with_arabic_title` | Feature | Arabic stored and returned |
| 39 | `test_update_title_in_arabic` | Feature | Arabic updated |
| 40 | `test_title_max_length_enforced` | Feature | 30 chars for page, 50 for section |

### Regression Tests

| # | Test Name | Type | Description |
|---|-----------|------|-------------|
| 41 | `test_section_attached_to_page_shows_in_page` | Feature | After attach, section in page.sections |
| 42 | `test_section_detached_from_page_removed` | Feature | After detach, section not in page.sections |
| 43 | `test_reorder_persists_after_refetch` | Feature | Reorder → GET → same order |
| 44 | `test_toggle_active_persists` | Feature | Toggle → GET → updated status |

### New Regression Tests (Bug SECTION-B002)

| # | Test Name | Type | Description |
|---|-----------|------|-------------|
| R45 | `test_create_section_with_multilingual_title_via_formdata` | Regression | POST FormData with title[en], title[ar] → title stored as JSON object |
| R46 | `test_update_section_with_multilingual_title_via_formdata` | Regression | PUT (spoofed) FormData with title[en], title[ar] → title updated correctly |
| R47 | `test_create_section_title_not_empty_array` | Regression | Ensure title !== [] after create from any content type |
