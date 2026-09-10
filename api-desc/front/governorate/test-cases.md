# Test Coverage — Governorate Module (Public API)

---

## Existing Tests

None yet.

## Recommended Tests

### Functional Tests

| # | Test Name | Type | Description |
|---|-----------|------|-------------|
| 1 | `test_list_active_governorates` | Feature | Active only returned |
| 2 | `test_list_inactive_excluded` | Feature | status=false excluded |
| 3 | `test_list_empty` | Feature | No active governorates → [] |
| 4 | `test_list_ordering` | Feature | Ordered by id DESC |

### Response Structure Tests

| # | Test Name | Type | Description |
|---|-----------|------|-------------|
| 1 | `test_list_response_structure` | Feature | Top-level keys: status, message, success, data |
| 2 | `test_governorate_object_structure` | Feature | All resource fields present |
| 3 | `test_governorate_name_translated` | Feature | Name returns in current locale |
