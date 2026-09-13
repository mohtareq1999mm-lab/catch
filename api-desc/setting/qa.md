# Settings Module — QA Test Cases

> Covers admin `GET|PUT /api/v1/settings` (partial `sometimes`, `SettingsRequest`) + public `GET /api/v1/general/settings` (`settings.front`, single-locale rendering) + `order_tax` + `footer_logo`. Verified 2026-09-13.

## Test Files

- `tests/Feature/Settings/SettingsCrudTest.php` — GET/PUT, `tiktok`/`snapchat`/`footer_logo` preserve + expose, `order_tax`
- `tests/Feature/Settings/SettingsValidationTest.php` — `422` cases (incl. `tiktok/snapchat` URL, `currency_selection_enabled` boolean, `order_tax_rate` 0..100)
- `tests/Feature/Settings/SettingsAuthenticationTest.php` — auth/authorization (fixed 2026-08-18: `guests_can_view_settings` now hits public `/api/v1/general/settings`)
- `tests/Feature/Settings/SettingsRegressionTest.php` — caching (`HasCache` `settings` tag + `Setting::getData` locale cache) + read-after-update
- `tests/Feature/Currency/CurrencySelectionEnabledTest.php` — `currency_selection_enabled` flag (all passing after BUG-006 fix: `sometimes|boolean`)

## API Functionality Tests

| # | Test | Description | Expected |
|---|------|-------------|----------|
| F1 | `GET /api/v1/general/settings` (public) | No auth | `200` public shape: translatable as single locale string, `footer_logo` URL, `tiktok:null`, `minimumOrderAmount` string, `currency_selection_enabled` bool |
| F2 | `GET /api/v1/settings` (admin) | With `auth:sanctum` + `view-settings` | `200` admin shape: translatable `{ar,en}` objects, same `footer_logo`/`options` |
| F3 | `PUT /api/v1/settings` without auth | No token | `401` |
| F4 | `PUT /api/v1/settings` without permission | Token without `update-settings` | `403` |
| F5 | `PUT` invalid data | Bad email/url/boolean/rate | `422` `errors` per field |
| F6 | `GET /api/v1/fast-shipping/settings` | Fetch fast shipping slice | `200` `{enabled, duration_minutes, fee, start_hour, end_hour}` |
| F7 | `PUT /api/v1/fast-shipping/settings` | Update slice | `200` + `Cache::forget('fast_shipping_settings')` |
| F8 | `PUT /fast-shipping/settings` no auth | No token | `401` |
| F9 | `PUT fast-shipping` invalid `duration_minutes: 9999` | `>1440` | `422` |
| F10 | `GET /api/v1/settings` without auth | No token | `401` (public endpoint is `GET /general/settings`) |
| F11 | `PUT currency_selection_enabled` | `true|false|0|1|"0"|"1"` via admin `PUT` | `200` + reflected as top-level bool + `options.currency_selection_enabled` on next `GET` (admin+public) |
| F12 | `PUT tiktok/snapchat` valid URLs | `tiktok/snapchat: url` | `200` + reflected on both GETs |
| F13 | `PUT` invalid `tiktok`/`snapchat` URL | `"not-a-url"` | `422` |
| F14 | `PUT order_tax_enabled` + `order_tax_rate` | `order_tax_enabled:true`, `order_tax_rate:14` | `200` + persisted `boolean`/`float`, `422` for `order_tax_rate:150` |
| F15 | `PUT` partial (omit `site_name` etc.) | Only `{phone:"+201..."} ` | `200` `site_name` preserved (partial `sometimes` — not `422`) |
| F16 | `PUT` with `logo`/`footer_logo`/`favicon` | file `image` `mimes...+max:2048` | `200` media updated; bad mime/size → `422` `LOGO_UPLOAD_FAILED` etc. |

## Response Structure Tests

| # | Test | Description | Expected |
|---|------|-------------|----------|
| S1 | Public `GET` shape | `settings.front` | `site_name/site_desc/meta_desc/site_copy_right` as single locale string; `logo`/`footer_logo`/`favicon` as media URL (possibly empty), `tiktok/snapchat` `string|null`, `minimumOrderAmount` string, `currency_selection_enabled` bool, `options:array` |
| S2 | Admin `GET` shape | `GET /settings` admin | Same but translatable as `{ar,en}` objects |
| S3 | `minimumOrderAmount` | top-level | `"50.00"` string `decimal:2` (not numeric) |
| S4 | `currency_selection_enabled` | top-level | `false` default ↔ `options.currency_selection_enabled` mirrors; `PUT` merges into `options` preserving `fast_shipping` |
| S5 | `tiktok/snapchat` | nullable URLs | Null-safe when unset; `sometimes|url` rejects `"not-a-url"` |
| S6 | `footer_logo` | media URL | `getFirstMediaUrl('footer_logo-setting')` on both endpoints; `422` for bad image |
| S7 | `order_tax` | tax prefs | `order_tax_enabled` bool, `order_tax_rate` `float\|null` 0..100; `nullable|numeric` rejects `>100` |

## Regression / Cache

| # | Test | Description | Expected |
|---|------|-------------|----------|
| R1 | `PUT` → `GET /general/settings` | update `site_name`, fetch public | reflected with locale rendering |
| R2 | `PUT` → `GET /settings` | update `site_desc`, fetch admin | reflected as `{ar,en}` |
| R3 | `minimumOrderAmount` flow | `PUT minimum_order_amount:100`, `GET` + checkout verify | `minimumOrderAmount:"100.00"` enforced as decimal |
| R4 | Fast shipping cache | `PUT /fast-shipping/settings` then `GET` | fresh (1h cache forgotten) |
| R5 | `currency_selection_enabled` merge | `PUT options:{fast_shipping:{...}}` + flag | `options` preserves `fast_shipping` while flag updates, `CurrencyService::forgetEffectiveCode` called |
| R6 | `settings` tag shared | `PUT /settings` then `GET /general/settings` | public cache flushed (same `settings` tag) |
| R7 | `order_tax` flow | `PUT order_tax_enabled:true + order_tax_rate:14`, `GET` | persisted + reflected |

## Performance

| # | Test | Expected |
|---|------|----------|
| P1 | `GET /general/settings` baseline singleton `LIMIT 1` | `<100ms` (DB + `HasCache` tag 4h) |
| P2 | `GET /general/settings` cached (2nd hit same `md5(fullUrl)`) | cache HIT |
| P3 | `GET /settings` admin cached | same tag, `HasCache::remember` |
| P4 | `PUT` concurrency | `lockForUpdate` on fast-shipping prevents race; settings singleton `update` is atomic on one row |
