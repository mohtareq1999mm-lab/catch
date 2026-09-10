# Test Coverage — Settings Module (Admin API)

---

## Existing Tests

### `tests/Feature/Settings/SettingsCrudTest.php`

| Test | Type | Description |
|------|------|-------------|
| `can_view_settings` | Feature | GET /api/v1/general/settings (public, no auth) → 200 |
| `can_view_admin_settings` | Feature | GET /api/v1/settings (admin, with token) → 200 |
| `can_update_settings` | Feature | PUT /api/v1/settings with valid data (incl. tiktok/snapchat) → 200 |
| `settings_returns_expected_json_structure` | Structure | GET public returns expected fields (incl. `tiktok: null`, `snapchat: null`; translatable as single locale; `minimumOrderAmount` as string) |
| `omitted_tiktok_and_snapchat_preserve_existing_values` | Feature | PUT without tiktok/snapchat keeps stored values |
| `updated_tiktok_and_snapchat_are_returned_by_admin_and_website_endpoints` | Feature | PUT tiktok/snapchat reflected in admin + public GET |

### `tests/Feature/Settings/SettingsValidationTest.php`

| Test | Type | Description |
|------|------|-------------|
| `update_returns_422_without_site_name` | Validation | Missing site_name → 422 |
| `update_returns_422_without_site_email` | Validation | Missing site_email → 422 |
| `update_returns_422_with_invalid_email` | Validation | Bad email → 422 |
| `update_returns_422_with_invalid_url` | Validation | Bad URL → 422 |
| `update_returns_422_with_invalid_tiktok_url` | Validation | `tiktok: "not-a-url"` → 422 |
| `update_returns_422_with_invalid_snapchat_url` | Validation | `snapchat: "not-a-url"` → 422 |
| `update_returns_422_without_fast_shipping_page_publish` | Validation | Missing fast_shipping_page_publish → 422 |
| `update_returns_422_with_invalid_fast_shipping_value` | Validation | Invalid fast_shipping_page_publish → 422 |

### `tests/Feature/Settings/SettingsAuthenticationTest.php`

| Test | Type | Description |
|------|------|-------------|
| `guests_can_view_settings` | Auth | Public `GET /api/v1/general/settings` → 200 (fixed 2026-08-18, was hitting admin `/api/v1/settings` → 401) |
| `guests_cannot_update_settings` | Auth | PUT without token → 401 |
| `user_without_permission_cannot_update_settings` | Auth | PUT without `update-settings` → 403 |

### `tests/Feature/Settings/SettingsRegressionTest.php`

| Test | Type | Description |
|------|------|-------------|
| `getData_*` (7 tests) | Feature | `SettingService::getData()` caching per language, null not cached |
| `settings_can_be_read_after_update` | Feature | Read-after-update consistency |
| `options_are_cast_to_array` | Feature | options JSON cast |

### `tests/Feature/Currency/CurrencySelectionEnabledTest.php` (17 tests)

Covers `is_currency_selection_enabled()` defaults, enable/disable via admin settings, invalid values (`"not-a-boolean"` → 422, `2` → 422), cache clearing after update, and that toggling does not alter base/catalog currency codes.

> **Note (2026-08-18):** BUG-SETTING-ADMIN-006 is fixed — `currency_selection_enabled` validation was restored to `boolean`, so the JSON-boolean enable/disable/cache tests all pass again (verified: `tests/Feature/Currency` → 131 passed).

---

## Recommended Tests (gaps)

| # | Test Name | Type | Description |
|---|-----------|------|-------------|
| 1 | `fast_shipping_get_settings` | Feature | GET /fast-shipping/settings → 200 |
| 2 | `fast_shipping_update_settings` | Feature | PUT /fast-shipping/settings → 200 |
| 3 | `fast_shipping_cache_invalidation` | Feature | Update clears cache |
| 4 | `fast_shipping_validation` | Feature | Invalid duration_minutes → 422 |
| 5 | `fast_shipping_defaults` | Feature | Empty DB returns defaults (enabled: false, 120min, 0 fee) |
| 6 | `admin_settings_auth_check` | Feature | Admin GET /api/v1/settings without token → 401 |
| 7 | `admin_settings_permission_check` | Feature | Admin PUT /api/v1/settings without update-settings → 403 |
| 8 | `currency_selection_enabled_boolean` | Feature | PUT with JSON true/false works; `in:true,false` rejected |
| 9 | `minimum_order_amount_string_type` | Feature | GET response has `minimumOrderAmount` as string (e.g., `"50.00"`), not numeric |