# API Reference — Settings Module

> Covers **Marvel admin** `GET /api/v1/settings` + `PUT /api/v1/settings` (`packages/marvel/src/Rest/Routes.php:118-119`, `Marvel\Http\Controllers\SettingsController`) and **App storefront** `GET /api/v1/general/settings` (`routes/api.php:92`, `App\Http\Controllers\Api\General\SettingController` — `settings.front`). Verified 2026-09-13 against `SettingsRequest`, `SettingResource`, `Settings` model.

---

### GET /api/v1/settings (Admin)

Fetch platform settings. Requires `auth:sanctum` + `permission:view-settings`.

**Route:** `Route::get('settings', [SettingsController::class,'index'])` — `packages/marvel/src/Rest/Routes.php:118` inside `Route::middleware(['auth:sanctum','throttle:admin'])`
**Controller:** `Marvel\Http\Controllers\SettingsController.php:32-38` (`__construct` wires `permission:view-settings` for `index`)
**Cache:** `HasCache::remember(FrontendResource::SETTINGS->value, md5($request->fullUrl()), Settings::first())`

**Headers:** `Authorization: Bearer <sanctum-token>`, `Accept: application/json`, `Accept-Language: ar|en`

**Response 200 — `Marvel\Http\Resources\SettingResource` (admin: translatable as `{ar,en}`):**
```json
{
    "status": 200,
    "message": "Data fetched successfully",
    "success": true,
    "data": {
        "site_name": {"ar": "موقعي", "en": "My Site"},
        "site_desc": {"ar": "...", "en": "..."},
        "meta_desc": {"ar": "...", "en": "..."},
        "site_copy_right": {"ar": "...", "en": "..."},
        "logo": "https://cdn.example.com/storage/logo-setting/abc.jpg",
        "footer_logo": "https://cdn.example.com/storage/footer_logo-setting/def.jpg",
        "favicon": "https://cdn.example.com/storage/favicon-setting/ghi.jpg",
        "site_email": "info@example.com",
        "email_support": "support@example.com",
        "facebook": "https://facebook.com/mywebsite",
        "instagram": "https://instagram.com/mywebsite",
        "linkedin": "https://linkedin.com/company/mywebsite",
        "promotion_video_url": "https://www.youtube.com/watch?v=dQw4w9WgXcQ",
        "youtube": "https://youtube.com/@mywebsite",
        "tiktok": null,
        "snapchat": null,
        "phone": "+201001234567",
        "fast_shipping_page_publish": 1,
        "minimumOrderAmount": "50.00",
        "order_tax_enabled": true,
        "order_tax_rate": 14.0,
        "currency_selection_enabled": false,
        "options": {
            "minimumOrderAmount": "50.00",
            "currency": "USD",
            "base_currency_code": "USD",
            "catalog_currency_code": "USD",
            "currency_selection_enabled": false,
            "fast_shipping": {"enabled": true, "duration_minutes": 120, "fee": 30, "start_hour": "08:00", "end_hour": "22:00"}
        }
    }
}
```

> **Translatable rendering:** admin `request()->routeIs('settings.front')` is false → `site_name/site_desc/meta_desc/site_copy_right` returned as `{ar,en}` via `getTranslation(field,'ar')` + `getTranslation(field,'en')`. Media fields `logo/footer_logo/favicon` are `getFirstMediaUrl('*-setting')` (empty string when no media). `minimumOrderAmount` is string `decimal:2` from `minimum_order_amount`. `order_tax_enabled` is `bool` cast, `order_tax_rate` is `float|null` (`nullable|numeric 0..100`) — fixed 2026-09-13 (was missing from `SettingResource`, now `packages/marvel/src/Http/Resources/SettingResource.php:44-45`). `currency_selection_enabled` is top-level `bool data_get(options,'currency_selection_enabled',false)` plus duplicate inside `options`.

**Error:** `401` no token, `403` missing `view-settings`.

---

### PUT /api/v1/settings (Admin)

Update platform settings (partial update). Requires `auth:sanctum` + `permission:update-settings`.

**Route:** `Route::put('settings', [SettingsController::class,'update'])` — `packages/marvel/src/Rest/Routes.php:119`
**Controller:** `Marvel\Http\Controllers\SettingsController.php:42-100`
**Request:** `Marvel\Http\Requests\SettingsRequest` (all `sometimes` — partial update allowed)

**Header:** `Authorization: Bearer <sanctum-token>`, `Content-Type: multipart/form-data` when uploading `logo/footer_logo/favicon` (files), otherwise `application/json`

