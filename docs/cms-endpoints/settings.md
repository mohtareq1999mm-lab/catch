# API Reference — Settings

---

## Public Endpoints

---

### GET /api/v1/general/settings

Get platform settings (public).

**Authentication**: None (public)

**Response 200** (settings exist):
```json
{
  "status": 200,
  "message": "Data fetched successfully",
  "success": true,
  "data": {
    "site_name": "My Store",
    "site_desc": "Best online store",
    "meta_desc": "SEO description",
    "site_copy_right": "© 2026 My Store",
    "logo": "https://example.com/storage/settings/logo.jpg",
    "favicon": "https://example.com/storage/settings/favicon.ico",
    "site_email": "contact@mystore.com",
    "email_support": "support@mystore.com",
    "facebook": "https://facebook.com/mystore",
    "instagram": "https://instagram.com/mystore",
    "linkedin": "https://linkedin.com/company/mystore",
    "promotion_video_url": "https://youtube.com/watch?v=abc123",
    "youtube": "https://youtube.com/@mystore",
    "phone": "+1234567890",
    "fast_shipping_page_publish": true,
    "options": {
      "siteTitle": "My Store",
      "currency": "USD",
      "seo": { "ogTitle": "My Store" }
    }
  }
}
```

**Response 200** (no settings exist):
```json
{
  "status": 200,
  "message": "Data fetched successfully",
  "success": true,
  "data": []
}
```

**Response Fields**:

| Field | Type | Nullable | Description |
|-------|------|----------|-------------|
| site_name | string | Yes | Translatable site name (current locale) |
| site_desc | string | Yes | Translatable site description |
| meta_desc | string | Yes | Translatable meta description for SEO |
| site_copy_right | string | Yes | Translatable copyright notice |
| logo | string | No | Desktop logo URL (empty string if not set) |
| favicon | string | No | Favicon URL (empty string if not set) |
| site_email | string | Yes | Primary site email |
| email_support | string | Yes | Support email |
| facebook | string | Yes | Facebook page URL |
| instagram | string | Yes | Instagram URL |
| linkedin | string | Yes | LinkedIn URL |
| promotion_video_url | string | Yes | Promotional video URL |
| youtube | string | Yes | YouTube channel URL |
| phone | string | Yes | Contact phone number |
| fast_shipping_page_publish | bool | Yes | Fast shipping page publish status (0/1) |
| options | object | No | Additional platform options (currency, siteTitle, SEO, etc.) |

**Business Rules**:
- All translatable fields (`site_name`, `site_desc`, `meta_desc`, `site_copy_right`) return the value for the current application locale
- Returns `[]` if no settings row exists in the database
- `logo` and `favicon` return empty string `""` when no media has been uploaded
- `options` is an object that may contain `siteTitle`, `siteSubtitle`, `currency`, `seo`, and other dynamic settings

---

### GET /settings

Get platform settings (Marvel public — same as `/api/v1/general/settings`).

**Authentication**: None (public)

**Response**: Same structure as `GET /api/v1/general/settings`.

---

## Admin Endpoints

---

### PUT /settings

Update platform settings.

**Authentication**: `auth:sanctum`, permission: `update-settings`

**Request Body** (multipart/form-data):

| Field | Type | Required | Description |
|-------|------|----------|-------------|
| site_name | object | required | Translatable site name (e.g., `{"en": "My Store", "ar": "متجري"}`) |
| site_name.* | string | required | Per-locale name (min:3, max:200) |
| site_desc | object | required | Translatable site description |
| site_desc.* | string | required | Per-locale description (min:3, max:2000) |
| meta_desc | object | required | Translatable meta description |
| meta_desc.* | string | required | Per-locale meta description (min:3, max:2000) |
| site_copy_right | object | required | Translatable copyright notice |
| site_copy_right.* | string | required | Per-locale copyright (min:3, max:200) |
| site_email | string | required | Primary site email |
| email_support | string | required | Support email |
| facebook | string | required | Facebook URL |
| instagram | string | required | Instagram URL |
| linkedin | string | required | LinkedIn URL |
| youtube | string | required | YouTube URL |
| phone | string | required | Contact phone |
| fast_shipping_page_publish | string | required | Must be "0" or "1" |
| logo | file | sometimes | Desktop logo (jpeg,png,jpg,gif,svg, max 2MB) |
| favicon | file | sometimes | Favicon (jpeg,png,jpg,gif,svg, max 2MB) |
| promotion_video_url | string | sometimes | Promotional video URL |
| options | object | sometimes | Additional platform options |

**Validation Rules**:
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
| site_email | required, email |
| email_support | required, email |
| facebook | required, url |
| instagram | required, url |
| linkedin | required, url |
| youtube | required, url |
| phone | required, string |
| fast_shipping_page_publish | required, in:0,1 |
| logo | sometimes, image, mimes:jpeg,png,jpg,gif,svg, max:2048 |
| favicon | sometimes, image, mimes:jpeg,png,jpg,gif,svg, max:2048 |
| promotion_video_url | sometimes, url |
| options | sometimes, array |

