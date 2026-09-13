# Settings Module — Admin + Storefront API

## Overview

Settings singleton (`settings` table, one row) holds site identity, SEO, contact, social links, media, `options` JSON (`minimumOrderAmount`, `currency_selection_enabled`, `fast_shipping`, tax prefs), and flags. Served by **two groups**: public `GET /api/v1/general/settings` (`settings.front`, no auth, `throttle:public-api`) and admin `GET|PUT /api/v1/settings` (`auth:sanctum`, `throttle:admin`, permissions `view-settings`/`update-settings`). Fast shipping subset (`options.fast_shipping`) has dedicated `GET|PUT /api/v1/fast-shipping/settings` (cached 1h). Media via Spatie MediaLibrary (`logo`,`footer_logo`,`favicon`).

Updated 2026-09-13 to match `SettingsRequest` (`sometimes` per field, `order_tax_enabled: sometimes|boolean`, `order_tax_rate: nullable|numeric|0..100`, `footer_logo`) + `Settings` model fillable/casts + `SettingResource` admin `{ar,en}` vs public single-locale rendering.

## Key Files

| Layer | File | Notes |
|-------|------|-------|
| Controller (admin) | `packages/marvel/src/Http/Controllers/SettingsController.php` | `index` (`permission:view-settings`), `update` (`permission:update-settings`) — merges `currency_selection_enabled` into `options`, `forgetEffectiveCode()`, `updateSingleImage` for `logo/footer_logo/favicon`, `flushTag(settings)`, `destroy` throws `ACTION_NOT_VALID` |
| Controller (storefront) | `app/Http/Controllers/Api/General/SettingController.php` | `index` → `SettingService::getSetting()` + `HasCache::remember(FrontendResource::SETTINGS, md5(fullUrl))` → `SettingResource` |
| Service | `app/Services/General/SettingService.php` | `getSetting(): Settings::first()` singleton |
| Repository | `packages/marvel/src/Database/Repositories/SettingsRepository.php` | (fast-shipping `getSettings`/`updateSettings` with `lockForUpdate` + cache) |
| Resource | `Marvel\Http\Resources\SettingResource.php` | `toArray`: `routeIs('settings.front')` → single locale string vs `{ar,en}` objects; `logo/footer_logo/favicon` via `getFirstMediaUrl('*-setting')`; `minimumOrderAmount` string from `minimum_order_amount:decimal:2`; `currency_selection_enabled` bool from `options`; `options ?? null` |
| Model | `Marvel\Database\Models\Settings.php` | `HasTranslations(site_name,site_desc,meta_desc,site_copy_right)`, `InteractsWithMedia`, `$fillable` 21 cols inc `tiktok,snapchat,minimum_order_amount,order_tax_enabled,order_tax_rate`, `$casts: options:array, minimum_order_amount:decimal:2, order_tax_enabled:boolean, order_tax_rate:float` |
| Request | `packages/marvel/src/Http/Requests/SettingsRequest.php` | `authorize:true`, `rules()` all `sometimes` (partial update), `tiktok/snapchat: sometimes|url`, `currency_selection_enabled: sometimes|boolean`, `order_tax_rate: nullable|numeric|0..100`, `options: sometimes|array` |
| Routes (admin) | `packages/marvel/src/Rest/Routes.php:118-119,121-122` | `GET settings`/`PUT settings` + `GET|PUT fast-shipping/settings` inside `auth:sanctum,throttle:admin` |
| Routes (public) | `routes/api.php:92` | `GET settings` → `SettingController@index` `name('settings.front')` inside `prefix v1/general` + `throttle:public-api` |
| Currency | `app/Services/Currency/CurrencyService.php` | `isCurrencySelectionEnabled()` / `getEffectiveCode()` / `forgetEffectiveCode()` gated by `options.currency_selection_enabled` |
| Seeder | `database/seeders/SettingSeeder.php` | `options.currency_selection_enabled ??= false`, `options.fast_shipping` defaults |

## Routes