**Request Body — JSON example (partial, any subset allowed):**
```json
{
    "site_name": {"en": "New Name", "ar": "اسم جديد"},
    "site_desc": {"en": "Description", "ar": "الوصف"},
    "meta_desc": {"en": "Meta", "ar": "الوصف التعريفي"},
    "site_copy_right": {"en": "Copyright 2026", "ar": "حقوق 2026"},
    "site_email": "admin@example.com",
    "email_support": "support@example.com",
    "facebook": "https://facebook.com/...",
    "instagram": "https://instagram.com/...",
    "linkedin": "https://linkedin.com/...",
    "youtube": "https://youtube.com/...",
    "tiktok": "https://tiktok.com/@mywebsite",
    "snapchat": "https://snapchat.com/@mywebsite",
    "phone": "+201001234567",
    "fast_shipping_page_publish": "1",
    "currency_selection_enabled": true,
    "minimum_order_amount": 100,
    "order_tax_enabled": true,
    "order_tax_rate": 14.0,
    "options": {
        "minimumOrderAmount": 100,
        "fast_shipping": {"enabled": true, "duration_minutes": 120, "fee": 0, "start_hour": "08:00", "end_hour": "22:00"}
    }
}
```

**Media (multipart):** fields `logo`, `footer_logo`, `favicon` as `file` (`image`, `mimes:jpeg,png,jpg,gif,svg`, `max:2048`). Handled via `MediaManager::updateSingleImage($request,'logo',$settings,'logo-setting','settings')` etc. Failure throws `HttpException(422, __('message.ERROR.LOGO_UPLOAD_FAILED'))` etc.

**Validation — `SettingsRequest::rules()` (`packages/marvel/src/Http/Requests/SettingsRequest.php:27-58`):**

| Field | Rules | Note |
|-------|-------|------|
| `site_name` | `sometimes|array` | |
| `site_name.*` | `sometimes|string|min:3|max:200` | per locale |
| `site_desc` | `sometimes|array` | |
| `site_desc.*` | `sometimes|string|min:3|max:2000` | |
| `meta_desc` | `sometimes|array` | |
| `meta_desc.*` | `sometimes|string|min:3|max:2000` | |
| `site_copy_right` | `sometimes|array` | |
| `site_copy_right.*` | `sometimes|string|min:3|max:200` | |
| `logo` | `sometimes|image|mimes:jpeg,png,jpg,gif,svg|max:2048` | file |
| `footer_logo` | `sometimes|image|mimes:jpeg,png,jpg,gif,svg|max:2048` | file |
| `favicon` | `sometimes|image|mimes:jpeg,png,jpg,gif,svg|max:2048` | file |
| `site_email` | `sometimes|email` | |
| `email_support` | `sometimes|email` | |
| `facebook` | `sometimes|url` | |
| `instagram` | `sometimes|url` | |
| `linkedin` | `sometimes|url` | |
| `promotion_video_url` | `sometimes|url` | |
| `youtube` | `sometimes|url` | |
| `tiktok` | `sometimes|url` | nullable string |
| `snapchat` | `sometimes|url` | nullable string |
| `phone` | `sometimes|string` | |
| `fast_shipping_page_publish` | `sometimes|in:0,1` | string/int |
| `minimum_order_amount` | `sometimes|numeric|min:0` | stored `decimal:2`, returned as `minimumOrderAmount` string |
| `currency_selection_enabled` | `sometimes|boolean` | `true/false/0/1/"0"/"1"` → `422` for `2`/`"not-a-boolean"` |
| `order_tax_enabled` | `sometimes|boolean` | |
| `order_tax_rate` | `nullable|numeric|min:0|max:100` | float, requires `order_tax_enabled` in practice |
| `options` | `sometimes|array` | merged, preserves `fast_shipping` etc. |

> **All fields are `sometimes`** — `PUT` is partial-update. Omitting `currency_selection_enabled` leaves stored value untouched; when present it is merged into `settings.options` (`array_merge($settings->options ?? [], $data['options'] ?? [])` + `$options['currency_selection_enabled']=boolean`) per `SettingsController.php:62-67`, then `app(CurrencyService::class)->forgetEffectiveCode()` (`SettingsController.php:71`) and cache tag flushed. Failed media upload → `422` with `message.ERROR.*_UPLOAD_FAILED`.

**Controller flow (`SettingsController::update`):**
1. `Settings::first()` singleton
2. `$data=$request->only([...16 keys: site_name…options, minimum_order_amount, order_tax_enabled, order_tax_rate])`
3. If `has('currency_selection_enabled')` → merge into `options` + `boolean` cast
4. `$settings->update($data)` (fillable `Settings.php:14-27` + casts `options:array, minimum_order_amount:decimal:2, order_tax_enabled:boolean, order_tax_rate:float`)
5. If currency flag present → `CurrencyService::forgetEffectiveCode()` clears memo
6. For each of `logo/footer_logo/favicon` if `has` → `updateSingleImage` or throw `HttpException 422`
7. `flushTag(FrontendResource::SETTINGS->value)` clears `settings` tag (4h TTL)
8. `SettingResource::make(Settings::first())` → `200`

**Response 200:**
```json
{
    "status": 200,
    "message": "Settings updated successfully",
    "success": true,
    "data": { "site_name": {"ar":"...","en":"..."}, "logo": "...", "minimumOrderAmount": "100.00", "currency_selection_enabled": true, "options": {...} }
}
```

**Errors:** `401` no token, `403` missing `update-settings`, `422` validation (e.g., `tiktok:"not-a-url"` → `{"tiktok":["The tiktok format is invalid."]}`, `currency_selection_enabled:2` → `{"currency_selection_enabled":["The currency selection enabled field must be true or false."]}`, oversized image, bad `order_tax_rate>100`).

