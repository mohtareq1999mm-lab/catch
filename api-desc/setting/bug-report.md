# Bug Report — Settings Module (Admin API)

---

## BUG-SETTING-ADMIN-006: `currency_selection_enabled` `in:true,false` Rejects JSON Booleans

**Severity:** High

**Component:** `packages/marvel/src/Http/Requests/SettingsRequest.php`

**Description:** The rule was changed from `sometimes|boolean` to `sometimes|in:true,false`. Laravel's `in` rule casts the submitted value to a string (`validateIn` → `in_array((string) $value, $parameters)`), so a JSON boolean `true` becomes `"1"` and `false` becomes `""` — neither matches `"true"`/`"false"`. Result: clients sending `currency_selection_enabled: true|false` get **422**.

**Affected:** `tests/Feature/Currency/CurrencySelectionEnabledTest.php` — `admin_can_enable_currency_selection`, `admin_can_disable_currency_selection`, `settings_cache_is_cleared_after_updating_currency_selection` (failed; tests send JSON booleans, which is the natural client contract).

**Fix (2026-08-18):** Restored `['sometimes', 'boolean']` in `SettingsRequest.php:53`. `boolean` accepts `true/false/0/1/"0"/"1"` and still rejects `"not-a-boolean"` and `2`. All 3 affected tests now pass (verified: `tests/Feature/Currency` → 131 passed; `tests/Feature/Settings` → 26 passed).

**Status:** **RESOLVED.**

---

## BUG-SETTING-ADMIN-007: `guests_can_view_settings` Hits the Wrong Endpoint

**Severity:** Medium

**Component:** `tests/Feature/Settings/SettingsAuthenticationTest.php` (line 38)

**Description:** The test called `GET /api/v1/settings` (the **admin** endpoint inside the `auth:sanctum` group) but asserted `assertOk()` expecting 200. Unauthenticated access to `/api/v1/settings` correctly returns **401**. The public endpoint is `GET /api/v1/general/settings` (`settings.front`).

**Affected:** `guests_can_view_settings` — failed (401).

**Fix (2026-08-18):** Changed the request to `getJson('/api/v1/general/settings')`. Test now passes (verified).

**Status:** **RESOLVED.**

---

## BUG-SETTING-ADMIN-001: No Tests for Admin Settings Endpoints

**Severity:** Medium

**Description:** No feature tests exist for `PUT /api/v1/settings`, `GET /api/v1/fast-shipping/settings`, or `PUT /api/v1/fast-shipping/settings`.

**Status:** **PARTIALLY RESOLVED.** `SettingsCrudTest`, `SettingsValidationTest`, `SettingsAuthenticationTest`, `SettingsRegressionTest`, and `CurrencySelectionEnabledTest` now cover `GET/PUT /settings` and auth. The **fast-shipping** GET/PUT endpoints still lack direct tests.

---

## BUG-SETTING-ADMIN-002: `PUT /api/v1/settings` Replaces Entire `options` JSON

**Severity:** Medium

**Component:** `packages/marvel/src/Http/Controllers/SettingsController.php` (line 110)

**Description:** `$settings->fill($request->only('options'))` replaces the entire `options` JSON. If the frontend sends only `{"options": {"minimumOrderAmount": 100}}`, all other options (like `fast_shipping`) are lost.

**Suggested Fix:** Merge new options into existing:
```php
$existingOptions = $settings->options ?? [];
$newOptions = $request->input('options', []);
$settings->options = array_merge($existingOptions, $newOptions);
```

Compare with `FastShippingRepository::updateSettings()` which correctly uses:
```php
$options[self::SETTINGS_KEY] = array_merge($this->defaults(), $data);
```

**Status:** **PARTIALLY RESOLVED.** The wholesale-replace behavior remains when the client sends a full `options` object, but the `currency_selection_enabled` flag added by the currency feature is now **merged** into `options` (it does not drop other keys). Any future fix should extend the same merge approach to the whole `options` payload.

---

## BUG-SETTING-ADMIN-004: Admin `GET /api/v1/settings` Auth Status

**Severity:** Low

**Component:** `packages/marvel/src/Rest/Routes.php` (lines 114-115)

**Description:** Docs previously described `GET /api/v1/settings` as public. The route actually sits inside the `auth:sanctum` + `throttle:admin` group, so unauthenticated callers receive 401.

**Status:** Documentation corrected (no code change). Consumers should use the public `GET /api/v1/general/settings` endpoint (`settings.front`).

---

## BUG-SETTING-ADMIN-005: `currency_selection_enabled` Response Exposure

**Severity:** Low

**Component:** `packages/marvel/src/Http/Resources/SettingResource.php`, `packages/marvel/src/Http/Requests/SettingsRequest.php`

**Description:** The `currency_selection_enabled` option was stored in settings but not exposed in the settings API response nor accepted on `PUT /api/v1/settings`.

**Status:** **RESOLVED.** `SettingResource` now returns top-level `currency_selection_enabled` (bool); `SettingsRequest` accepts `currency_selection_enabled => sometimes|boolean`; `SettingsController::update()` merges it into `options`, resets the effective-currency memo and flushes the `settings` tag.

---

## BUG-SETTING-ADMIN-003: `update-settings` Permission Not Listed in Enums

**Severity:** Low

**Description:** Need to verify that `update-settings`, `view-fast-shipping`, and `update-fast-shipping` permissions exist in the `Permission` enum.
