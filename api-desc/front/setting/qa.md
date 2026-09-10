# Settings Module — QA Test Cases (Public API)

## Test Files

`tests/Feature/Settings/SettingsCrudTest.php` — Admin CRUD (not public)
`tests/Feature/Settings/SettingsValidationTest.php` — Admin validation (not public)
`tests/Feature/Settings/SettingsAuthenticationTest.php` — Admin auth (not public)
`tests/Feature/Settings/SettingsRegressionTest.php` — Admin regression (not public)

**No tests exist for the public `GET /api/v1/general/settings` endpoint.**

---

## API Functionality Tests

| # | Test | Description | Expected |
|---|------|-------------|----------|
| F1 | Fetch settings | GET /general/settings | 200, full settings object |
| F2 | Fetch with different locale | Accept-Language: ar | Translated fields in Arabic |
| F3 | Fetch with no DB record | Empty settings table | 200, null/empty fields |
| F4 | Settings include options | JSON options configured | 200, options as object |

---

## Response Structure Tests

| # | Test | Description | Expected |
|---|------|-------------|----------|
| S1 | Top-level keys | status, message, success, data | Correct keys |
| S2 | Settings object fields | All 18 fields present | Correct types |
| S3 | Translatable fields | site_name, site_desc, meta_desc, site_copy_right | Strings |
| S4 | Media fields | logo, favicon | URL strings or null |
| S5 | Social fields | facebook, instagram, linkedin, youtube | URL strings or null |
| S6 | Options | Arbitrary JSON | Object or null |
| S7 | minimumOrderAmount | Top-level float | 0 or configured value |

---

## Regression Tests

| # | Test | Description | Expected |
|---|------|-------------|----------|
| R1 | Settings after update | Change site_name via admin, fetch public | Updated value returned |
| R2 | Locale switching | Fetch en, then ar | Different translations |
| R3 | Media after upload | Upload logo via admin, fetch public | New logo URL |

---

## Performance Tests

| # | Test | Description | Expected |
|---|------|-------------|----------|
| P1 | Response time | Baseline | <100ms (no caching currently) |
| P2 | DB query count | 1 query (Settings::first()) | 1 query max |
| R4 | minimumOrderAmount after update | Change via admin PUT, verify public GET | Updated value returned |
