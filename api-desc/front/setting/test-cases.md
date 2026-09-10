# Test Coverage — Settings Module (Public API)

---

## Existing Tests

**Files:** `tests/Feature/Settings/SettingsCrudTest.php`, `tests/Feature/Settings/SettingsValidationTest.php`, `tests/Feature/Settings/SettingsAuthenticationTest.php`, `tests/Feature/Settings/SettingsRegressionTest.php`

| # | Test Name | Type | Description |
|---|-----------|------|-------------|
| 1 | Admin CRUD tests | Feature | Settings create/update/destroy via admin API |
| 2 | Validation tests | Feature | SettingsRequest validation rules |
| 3 | Authentication tests | Feature | Auth guard for admin endpoints |
| 4 | Regression tests | Feature | Settings update flows |

**No tests exist for the public `GET /api/v1/general/settings` endpoint.**

---

## Recommended Tests

### Fetch Settings Tests

| # | Test Name | Type | Description |
|---|-----------|------|-------------|
| 1 | `test_fetch_settings_success` | Feature | 200, all fields present |
| 2 | `test_fetch_settings_translated` | Feature | Locale header changes translated fields |
| 3 | `test_fetch_settings_no_record` | Feature | Empty table → 200 with nulls |
| 4 | `test_fetch_settings_media_urls` | Feature | Logo and favicon URLs present |

### Response Structure Tests

| # | Test Name | Type | Description |
|---|-----------|------|-------------|
| 1 | `test_response_structure` | Feature | Top-level status, message, success, data |
| 2 | `test_settings_object_fields` | Feature | All 18 fields present with correct types |

### Regression Tests

| # | Test Name | Type | Description |
|---|-----------|------|-------------|
| 1 | `test_settings_after_update` | Feature | Update site_name via admin, verify public reflects change |
| 2 | `test_options_as_object` | Feature | Options returns {} when null, object when populated |
| 3 | `test_fast_shipping_flag` | Feature | Boolean field toggles correctly |
| 4 | `test_minimum_order_amount_present` | Feature | minimumOrderAmount field exists, is float |
| 5 | `test_minimum_order_amount_default` | Feature | Defaults to 0 when not configured |