---

### GET /api/v1/general/settings (Public)

Fetch platform settings — **no authentication**.

**Route:** `Route::get('settings', [SettingController::class,'index'])->name('settings.front')` — `routes/api.php:92` inside `Route::prefix('v1/general')->middleware(['api','throttle:public-api'])`
**Controller:** `App\Http\Controllers\Api\General\SettingController::index` (`app/Http/Controllers/Api/General/SettingController.php:18-25`) — `SettingService::getSetting()` → `HasCache::remember(FrontendResource::SETTINGS->value, md5(fullUrl), $setting)` → `SettingResource`
**Service:** `App\Services\General\SettingService::getSetting()` → `Settings::first()` (singleton, no channel filter)

**Response 200 — same `SettingResource` but public rendering (`routeIs('settings.front')` true → translatable as single locale string):**
```json
{
    "status": 200,
    "message": "تم جلب البيانات بنجاح",
    "success": true,
    "data": {
        "site_name": "موقعي",
        "site_desc": "هذا هو وصف الموقع.",
        "meta_desc": "الوصف التعريفي للموقع.",
        "site_copy_right": "© 2026 جميع الحقوق محفوظة.",
        "logo": "https://cdn.example.com/storage/logo-setting/abc.jpg",
        "footer_logo": "https://cdn.example.com/storage/footer_logo-setting/def.jpg",
        "favicon": "https://cdn.example.com/storage/favicon-setting/ghi.jpg",
        "site_email": "info@example.com",
        "email_support": "support@example.com",
        "facebook": "https://facebook.com/mywebsite",
        "instagram": "https://instagram.com/mywebsite",
        "linkedin": "https://linkedin.com/company/mywebsite",
        "promotion_video_url": "https://www.youtube.com/watch?v=dQw4w9WgXcQ",
        "youtube": "https://youtube.com/@mywebsite",
        "tiktok": null,
        "snapchat": null,
        "phone": "+201001234567",
        "fast_shipping_page_publish": 1,
        "minimumOrderAmount": "50.00",
        "order_tax_enabled": true,
        "order_tax_rate": 14.0,
        "currency_selection_enabled": false,
        "options": { "minimumOrderAmount": "50.00", "currency": "USD", "base_currency_code": "USD", "catalog_currency_code": "USD", "currency_selection_enabled": false }
    }
}
```

> Public vs Admin resource diff: `site_name/site_desc/meta_desc/site_copy_right` return `getTranslation(field, locale)` single string (locale from `Accept-Language` / `app()->getLocale()`) on `settings.front`; admin returns `{ar,en}` objects. `footer_logo` + `order_tax_enabled`/`order_tax_rate` + `currency_selection_enabled` + `options` included on both (order tax added 2026-09-13 via `SettingResource.php:44-45`).

**Throttle:** `throttle:public-api` only (no `auth:sanctum`). Cached per full URL under `settings` tag (storefront and admin share tag — `PUT` flush affects both).

---

### GET /api/v1/fast-shipping/settings

Fetch fast shipping config (subset of `settings.options.fast_shipping`). **Auth: sanctum + `view-fast-shipping`.**

**Route:** `Route::get('fast-shipping/settings', [FastShippingController::class,'getSettings'])` — `packages/marvel/src/Rest/Routes.php:121` inside `auth:sanctum,throttle:admin`
**Cache:** `Cache::remember('fast_shipping_settings', 3600, fn=> data_get(Settings::first()->options,'fast_shipping', defaults))`

**Response 200:**
```json
{
    "status": 200,
    "message": "Data fetched successfully",
    "success": true,
    "data": {"enabled": true, "duration_minutes": 120, "fee": 30, "start_hour": "08:00", "end_hour": "22:00"}
}
```

---

### PUT /api/v1/fast-shipping/settings

Update fast shipping config. **Auth: sanctum + `update-fast-shipping`.**

**Route:** `Route::put('fast-shipping/settings', [FastShippingController::class,'updateSettings'])` — `Rest/Routes.php:122`

**Request:**
```json
{"enabled": true, "duration_minutes": 120, "fee": 30, "start_hour": "08:00", "end_hour": "22:00"}
```

| Field | Rules |
|-------|-------|
| `enabled` | `sometimes|boolean` |
| `duration_minutes` | `sometimes|integer|min:1|max:1440` |
| `fee` | `sometimes|numeric|min:0` |
| `start_hour` | `sometimes|string|date_format:H:i` |
| `end_hour` | `sometimes|string|date_format:H:i` |

**Response 200:** `{status:200, message:"Fast shipping settings updated successfully", success:true}` + `Cache::forget('fast_shipping_settings')` + `lockForUpdate` transaction on `Settings::lockForUpdate()->first()`.

---

### Error contract (all settings endpoints)

```json
{"status": 401, "message": "Unauthenticated.", "success": false}
{"status": 403, "message": "Forbidden", "success": false}
{"status": 422, "message": "The given data was invalid.", "success": false, "errors": {"tiktok": ["The tiktok format is invalid."]}}
```
