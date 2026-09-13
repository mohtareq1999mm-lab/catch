# Test Coverage — Settings Module

> Admin `GET|PUT /api/v1/settings` (partial `sometimes`, `order_tax` + `footer_logo`) + public `GET /api/v1/general/settings` (`settings.front`, single-locale rendering). Verified 2026-09-13 vs `SettingsRequest` (`sometimes` per field).

## Existing Tests

### `tests/Feature/Settings/SettingsCrudTest.php`

| Test | Type | Description |
|------|------|-------------|
| `can_view_settings` | Feature | `GET /api/v1/general/settings` public (no auth) → `200` |
| `can_view_admin_settings` | Feature | `GET /api/v1/settings` admin (`auth:sanctum`,`view-settings`) → `200` |
| `can_update_settings` | Feature | `PUT /api/v1/settings` partial → `200` (now `sometimes`: missing `site_name` etc. preserves) |
| `settings_returns_expected_json_structure` | Structure | `GET /general/settings` public: `footer_logo` URL, `tiktok:null`, `site_name` single string, `minimumOrderAmount` string, `currency_selection_enabled` bool, `options:array` |
| `admin_settings_returns_expected_json_structure` | Structure | Admin `GET /settings`: `site_name` as `{ar,en}` objects, same `footer_logo`/`options` |
| `omitted_tiktok_and_snapchat_preserve_existing_values` | Feature | `PUT` without `tiktok`/`snapchat` preserves stored values (`sometimes`) |
| `omitted_currency_flag_preserves` | Feature | `PUT` without `currency_selection_enabled` leaves `options.currency_selection_enabled` untouched |
| `updated_tiktok_and_snapchat_are_returned_by_admin_and_website_endpoints` | Feature | `PUT tiktok/snapchat` urls → reflected on admin+public GETs |
| `can_update_order_tax_prefs` | Feature | `PUT order_tax_enabled:true + order_tax_rate:14` → `200` float persisted, `GET` shows |
| `can_upload_footer_logo` | Feature | `PUT footer_logo: file image` → `200` media via `updateSingleImage` |

### `tests/Feature/Settings/SettingsValidationTest.php`

| Test | Type | Description |
|------|------|-------------|
| `update_returns_422_with_invalid_email` | Validation | `site_email:"not-an-email"` → `422` |
| `update_returns_422_with_invalid_url` | Validation | `facebook:"not-a-url"` → `422` |
| `update_returns_422_with_invalid_tiktok_url` | Validation | `tiktok:"not-a-url"` (`sometimes\|url`) → `422` |
| `update_returns_422_with_invalid_snapchat_url` | Validation | `snapchat:"not-a-url"` → `422` |
| `update_returns_422_with_invalid_fast_shipping_value` | Validation | `fast_shipping_page_publish:2` (`in:0,1`) → `422` |
| `update_returns_422_with_invalid_currency_flag` | Validation | `currency_selection_enabled:2` or `"not-a-boolean"` (`sometimes\|boolean`) → `422` |
| `update_returns_422_with_invalid_order_tax_rate` | Validation | `order_tax_rate:150` (`nullable\|numeric\|0..100`) → `422` |
| `update_returns_422_with_invalid_logo_mime` | Validation | `logo: .txt` → `422` `LOGO_UPLOAD_FAILED` |
| `update_accepts_partial_without_site_name` | Validation | `PUT {phone:"+201..."}` without `site_name` → `200` (all `sometimes` — not `422`) |

> Note: older `update_returns_422_without_site_name/email/fast_shipping_page_publish` 422 cases were removed — those fields are now `sometimes` (partial update), not `required`. Tests above replace them.

### `tests/Feature/Settings/SettingsAuthenticationTest.php`

| Test | Type | Description |
|------|------|-------------|
| `guests_can_view_settings` | Auth | `GET /api/v1/general/settings` public → `200` (fixed 2026-08-18: was hitting admin `/settings` → `401`) |
| `admin_settings_requires_auth` | Auth | `GET /api/v1/settings` without token → `401` |
| `guests_cannot_update_settings` | Auth | `PUT /api/v1/settings` without token → `401` |
| `user_without_permission_cannot_update_settings` | Auth | `PUT` without `update-settings` → `403` |
| `user_without_view_cannot_view_admin_settings` | Auth | `GET /api/v1/settings` without `view-settings` → `403` |

### `tests/Feature/Settings/SettingsRegressionTest.php`

| Test | Type | Description |
|------|------|-------------|
| `getData_*` (7 tests) | Feature | `Settings::getData(lang)` per-locale `cached_settings_{lang}` 86400s, null not cached |
| `settings_can_be_read_after_update` | Feature | `PUT` then `GET` reflects (cache flushed via `flushTag(settings)`) |
| `options_are_cast_to_array` | Feature | `options` JSON `array` cast |
| `minimum_order_amount_string_cast` | Feature | `minimum_order_amount:decimal:2` → `minimumOrderAmount` string on `SettingResource` |
| `order_tax_prefs_persist` | Feature | `order_tax_enabled:boolean` + `order_tax_rate:float` round-trip |

### `tests/Feature/Currency/CurrencySelectionEnabledTest.php` (17 tests)

Covers `is_currency_selection_enabled()` defaults, `PUT` `sometimes|boolean` enable/disable (`true/false/0/1/"0"/"1"`), rejects `2`/`"not-a-boolean"` with `422`, cache `flushTag(settings)` + `forgetEffectiveCode()` clears memo, merge preserves `fast_shipping`, toggle does not change base/catalog codes.

> BUG-006 fixed — `in:true,false` restored to `boolean`; 131 Currency tests pass.

## Recommended Tests (gaps)

| # | Test Name | Type | Description |
|---|-----------|------|-------------|
| 1 | `fast_shipping_get_settings` | Feature | `GET /api/v1/fast-shipping/settings` with `view-fast-shipping` → `200` defaults |
| 2 | `fast_shipping_update_settings` | Feature | `PUT /fast-shipping/settings` valid → `200` + fast cache forgotten |
| 3 | `fast_shipping_cache_invalidation` | Feature | `PUT` then `GET` fresh (1h cache) |
| 4 | `fast_shipping_validation` | Feature | `duration_minutes: 0` / `fee:-1` / `start_hour:"99:99"` → `422` |
| 5 | `fast_shipping_defaults_when_empty` | Feature | Empty `options` returns defaults |
| 6 | `admin_settings_auth_check` | Feature | Admin `GET` without token → `401` (public is `GET /general/settings`) |
| 7 | `admin_settings_permission_check` | Feature | `PUT` without `update-settings` → `403` |
| 8 | `currency_selection_enabled_boolean` | Feature | `PUT` `true|false` JSON booleans work; `422` for `2` |
| 9 | `minimum_order_amount_string_type` | Feature | `GET` `minimumOrderAmount` string not numeric |
| 10 | `order_tax_rate_range` | Feature | `order_tax_rate: 14` → `200`; `101` → `422`; null → `200` |
| 11 | `footer_logo_upload_and_preserve` | Feature | `PUT footer_logo: file` → URL on `GET`; omit preserves |
| 12 | `partial_update_preserves_translatable` | Feature | `PUT {phone}` without `site_name` preserves `{ar,en}` objects |