| Method | Endpoint | Auth | Permission | Purpose |
|--------|----------|------|------------|---------|
| `GET` | `/api/v1/general/settings` | Public | — | Public settings (`settings.front`) — `throttle:public-api`, no auth, cached per full URL under `settings` tag |
| `GET` | `/api/v1/settings` | Sanctum | `view-settings` | Admin fetch — `auth:sanctum`, `throttle:admin`, cached under `settings` tag |
| `PUT` | `/api/v1/settings` | Sanctum | `update-settings` | Admin partial update — `SettingsRequest` `sometimes`, media via multipart, merges `currency_selection_enabled` into `options`, flushes cache |
| `GET` | `/api/v1/fast-shipping/settings` | Sanctum | `view-fast-shipping` | Fast shipping config from `options.fast_shipping`, cached 1h |
| `PUT` | `/api/v1/fast-shipping/settings` | Sanctum | `update-fast-shipping` | Merge into `options.fast_shipping`, `lockForUpdate` + forget cache |

## Options Keys

JSON `settings.options` (cast `array`) — exposed verbatim as `options` plus top-level mirrors:

| Key | Type | Default | Purpose |
|-----|------|---------|---------|
| `minimumOrderAmount` | string (`decimal:2`) | `"0.00"` | Top-level mirror `minimumOrderAmount` in resource; enforced in `CheckoutRepository::verify()` (cast decimal) |
| `currency_selection_enabled` | boolean | `false` | Top-level mirror; `CurrencyService::isCurrencySelectionEnabled()` gate. `PUT` with flag merges into `options` without dropping `fast_shipping` |
| `fast_shipping` | object | `{enabled:false, duration_minutes:120, fee:0, start_hour:"08:00", end_hour:"22:00"}` | Managed via `fast-shipping/settings` endpoints |
| `currency`, `base_currency_code`, `catalog_currency_code` | string | `"USD"` | Catalog vs base currency codes (derived) |
| `order_tax_enabled`, `order_tax_rate` | boolean, float 0..100 | `false`, `null` | Also stored as top-level columns `order_tax_enabled`/`order_tax_rate` on `settings` row; surfaced via `GET` when needed |
| other keys | — | — | preserved across `currency_selection_enabled` merge (`array_merge`) |

## Social Media Fields

`facebook`, `instagram`, `linkedin`, `youtube`, `promotion_video_url`, `tiktok`, `snapchat` — all `sometimes|url` (nullable). Returned as `null` when empty. Added as `settings` columns, `Settings` `$fillable`, `SettingsController@update` allowlist (via `only`), `SettingResource` response, and validated on `PUT`.

> Rendering diff: public `settings.front` → `site_name/site_desc/meta_desc/site_copy_right` as **single locale string** (`getTranslation(field, locale)`); admin → **`{ar,en}` objects**. Both expose `footer_logo` + `tiktok/snapchat` + `minimumOrderAmount` + `currency_selection_enabled`.

## Dependencies

- **Spatie Translatable** (`HasTranslations`) — bilingual `site_name,site_desc,meta_desc,site_copy_right`
- **Spatie MediaLibrary** (`InteractsWithMedia`) — `logo`/`footer_logo`/`favicon` collections `*-setting`
- **SettingResource** — dual rendering per `request()->routeIs('settings.front')`
- **HasCache** + `FrontendResource::SETTINGS` tag — 4h TTL shared by admin+public `GET` under `md5(fullUrl)` key; `PUT` flushes via `flushTag`; fast-shipping separate `fast_shipping_settings` 1h cache
- **CurrencyService** — `forgetEffectiveCode()` on `currency_selection_enabled` toggle
- **LockForUpdate** — fast-shipping update transaction

## Image Upload

| Field | Media collection | Validation | On failure |
|-------|-----------------|------------|------------|
| `logo` | `logo-setting` | `sometimes|image|mimes:jpeg,png,jpg,gif,svg|max:2048` | `HttpException 422 message.ERROR.LOGO_UPLOAD_FAILED` |
| `footer_logo` | `footer_logo-setting` | `sometimes|image|mimes:jpeg,png,jpg,gif,svg|max:2048` | `HttpException 422 message.ERROR.FOOTER_LOGO_UPLOAD_FAILED` |
| `favicon` | `favicon-setting` | `sometimes|image|mimes:jpeg,png,jpg,gif,svg|max:2048` | `HttpException 422 message.ERROR.FAVICON_UPLOAD_FAILED` |

Uploaded in `SettingsController@update` via `MediaManager::updateSingleImage($request,'logo',$settings,'logo-setting','settings')` etc. Only processed when `has('logo')` etc.
