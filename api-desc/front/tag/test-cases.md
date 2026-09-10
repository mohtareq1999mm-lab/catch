# Test Coverage — Tag Module (Public API)

---

## Existing Tests

**File:** `tests/Feature/ProductTagTest.php`

| # | Test Name | Type | Description |
|---|-----------|------|-------------|
| 1 | `test_public_tags_index_returns_all_tags` | Feature | GET /general/tags returns 200 with all tags |
| 2 | `test_public_tags_show_by_slug` | Feature | GET /general/tags/{slug} returns tag by slug |
| 3 | `test_public_tags_show_returns_404_for_invalid_slug` | Feature | Non-existent slug returns 404 |

---

## Recommended Additional Tests

### Response Structure Tests

| # | Test Name | Type | Description |
|---|-----------|------|-------------|
| 1 | `test_tags_list_response_structure` | Feature | Validates top-level keys and tag object keys |
| 2 | `test_tags_show_response_structure` | Feature | Validates tag object includes type |
| 3 | `test_tags_list_empty` | Feature | No tags returns `[]` |

### N+1 Query Tests

| # | Test Name | Type | Description |
|---|-----------|------|-------------|
| 1 | `test_tags_list_no_n_plus_one` | Feature | Verifies type is eager loaded (≤2 queries) |

### Regression Tests

| # | Test Name | Type | Description |
|---|-----------|------|-------------|
| 1 | `test_tag_without_type` | Feature | type_id null → type is null in response |
| 2 | `test_tag_has_translated_languages` | Feature | translated_languages array populated |
