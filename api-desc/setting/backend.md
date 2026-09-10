# Settings Module — Backend Architecture (Admin API)

## Endpoints

| Method | URL | Auth | Permission | Purpose |
|--------|-----|------|------------|---------|
| GET | `/api/v1/general/settings` | Public | — | Public settings (`settings.front`) |
| GET | `/api/v1/settings` | Sanctum | `view-settings` | Fetch platform settings (admin) |
| PUT | `/api/v1/settings` | Sanctum | `update-settings` | Update platform settings |
| GET | `/api/v1/fast-shipping/settings` | Sanctum | `view-fast-shipping` | Fetch fast shipping settings |
| PUT | `/api/v1/fast-shipping/settings` | Sanctum | `update-fast-shipping` | Update fast shipping settings |

## Route Definitions

### Admin Routes (inside `Route::middleware(['auth:sanctum', 'throttle:admin'])` group)

**File:** `packages/marvel/src/Rest/Routes.php` (lines 114-118)

```php
Route::get('settings', [SettingsController::class, 'index']);             // line 114
Route::put('settings', [SettingsController::class, 'update']);            // line 115
Route::get('fast-shipping/settings', [FastShippingController::class, 'getSettings']);    // line 117
Route::put('fast-shipping/settings', [FastShippingController::class, 'updateSettings']); // line 118
```

### Public Routes (inside `Route::prefix('v1/general')->group()`)

**File:** `routes/api.php` (line 86)

```php
Route::get('settings', [SettingController::class, 'index'])->name('settings.front'); // routes/api.php:86
```

> **Note:** The public endpoint `GET /api/v1/general/settings` does not require Sanctum authentication; it uses the `throttle:public-api` middleware only. The admin endpoint `GET /api/v1/settings` requires `auth:sanctum` and `throttle:admin` with `view-settings` permission.

## Middleware

| Endpoint | Middleware |
|----------|-----------|
| GET /api/v1/general/settings | `throttle:public-api` only (no auth) |
| GET /api/v1/settings | `auth:sanctum`, `permission:view-settings` (via `SettingsController` constructor) |
| PUT /api/v1/settings | `auth:sanctum`, `permission:update-settings` |
| GET /api/v1/fast-shipping/settings | `auth:sanctum`, `permission:view-fast-shipping` |
| PUT /api/v1/fast-shipping/settings | `auth:sanctum`, `permission:update-fast-shipping` |

## Key Classes

| Class | Method | Responsibility |
|-------|--------|----------------|
| `SettingsController` | `index()` | Return settings singleton via SettingResource (cached under `settings` tag via `HasCache::remember`) |
| `SettingsController` | `update()` | Update settings fields via SettingsRequest; merges `currency_selection_enabled` into options, resets effective-currency memo, flushes settings tag |
| `FastShippingController` | `getSettings()` | Return fast shipping config from `options.fast_shipping` |
| `FastShippingController` | `updateSettings()` | Validate + merge into `options.fast_shipping`, clear cache |
| `FastShippingRepository` | `getSettings()` | Read from cache/DB, default values |
| `FastShippingRepository` | `updateSettings()` | Merge into options, `lockForUpdate` transaction, clear cache |
| `SettingResource` | `toArray()` | Transform settings for response (incl. top-level `currency_selection_enabled`, `tiktok`, `snapchat` as nullable, `minimumOrderAmount` as string) |

## Fast Shipping Data Flow

```
settings.options.fast_shipping
  ├── enabled            (bool, default false)
  ├── duration_minutes   (int,  default 120)
  ├── fee                (float, default 0)
  ├── start_hour         (string "H:i", default "08:00")
  └── end_hour           (string "H:i", default "22:00")
```

All stored inside the `settings` table `options` JSON column under the `fast_shipping` key.

## Caching

