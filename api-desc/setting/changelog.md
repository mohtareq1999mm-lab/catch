# Settings Module — Changelog

## [1.2.1] — 2026-09-13 — Docs sync to current `SettingsRequest`/`SettingResource` + `order_tax` fields

### Changed

- **api.md**: aligned validation table to actual `SettingsRequest.php:27-58` — all 26 fields `sometimes` (partial `PUT` allowed), added `footer_logo: sometimes|image`, `order_tax_enabled: sometimes|boolean`, `order_tax_rate: nullable|numeric|0..100` (float), corrected `site_name`/`site_desc`/`meta_desc`/`site_copy_right` to `sometimes|array` + `* sometimes|string` (was documented as `required`), corrected `site_email`/social/`phone`/`fast_shipping_page_publish` to `sometimes` (was `required`), and noted `multipart/form-data` when uploading `logo/footer_logo/favicon`. Response examples now include `footer_logo` on both admin+public GETs.
- **README.md / backend.md / flow.md / frontend.md / database.md**: added `order_tax_enabled`/`order_tax_rate` columns & casts & validation, `footer_logo` media collection & upload docs, singleton fillable 21 cols, `sometimes` semantics (omit preserves), `footer_logo` URL in resource, and `CurrencyService::forgetEffectiveCode()` + `flushTag(settings)` shared-cache note.
- **qa.md / test-cases.md**: updated validation tests to reflect `sometimes` semantics (missing field → `200` partial update, not `422`); added `order_tax` validation & public vs admin locale rendering checks.

### Fixed

- Docs previously marked required-for-`PUT` fields as `required`; actual `SettingsRequest` is `sometimes` per field — docs now match code (partial update without `site_name` etc. succeeds).

## [1.2.0] — 2026-08-18

### Added

- `tiktok` / `snapchat` to `settings` table columns, `Settings` model `$fillable`, `SettingsController@update` allowlist, and `SettingResource` response (admin `{ar,en}` vs public single-locale both nullable)
- `footer_logo` media collection support
- `currency_selection_enabled` behavior (merge into `options` preserving `fast_shipping`, reset `CurrencyService` memo, flush `settings` tag)
- `minimum_order_amount` / `minimumOrderAmount` string handling

### Fixed

- `tests/Feature/Settings` — **26 passed** (Crud 5, Validation 8, Regression 10, Authentication 3) — fixed `guests_can_view_settings` to hit public `settings.front`
- `tests/Feature/Currency` — **131 passed** (includes `CurrencySelectionEnabledTest` 17 cases, boolean `422` for `2`/`"not-a-boolean"`)
- Cache & transaction correctness for fast shipping (`lockForUpdate`, 1h cache)

## [1.1.0] — 2026-08-12

### Changed

- `SettingResource` now renders `site_name/site_desc/meta_desc/site_copy_right` as `{ar,en}` on admin but single locale on `settings.front` via `routeIs` check
- Media URLs via `getFirstMediaUrl` (`logo-setting` etc.)

## [1.0.0] — 2026-07-21

### Added

- Admin `GET|PUT /api/v1/settings` + public `GET /api/v1/general/settings` + `GET|PUT /api/v1/fast-shipping/settings`
- `Settings` model `HasTranslations` + `InteractsWithMedia` + `options:array` cast
