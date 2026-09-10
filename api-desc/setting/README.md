# Settings Module — Admin API

## Overview

The Settings Admin module provides CRUD for platform-wide configuration: site info, SEO, social links, contact, media, and JSON options. Fast shipping settings are stored inside `options.fast_shipping` and managed via separate endpoints with caching.

The Settings API has **two distinct endpoint groups**:

1. **Public (unauthenticated):** `GET /api/v1/general/settings` — no auth required
2. **Admin (Sanctum authenticated):** `GET /api/v1/settings` — requires `auth:sanctum` + `view-settings`

Both endpoints return the same `SettingResource` shape, but with different translative field rendering and slightly different response field types.

## Key Files

| Layer | File |
|-------|------|
| Controller | `packages/marvel/src/Http/Controllers/SettingsController.php` |
| Controller | `packages/marvel/src/Http/Controllers/FastShippingController.php` |
| Repository | `packages/marvel/src/Database/Repositories/FastShippingRepository.php` |
| Resource | `Marvel\Http\Resources\SettingResource.php` |
| Model | `Marvel\Database\Models\Settings.php` |
| Routes | `packages/marvel/src/Rest/Routes.php` (lines 114-118 for admin; routes/api.php:86 for public) |
| Request | `packages/marvel/src/Http/Requests/SettingsRequest.php` |
| Currency Service | `app/Services/Currency/CurrencyService.php` (effective-currency gating via `currency_selection_enabled`) |
| Seeder | `database/seeders/SettingSeeder.php` (options defaults) |

## Routes

| Method | Endpoint | Auth | Permission | Purpose |
|--------|----------|------|------------|---------|
| GET | `/api/v1/general/settings` | Public | — | Public settings (`settings.front`); no auth, `throttle:public-api` only |
| GET | `/api/v1/settings` | Sanctum | `view-settings` | Fetch platform settings (admin) |
| PUT | `/api/v1/settings` | Sanctum | `update-settings` | Update platform settings |
| GET | `/api/v1/fast-shipping/settings` | Sanctum | `view-fast-shipping` | Fetch fast shipping config |
| PUT | `/api/v1/fast-shipping/settings` | Sanctum | `update-fast-shipping` | Update fast shipping config |

> **Note:** `GET /api/v1/general/settings` (route name `settings.front`) is **not** inside the `auth:sanctum` group. It uses the `throttle:public-api` middleware only. The admin `GET /api/v1/settings` sits inside the `auth:sanctum + throttle:admin` middleware group and requires `view-settings` permission.

## Options Keys

Platform JSON `options` includes (among others):

- `minimumOrderAmount` — top-level `minimumOrderAmount` in the response (string, e.g., `"50.00"`)
- `fast_shipping` — managed via the fast-shipping endpoints
- `currency_selection_enabled` — boolean (default `false`); exposed as top-level `currency_selection_enabled`. When `false`, the storefront effective currency always resolves to the catalog code; when `true`, customer-selected currency preference / guest cookie is honored.

> **Important:** The `currency_selection_enabled` flag appears **both at the top level** of the API response **and** inside the `options` JSON object. When present in a PUT request, it is merged into `options` without dropping other keys.

## Social Media Fields

Settings include `facebook`, `instagram`, `linkedin`, `youtube`, plus **`tiktok`** and **`snapchat`** (nullable URL strings). All six are exposed in the `SettingResource` response (admin + public) and accepted on `PUT /api/v1/settings` (`sometimes|url`).

> **Rendering difference:**
> - **Public** `GET /api/v1/general/settings`: translatable fields (`site_name`, `site_desc`, `meta_desc`, `site_copy_right`) returned as a **single locale string**; `tiktok` and `snapchat` returned as `null` when not configured
> - **Admin** `GET /api/v1/settings`: translatable fields returned as **`{ar, en}` objects**; `tiktok` and `snapchat` returned as `null` when not configured

## Dependencies

- **Spatie Translatable** (`HasTranslations`) — bilingual site_name, site_desc, meta_desc, copyright
- **Spatie Media Library** (`InteractsWithMedia`) — logo, favicon
- **SettingResource** — response transformation (renders translatable fields differently based on route)
- **Cache** — fast shipping settings cached for 1 hour; settings cached for 4h under `settings` tag
- **LockForUpdate** — prevents race conditions on settings update

> **Note on field types:** The API response returns `minimumOrderAmount` as a **string** (e.g., `"50.00"`), not a numeric float. The `tiktok` and `snapchat` fields are **nullable strings** (return `null` when not configured).

## Image Upload

| Field | Storage | Validation |
|-------|---------|-----------|
| `logo` | `logo-setting` media collection | `sometimes|image`, `mimes:jpeg,png,jpg,gif,svg`, `max:2048` |
| `footer_logo` | `footer_logo-setting` media collection | `sometimes|image`, `mimes:jpeg,png,jpg,gif,svg`, `max:2048` |
| `favicon` | `favicon-setting` media collection | `sometimes|image`, `mimes:jpeg,png,jpg,gif,svg`, `max:2048` |

Uploaded via `SettingsController@update()` using `updateSingleImage()` trait method. Failed uploads throw `HttpException(422, __('message.ERROR.LOGO_UPLOAD_FAILED'))`.