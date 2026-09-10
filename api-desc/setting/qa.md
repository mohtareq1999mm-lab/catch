# Settings Module — QA Test Cases (Admin API)

## Test Files

Existing feature tests:
- `tests/Feature/Settings/SettingsCrudTest.php` — GET/PUT, tiktok/snapchat preserve + expose
- `tests/Feature/Settings/SettingsValidationTest.php` — 422 cases (incl. tiktok/snapchat URL)
- `tests/Feature/Settings/SettingsAuthenticationTest.php` — auth/authorization (fixed 2026-08-18: `guests_can_view_settings` now hits public `/api/v1/general/settings`)
- `tests/Feature/Settings/SettingsRegressionTest.php` — caching + read-after-update
- `tests/Feature/Currency/CurrencySelectionEnabledTest.php` — currency flag (all passing after BUG-SETTING-ADMIN-006 fix)

---

## API Functionality Tests

| # | Test | Description | Expected |
|---|------|-------------|----------|
| F1 | GET public settings (`/api/v1/general/settings`) | No auth | 200, full settings object |
| F2 | GET admin settings (with token) | With Sanctum token | 200, full settings object |
| F3 | PUT settings without auth | No token | 401 |
| F4 | PUT settings without permission | Token but no `update-settings` | 403 |
| F5 | PUT settings invalid data | Wrong field types | 422 |
| F6 | GET fast shipping settings | Fetch fast shipping config | 200, config object |
| F7 | PUT fast shipping settings | Update config | 200, success message |
| F8 | PUT fast shipping no auth | No token | 401 |
| F9 | PUT fast shipping invalid duration | `duration_minutes: 9999` | 422 |
| F10 | GET admin settings without auth | No token | 401 |
| F11 | PUT `currency_selection_enabled` | Set boolean via admin settings | 200, flag reflected in GET |
| F12 | PUT tiktok/snapchat | Valid URLs | 200, reflected in admin + public GET |
| F13 | PUT invalid tiktok/snapchat URL | `"not-a-url"` | 422 |

---

## Response Structure Tests

| # | Test | Description | Expected |
|---|------|-------------|----------|
| S1 | GET public settings structure | All fields present | Correct types; translatable fields as single locale string; `tiktok: null`, `snapchat: null`; `minimumOrderAmount` as string |
| S2 | GET admin settings structure | All fields present | Correct types; translatable fields as `{ar, en}` objects; `tiktok: null`, `snapchat: null`; `minimumOrderAmount` as string |
| S3 | `minimumOrderAmount` present | Top-level string | `"50.00"` or configured string value |
| S4 | `currency_selection_enabled` present | Top-level boolean | `false` default, matches `options.currency_selection_enabled` |
| S5 | tiktok / snapchat present | URL strings or null | Null-safe when not configured; URL validated on PUT |

---

## Regression Tests

| # | Test | Description | Expected |
|---|------|-------------|----------|
| R1 | PUT then GET public settings | Update value, fetch public GET | Updated value reflected (locale-aware) |
| R2 | PUT then GET admin settings | Update value, fetch admin GET | Updated value reflected (`{ar, en}` objects) |
| R3 | `minimumOrderAmount` flow | Set via PUT, verify GET + checkout | Enforced correctly (string cast to decimal) |
| R4 | Fast shipping cache | Update settings, immediate GET | Fresh data (cache cleared) |
| R5 | `currency_selection_enabled` flow | PUT flag, then public GET | Bool reflected + options merge preserved |

---

## Performance Tests

| # | Test | Description | Expected |
|---|------|-------------|----------|
| P1 | GET public settings response | Baseline | <100ms |
| P2 | GET public settings cached | After first request | Cache HIT |
| P3 | PUT settings transaction | Concurrent updates | `lockForUpdate` prevents races |