# Settings Module — Frontend Integration Guide

> Admin `GET|PUT /api/v1/settings` (`SettingsController`, `auth:sanctum`, `throttle:admin`, permissions) + public `GET /api/v1/general/settings` (`SettingController`, `settings.front`, `throttle:public-api`, no auth). Verified 2026-09-13 vs `SettingsRequest`/`SettingResource`/`Settings` model.

## Endpoint table

| # | Method | Endpoint | Auth | Permission | Purpose |
|---|--------|----------|------|------------|---------|
| 1 | `GET` | `/api/v1/general/settings` | none | — | Storefront settings — single-locale rendering, cached per full URL under `settings` tag |
| 2 | `GET` | `/api/v1/settings` | Sanctum | `view-settings` | Admin fetch — `{ar,en}` objects, `throttle:admin` |
| 3 | `PUT` | `/api/v1/settings` | Sanctum | `update-settings` | Admin partial update — all `sometimes`, media via multipart, merges `currency_selection_enabled` into `options` |
| 4 | `GET` | `/api/v1/fast-shipping/settings` | Sanctum | `view-fast-shipping` | Fast shipping slice from `options.fast_shipping`, 1h cache |
| 5 | `PUT` | `/api/v1/fast-shipping/settings` | Sanctum | `update-fast-shipping` | Merge into `options.fast_shipping`, `lockForUpdate`, forget cache |

---

### 1. GET /api/v1/general/settings — Fetch Settings (Public, No Auth)

**Route:** `Route::get('settings', [SettingController::class,'index'])->name('settings.front')` — `routes/api.php:92` (`prefix v1/general`, `throttle:public-api`)
**Use:** call without token; set `Accept-Language: ar` or `en` to control locale rendering.

**Response 200:**
```json
{
  "status": 200, "message": "تم جلب البيانات بنجاح", "success": true,
  "data": {
    "site_name": "موقعي",
    "site_desc": "هذا هو وصف الموقع.",
    "meta_desc": "الوصف التعريفي للموقع.",
    "site_copy_right": "© 2026 جميع الحقوق محفوظة.",
    "logo": "https://cdn.example.com/storage/logo-setting/abc.jpg",
    "footer_logo": "https://cdn.example.com/storage/footer_logo-setting/def.jpg",
    "favicon": "https://cdn.example.com/storage/favicon-setting/ghi.jpg",
    "site_email": "info@example.com", "email_support": "support@example.com",
    "facebook": "https://facebook.com/mywebsite", "instagram": "https://instagram.com/mywebsite",
    "linkedin": "https://linkedin.com/company/mywebsite", "promotion_video_url": "https://www.youtube.com/watch?v=dQw4w9WgXcQ",
    "youtube": "https://youtube.com/@mywebsite", "tiktok": null, "snapchat": null,
    "phone": "+201001234567", "fast_shipping_page_publish": 1,
    "minimumOrderAmount": "50.00",
    "order_tax_enabled": true, "order_tax_rate": 14.0,
    "currency_selection_enabled": false,
    "options": {"minimumOrderAmount":"50.00","currency":"USD","base_currency_code":"USD","catalog_currency_code":"USD","currency_selection_enabled":false,"fast_shipping":{"enabled":true,"duration_minutes":120,"fee":30,"start_hour":"08:00","end_hour":"22:00"}}
  }
}
```

**Notes:** `site_name/site_desc/meta_desc/site_copy_right` are **single locale strings** (public) — via `SettingResource` `routeIs('settings.front')` → `getTranslation(locale)`. `footer_logo`/`order_tax_enabled`/`order_tax_rate` included (tax added 2026-09-13 via `SettingResource.php:44-45`). `tiktok/snapchat` `null` when unset. `minimumOrderAmount` is **string** (`decimal:2`). Cached under shared `settings` tag — admin `PUT` flush affects storefront on next fetch.

### 2. GET /api/v1/settings — Fetch Settings (Admin, Requires Auth)

**Route:** `Route::get('settings', [SettingsController::class,'index'])` — `packages/marvel/src/Rest/Routes.php:118` (`auth:sanctum`,`throttle:admin`, `permission:view-settings`)
**Use:** admin settings form preload; token + `Accept-Language` still respected but resource returns bilingual.

**Response 200:**
```json
{
  "status": 200, "message": "Data fetched successfully", "success": true,
  "data": {
    "site_name": {"ar":"موقعي","en":"My Site"}, "site_desc": {"ar":"...","en":"..."}, "meta_desc": {"ar":"...","en":"..."}, "site_copy_right": {"ar":"...","en":"..."},
    "logo": "https://cdn.example.com/storage/logo-setting/abc.jpg", "footer_logo": "https://cdn.example.com/storage/footer_logo-setting/def.jpg", "favicon": "...",
    "site_email":"info@example.com","email_support":"support@example.com",
    "facebook":"https://facebook.com/mywebsite","instagram":"...","linkedin":"...","promotion_video_url":"...",
    "youtube":"https://youtube.com/@mywebsite","tiktok":null,"snapchat":null,"phone":"+201001234567","fast_shipping_page_publish":1,
    "minimumOrderAmount":"50.00",
    "order_tax_enabled": true, "order_tax_rate": 14.0,
    "currency_selection_enabled":false,
    "options": {"minimumOrderAmount":"50.00","currency_selection_enabled":false, "fast_shipping":{...}}
  }
}
```

**Notes:** admin returns `{ar,en}` objects for the 4 translatable fields. `footer_logo` + `order_tax_enabled`/`order_tax_rate` always present (tax fixed 2026-09-13). `401` no token, `403` missing `view-settings`.