**Request Body (JSON)**:
```json
{
  "site_name": { "en": "My Store", "ar": "متجري" },
  "site_desc": { "en": "Best online store", "ar": "أفضل متجر إلكتروني" },
  "meta_desc": { "en": "SEO description", "ar": "وصف SEO" },
  "site_copy_right": { "en": "© 2026 My Store", "ar": "© ٢٠٢٦ متجري" },
  "site_email": "contact@mystore.com",
  "email_support": "support@mystore.com",
  "facebook": "https://facebook.com/mystore",
  "instagram": "https://instagram.com/mystore",
  "linkedin": "https://linkedin.com/company/mystore",
  "promotion_video_url": "https://youtube.com/watch?v=abc123",
  "youtube": "https://youtube.com/@mystore",
  "phone": "+1234567890",
  "fast_shipping_page_publish": "1",
  "options": {
    "siteTitle": "My Store",
    "currency": "USD"
  }
}
```

> **Note:** `logo` and `favicon` are file fields — they must be sent as `multipart/form-data`. All non-file fields can be sent as JSON in the same request.

**Response 200**:
```json
{
  "status": 200,
  "message": "Settings updated successfully",
  "success": true,
  "data": {
    "site_name": "My Store",
    "site_desc": "Best online store",
    "meta_desc": "SEO description",
    "site_copy_right": "© 2026 My Store",
    "logo": "https://example.com/storage/settings/logo.jpg",
    "favicon": "https://example.com/storage/settings/favicon.ico",
    "site_email": "contact@mystore.com",
    "email_support": "support@mystore.com",
    "facebook": "https://facebook.com/mystore",
    "instagram": "https://instagram.com/mystore",
    "linkedin": "https://linkedin.com/company/mystore",
    "promotion_video_url": "https://youtube.com/watch?v=abc123",
    "youtube": "https://youtube.com/@mystore",
    "phone": "+1234567890",
    "fast_shipping_page_publish": true,
    "options": {
      "siteTitle": "My Store",
      "currency": "USD"
    }
  }
}
```

**Response 422** (validation):
```json
{
  "site_name.en": ["The site_name.en must be at least 3 characters."],
  "site_email": ["The site_email field is required."],
  "logo": ["The logo must be an image."]
}
```

**Quick Test**:
```bash
# Fetch settings (public)
curl -X GET "http://example.com/api/v1/general/settings" \
  -H "Accept: application/json"

# Update settings (admin) — without images
curl -X PUT "http://example.com/settings" \
  -H "Authorization: Bearer {token}" \
  -H "Content-Type: application/json" \
  -H "Accept: application/json" \
  -d '{
    "site_name": {"en": "My Store", "ar": "متجري"},
    "site_desc": {"en": "Best online store"},
    "meta_desc": {"en": "SEO description"},
    "site_copy_right": {"en": "© 2026"},
    "site_email": "contact@mystore.com",
    "email_support": "support@mystore.com",
    "facebook": "https://facebook.com/mystore",
    "instagram": "https://instagram.com/mystore",
    "linkedin": "https://linkedin.com/mystore",
    "youtube": "https://youtube.com/@mystore",
    "phone": "+1234567890",
    "fast_shipping_page_publish": "1"
  }'
```

**Business Rules**:
- If no settings row exists, a new one is created automatically on update
- Translatable fields are stored as JSON objects per locale
- `logo` and `favicon` are uploaded to media library collections; previous images are replaced
- Activity is logged via model events
- No cache layer — data is read directly from the database

---

## Resource Structure

| Field | Type | Always Present | Notes |
|-------|------|----------------|-------|
| site_name | string | Yes | Current locale translation |
| site_desc | string | Yes | Current locale translation |
| meta_desc | string | Yes | Current locale translation |
| site_copy_right | string | Yes | Current locale translation |
| logo | string | Yes | Empty string if no media uploaded |
| favicon | string | Yes | Empty string if no media uploaded |
| site_email | string | Yes | Null if not set |
| email_support | string | Yes | Null if not set |
| facebook | string | Yes | Null if not set |
| instagram | string | Yes | Null if not set |
| linkedin | string | Yes | Null if not set |
| promotion_video_url | string | Yes | Null if not set |
| youtube | string | Yes | Null if not set |
| phone | string | Yes | Null if not set |
| fast_shipping_page_publish | bool | Yes | Null if not set |
| options | object | Yes | Empty object if not set |

---

## Database Impact

| Table | Operation |
|-------|-----------|
| settings | Read / Create / Update |
| media | Create (when logo/favicon uploaded) |

---

## Dependencies

| Type | Files |
|------|-------|
| Controller | `App\Http\Controllers\Api\General\SettingController` |
| Controller | `Marvel\Http\Controllers\SettingsController` |
| Request | `Marvel\Http\Requests\SettingsRequest` |
| Resource | `Marvel\Http\Resources\SettingResource` |
| Service | `App\Services\General\SettingService` |
| Repository | `Marvel\Database\Repositories\SettingsRepository` |
| Model | `Marvel\Database\Models\Settings` |
| Trait | `Marvel\Traits\ApiResponse` |

---

## Test Coverage

| Test File | Coverage |
|-----------|----------|
| `tests/Feature/Settings/SettingsCrudTest.php` | View, update, JSON structure |
| `tests/Feature/Settings/SettingsRegressionTest.php` | getData, caching, null results |
| `tests/Feature/Settings/SettingsValidationTest.php` | Validation rules |
| `tests/Feature/Settings/SettingsAuthenticationTest.php` | Auth/permission checks |
