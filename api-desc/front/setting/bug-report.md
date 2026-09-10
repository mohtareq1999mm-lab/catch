# Bug Report — Settings Module (Public API)

---

## BUG-SETTING-001: No Caching in Public Settings Endpoint

**Severity:** Medium

**Component:** `app/Services/General/SettingService.php` (line 10)

**Description:** The `getSetting()` method calls `Settings::first()` directly on every request with no caching. The model has a commented-out `getData()` static method that would provide 24-hour caching, but it's not used by the public service.

**Code Location:** `app/Services/General/SettingService.php` — line 10

---

## BUG-SETTING-002: `Settings::getData()` Is Commented Out / Dead Code

**Severity:** Low

**Component:** `packages/marvel/src/Database/Models/Settings.php` (lines 47-63)

**Description:** The static `getData()` method in the Settings model is fully commented out. This method implemented read-through caching with 24-hour TTL, but it's inaccessible. The cache key pattern `cached_settings_{language}` and the `Cache::remember()` logic exist only as dead code.

---

## BUG-SETTING-003: No Tests for Public GET /general/settings

**Severity:** Medium

**Component:** `tests/Feature/Settings/`

**Description:** Existing test files cover admin CRUD (SettingsCrudTest, SettingsValidationTest), authentication (SettingsAuthenticationTest), and regression (SettingsRegressionTest), but none test the public `GET /api/v1/general/settings` endpoint under `Api\General\SettingController`.

---

## BUG-SETTING-004: Null Pointer Risk for `options` Field

**Severity:** Low

**Component:** `packages/marvel/src/Http/Resources/SettingResource.php` (line 33)

**Description:** `$this->options` has no null coalesce on the `options` field. `minimumOrderAmount` was added with `?? 0` fallback, but the parent `options` field itself still returns `null` if the DB value is null.

## FIX-SETTING-001: Added `minimumOrderAmount` to SettingResource

**Severity:** Medium
**Status:** FIXED (2026-07-21)

**Component:** `packages/marvel/src/Http/Resources/SettingResource.php`

**Description:** `minimumOrderAmount` was only accessible inside `options` JSON. Added as a top-level field:

```php
'minimumOrderAmount' => $this->options['minimumOrderAmount'] ?? 0,
```

**Related:** CheckoutRepository reads from `settings.options.minimumOrderAmount` to enforce minimum cart total (line 39, 61-63).