### 3. PUT /api/v1/settings — Update Settings (Admin)

**Route:** `Route::put('settings', [SettingsController::class,'update'])` — `Rest/Routes.php:119` (`auth:sanctum`,`throttle:admin`, `permission:update-settings`)
**Request:** partial-update — all fields `sometimes` per `SettingsRequest.php:27-58`. Send only changed fields; omit `currency_selection_enabled` to preserve it.

**JSON body example (partial allowed):**
```json
{
  "site_name": {"en":"New Name","ar":"اسم جديد"},
  "site_desc": {"en":"Description","ar":"الوصف"},
  "meta_desc": {"en":"Meta","ar":"الوصف التعريفي"},
  "site_copy_right": {"en":"Copyright","ar":"حقوق النشر"},
  "site_email":"admin@example.com","email_support":"support@example.com",
  "facebook":"https://facebook.com/...","instagram":"https://instagram.com/...","linkedin":"https://linkedin.com/...",
  "youtube":"https://youtube.com/...","tiktok":"https://tiktok.com/@mywebsite","snapchat":"https://snapchat.com/@mywebsite",
  "phone":"+201001234567","fast_shipping_page_publish":"1",
  "currency_selection_enabled": true,
  "minimum_order_amount": 100,
  "order_tax_enabled": true,
  "order_tax_rate": 14.0,
  "options": {"minimumOrderAmount":100, "fast_shipping":{"enabled":true,"duration_minutes":120,"fee":0,"start_hour":"08:00","end_hour":"22:00"}}
}
```

**Multipart for media:** fields `logo`, `footer_logo`, `favicon` as `file` (`sometimes|image|mimes:jpeg,png,jpg,gif,svg|max:2048`). Fail → `HttpException 422 LOGO_UPLOAD_FAILED/FOOTER_LOGO_UPLOAD_FAILED/FAVICON_UPLOAD_FAILED`.

**Key fields:**

| Field | Type | Rule | Frontend behavior |
|-------|------|------|-------------------|
| `site_name/site_desc/meta_desc/site_copy_right` | `array {ar,en}` | `sometimes|array` + `* sometimes|string min:3 max:200/2000/200/2000` | send `{en,ar}` when updating either locale; omit to preserve |
| `logo/footer_logo/favicon` | `file` | `sometimes|image|mimes...+max:2048` | `<input type=file>`; immediate preview; omit to preserve existing media |
| `site_email/email_support` | `string` | `sometimes|email` | show field-level `422` on invalid |
| `facebook/instagram/linkedin/promotion_video_url/youtube/tiktok/snapchat` | `string url` | `sometimes|url` | `tiktok/snapchat` nullable |
| `phone` | `string` | `sometimes|string` | |
| `fast_shipping_page_publish` | `0|1` | `sometimes|in:0,1` | toggle |
| `minimum_order_amount` | `numeric` | `sometimes|numeric|min:0` | money string returned as `minimumOrderAmount: string` |
| `currency_selection_enabled` | `boolean` | `sometimes|boolean` | `true/false/0/1/"0"/"1"` → `422` for `2` or `"not-a-boolean"`; merged into `options` without dropping other keys; toggles `CurrencyService` memo |
| `order_tax_enabled` | `boolean` | `sometimes|boolean` | show tax section toggle |
| `order_tax_rate` | `numeric 0..100 nullable` | `nullable|numeric|min:0|max:100` | enable only when `order_tax_enabled` true |
| `options` | `array` | `sometimes|array` | deep merge preserved (`fast_shipping` etc.) |

**Response 200:**
```json
{"status":200,"message":"Settings updated successfully","success":true,"data":{"site_name":{"ar":"...","en":"..."},"logo":"...","footer_logo":"...","minimumOrderAmount":"100.00","currency_selection_enabled":true,"options":{"minimumOrderAmount":100,"currency_selection_enabled":true, "fast_shipping":{...}}}}
```

Data is same `SettingResource` shape as admin GET.

**Errors:** `401` unauth, `403` missing `update-settings`, `422` validation (show `errors.field:[]` under each input). Flushes `settings` tag + `CurrencyService::forgetEffectiveCode()` when flag present.

### 4. PUT /api/v1/fast-shipping/settings — Update Fast Shipping Config

**Body:** `{"enabled":boolean,"duration_minutes":int 1..1440,"fee":numeric min0,"start_hour":"H:i","end_hour":"H:i"}` all `sometimes`. On success `200` + `Cache::forget('fast_shipping_settings')`.

### 5. GET /api/v1/fast-shipping/settings — Fetch Fast Shipping Config

**Auth:** `view-fast-shipping`. Returns `{enabled, duration_minutes, fee, start_hour, end_hour}` from `options.fast_shipping` (cached 1h).

## Common handling

- **i18n:** render public `site_name` as string (`Accept-Language` driven), admin as `localeString || arFallback`. Send `{en,ar}` on save.
- **Cache:** storefront/admin `GET` share `settings` tag — treat `PUT` as cache invalidation (refetch `GET /general/settings` after save).
- **Error UX:** `422` → field-level errors; social URL `422` example `{"tiktok":["The tiktok format is invalid."]}`; boolean `422` example `{"currency_selection_enabled":["The currency selection enabled field must be true or false."]}`; image `422` → `message.ERROR.LOGO_UPLOAD_FAILED`.
- **Media preview:** `logo/footer_logo/favicon` are `getFirstMediaUrl` — use `<img src>` directly; empty string means no media yet.