| Endpoint | Cache |
|----------|-------|
| GET /api/v1/general/settings | Cached under `settings` tag (`HasCache::remember('settings', md5(fullUrl), ...)`, TTL 4h) |
| GET /api/v1/fast-shipping/settings | `Cache::remember('fast_shipping_settings', 3600s)` |
| PUT /api/v1/settings | Flushes the `settings` tag (`flushTag(FrontendResource::SETTINGS->value)`) + resets `CurrencyService` effective-currency memo when `currency_selection_enabled` is present |
| PUT /api/v1/fast-shipping/settings | Clears `fast_shipping_settings` cache key |

The fast shipping update uses `lockForUpdate()` transaction to prevent race conditions.

## `minimumOrderAmount`

| Field | Source | Default | Type |
|-------|--------|---------|------|
| `minimumOrderAmount` | `settings.options.minimumOrderAmount` | 0 | **string** (e.g., `"50.00"`) |
| Top-level in response | `SettingResource` extracts via `$this->options['minimumOrderAmount'] ?? "0"` | — | string |
| Enforced in | `CheckoutRepository::verify()` — throws 400 if cart total < minimum (cast to decimal) | — | decimal |

> **Note:** The `minimumOrderAmount` value is stored and returned as a **string** (JSON decimal:2 cast). The checkout enforcement casts it to decimal for comparison.

## `currency_selection_enabled`

| Field | Source | Default | Type |
|-------|--------|---------|------|
| Top-level in response | `SettingResource`: `(bool) data_get($this->options, 'currency_selection_enabled', false)` | `false` | boolean |
| Stored in | `settings.options.currency_selection_enabled` (JSON boolean) | `false` | boolean (stored as `true`/`false`) |
| Seeder default | `SettingSeeder` sets `??= false` | `false` | — |
| Written via | `PUT /api/v1/settings` with `currency_selection_enabled` (`sometimes|boolean`; accepts `true/false/0/1/"0"/"1"`) — merged into options | — | — |
| Consumed by | `App\Services\Currency\CurrencyService::isCurrencySelectionEnabled()` / `getEffectiveCode()` | — | — |

When `true`, the storefront can honor a customer-selected currency (user preference or guest cookie). When `false` (default), the effective currency always resolves to the catalog code.

> **Note:** The `currency_selection_enabled` flag appears **both at the top level** of the API response **and** inside the `options` JSON object. When present in a PUT request, it is merged into `options` without dropping other keys.

## `tiktok` / `snapchat`

| Field | Source | Validation | Response Type |
|-------|--------|-----------|--------------|
| `tiktok` | `settings.tiktok` (nullable string) | `sometimes|url` on `PUT /api/v1/settings` | `string` or `null` |
| `snapchat` | `settings.snapchat` (nullable string) | `sometimes|url` on `PUT /api/v1/settings` | `string` or `null` |

Added to the `settings` table columns, the `Settings` model `$fillable`, the `SettingsController@update` allowlist, and the `SettingResource` response (admin + public). Omitting them on update preserves existing values; they are also `NULL`-safe on GET when not configured.

> **Note:** In the API response, `tiktok` and `snapchat` appear as `null` when not configured. They are accepted on `PUT /api/v1/settings` as `sometimes|url` validation.

## Image Upload

| Field | Storage | Validation |
|-------|---------|-----------|
| `logo` | `logo-setting` media collection | `sometimes|image`, `mimes:jpeg,png,jpg,gif,svg`, `max:2048` |
| `footer_logo` | `footer_logo-setting` media collection | `sometimes|image`, `mimes:jpeg,png,jpg,gif,svg`, `max:2048` |
| `favicon` | `favicon-setting` media collection | `sometimes|image`, `mimes:jpeg,png,jpg,gif,svg`, `max:2048` |

Uploaded via `SettingsController@update()` using `updateSingleImage()` trait method. Failed uploads throw `HttpException(422, __('message.ERROR.LOGO_UPLOAD_FAILED'))`.