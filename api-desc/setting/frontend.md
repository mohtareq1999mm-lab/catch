# Settings Module — Frontend Integration Guide (Admin)

---

### 1. GET /api/v1/general/settings — Fetch Settings (Public, No Auth)

**Purpose:** Retrieve current settings for the storefront. No authentication required.

**Authentication:** None (public endpoint)

**Response 200:**
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
        "logo": "",
        "favicon": "",
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
        "currency_selection_enabled": false,
        "options": {
            "minimumOrderAmount": "50.00",
            "currency": "USD",
            "base_currency_code": "USD",
            "catalog_currency_code": "USD",
            "currency_selection_enabled": false
        }
    }
}
```

> **Note:** The translatable fields (`site_name`, `site_desc`, `meta_desc`, `site_copy_right`) are returned as a **single locale string** instead of `{ar, en}` objects. The response includes `tiktok: null`, `snapchat: null` for the social URL fields. This endpoint is under `Route::prefix('v1/general')->group()` with `throttle:public-api` middleware only.

---

### 2. GET /api/v1/settings — Fetch Settings (Admin, Requires Auth)

**Purpose:** Retrieve current settings for the admin settings form.

**Authentication:** Sanctum token with `view-settings` permission

**Response 200:**
```json
{
    "status": 200,
    "message": "Data fetched successfully",
    "success": true,
    "data": {
        "site_name": {"ar": "...", "en": "..."},
        "site_desc": {"ar": "...", "en": "..."},
        "meta_desc": {"ar": "...", "en": "..."},
        "site_copy_right": {"ar": "...", "en": "..."},
        "logo": "",
        "favicon": "",
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
        "currency_selection_enabled": false,
        "options": {
            "minimumOrderAmount": "50.00",
            "currency": "USD",
            "base_currency_code": "USD",
            "catalog_currency_code": "USD",
            "currency_selection_enabled": false
        }
    }
}
```

> **Note:** The translatable fields are returned as `{ar, en}` objects for the admin endpoint. The response includes `tiktok: null`, `snapchat: null` (nullable string fields).

---

### 3. PUT /api/v1/settings — Update Settings (Admin)

**Purpose:** Save admin settings form.

**Authentication:** Sanctum token with `update-settings` permission

**Request:**
```json
{
    "site_name": {"en": "Name", "ar": "الاسم"},
    "site_desc": {"en": "Description", "ar": "الوصف"},
    "meta_desc": {"en": "Meta", "ar": "الوصف التعريفي"},
    "site_copy_right": {"en": "Copyright", "ar": "حقوق النشر"},
    "site_email": "admin@example.com",
    "email_support": "support@example.com",
    "facebook": "https://facebook.com/...",
    "instagram": "https://instagram.com/...",
    "linkedin": "https://linkedin.com/...",
    "youtube": "https://youtube.com/...",
    "tiktok": "https://tiktok.com/...",
    "snapchat": "https://snapchat.com/...",
    "phone": "+201001234567",
    "fast_shipping_page_publish": "1",
    "currency_selection_enabled": false,
    "options": {
        "minimumOrderAmount": 100,
        "fast_shipping": {
            "enabled": true,
            "duration_minutes": 120,
            "fee": 0,
            "start_hour": "08:00",
            "end_hour": "22:00"
        }
    }
}
```

**Validation Rules:**
| Field | Rules |
|-------|-------|
| site_name | required, array |
| site_name.* | required, string, min:3, max:200 |
| site_desc | required, array |
| site_desc.* | required, string, min:3, max:2000 |
| meta_desc | required, array |
| meta_desc.* | required, string, min:3, max:2000 |
| site_copy_right | required, array |
| site_copy_right.* | required, string, min:3, max:200 |
| logo | sometimes, image, mimes:jpeg,png,jpg,gif,svg, max:2048 |
| favicon | sometimes, image, mimes:jpeg,png,jpg,gif,svg, max:2048 |
| site_email | required, email |
| email_support | required, email |
| facebook | required, url |
| instagram | required, url |
| linkedin | required, url |
| promotion_video_url | sometimes, url |
| youtube | required, url |
| tiktok | sometimes, url |
| snapchat | sometimes, url |
| phone | required, string |
| fast_shipping_page_publish | required, in:0,1 |
| minimum_order_amount | sometimes, numeric, min:0 |
| currency_selection_enabled | sometimes, boolean |
| options | sometimes, array |

**Response 200:**
```json
{
    "status": 200,
    "message": "Settings updated successfully",
    "success": true,
    "data": { ... }
}
```

> **Notes:**
- `currency_selection_enabled` is a top-level boolean toggle; when sent it is merged into `options` (other option keys preserved) and resets the storefront effective-currency memo.
- Send it as a JSON boolean (`true`/`false`) — validation is `boolean` (also accepts `0`/`1`/`"0"`/`"1"`; rejects `2` or `"not-a-boolean"`).
- When `currency_selection_enabled` is `false`, the storefront currency selector is hidden/disabled (selections are stored but ignored).

---

### 4. PUT /api/v1/fast-shipping/settings — Update Fast Shipping Config

**Purpose:** Save fast shipping configuration.

**Authentication:** Sanctum token with `update-fast-shipping` permission

**Request:**
```json
{
    "enabled": true,
    "duration_minutes": 120,
    "fee": 0,
    "start_hour": "08:00",
    "end_hour": "22:00"
}
```

**Response 200:** Success message

---

### 5. GET /api/v1/fast-shipping/settings — Fetch Fast Shipping Config

**Purpose:** Retrieve fast shipping configuration for the admin form.

**Authentication:** Sanctum token with `view-fast-shipping` permission

**Response 200:**
```json
{
    "status": 200,
    "message": "Data fetched successfully",
    "success": true,
    "data": {
        "enabled": true,
        "duration_minutes": 120,
        "fee": 0,
        "start_hour": "08:00",
        "end_hour": "22:00"
    }
}
```