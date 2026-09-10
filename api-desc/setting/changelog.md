# Settings Module — Changelog (Admin API)

## [1.2.0] — 2026-08-18

### Added
- **`tiktok` and `snapchat` social URL fields.** Added as nullable `settings` columns (`tiktok`, `snapchat`), model `$fillable` entries, `SettingsController@update` allowlist entries, `SettingResource` response fields (admin + public), and `sometimes|url` validation on `PUT /api/v1/settings`. Omitting them on update preserves existing values. Backed by `SettingsCrudTest` (preserve + expose) and `SettingsValidationTest` (invalid URL → 422).

### Fixed
- **BUG-SETTING-ADMIN-006:** `currency_selection_enabled` validation restored to `sometimes|boolean`. The interim `in:true,false` rule rejected raw JSON booleans (Laravel's `in` rule casts `true`→`"1"`, `false`→`""`), breaking 3 `CurrencySelectionEnabledTest` tests. `boolean` accepts `true/false/0/1/"0"/"1"` and still rejects `"not-a-boolean"` / `2`.
- **BUG-SETTING-ADMIN-007:** `guests_can_view_settings` now hits the public `GET /api/v1/general/settings` instead of the admin `GET /api/v1/settings` (which correctly returns 401 for guests).

### Test Run (2026-08-18, after fixes)
- `tests/Feature/Settings` — **26 passed** (Crud 5, Validation 8, Regression 10, Authentication 3)
- `tests/Feature/Currency` — **131 passed** (includes all `CurrencySelectionEnabledTest` cases)
- `php -l` clean on changed PHP files.

## [1.1.0] — 2026-08-12

### Changed
- `SettingResource` now exposes a top-level `currency_selection_enabled` boolean (`options.currency_selection_enabled`, default `false`).
- `PUT /api/v1/settings` accepts `currency_selection_enabled` (`sometimes|boolean`); when sent it is merged into `options` (preserving other keys), resets the `CurrencyService` effective-currency memo, and flushes the `settings` cache tag.
- Admin settings GET/PUT auth documentation corrected: `GET /api/v1/settings` requires `auth:sanctum` + `view-settings`; public reads use `GET /api/v1/general/settings`.
- `SettingSeeder` seeds `currency_selection_enabled ??= false`.

## [1.0.0] — 2026-07-21

### Added
- Admin API investigation documentation (`api-desc/setting/`)
- Settings endpoints: GET + PUT `/api/v1/settings`
- Fast shipping settings endpoints: GET + PUT `/api/v1/fast-shipping/settings`
- Fast shipping config cached (1 hour TTL) with cache invalidation on update
- Transaction-based update with `lockForUpdate()` to prevent race conditions
- `minimumOrderAmount` exposed as top-level field in SettingResource
- `minimumOrderAmount` enforced in CheckoutRepository (400 if cart total < minimum)
