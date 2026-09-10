# Settings Module — Backend Architecture (Public API)

## Endpoints

| Method | URL | Auth | Purpose |
|--------|-----|------|---------|
| GET | `/api/v1/general/settings` | Public | Fetch platform settings (singleton) |

## Route Definitions

**File:** `routes/api.php`

```php
Route::prefix('v1/general')->middleware('api')->group(function () {
    Route::get('settings', [SettingController::class, 'index']);
});
```

## Middleware

- `index`: `api` group (throttle, SubstituteBindings, ChannelMiddleware) — no auth

## Request Flow

### Flow: Fetch Settings

```
Client → GET /api/v1/general/settings
         ↓
    SettingController@index
         ↓
    SettingService::getSetting()
         ↓
    Settings::first()           ← No caching
         ↓
    SettingResource::make($setting)
         ↓
    Response: 200
```

## Key Classes

| Class | Method | Responsibility |
|-------|--------|----------------|
| `SettingController` | `index()` | Fetch and return settings |
| `SettingService` | `getSetting()` | Query `Settings::first()` |
| `SettingResource` | `toArray()` | Transform settings for response |

## Model: Settings

| Column | Type | Translatable | Description |
|--------|------|:---:|-------------|
| id | bigint UNSIGNED | | Primary key |
| site_name | string | ✓ | Website name |
| site_desc | text | ✓ | Website description |
| meta_desc | text | ✓ | SEO meta description |
| site_copy_right | string | ✓ | Copyright text |
| logo | string | | Logo path (media library) |
| favicon | string | | Favicon path (media library) |
| site_email | string | | Contact email |
| email_support | string | | Support email |
| facebook | string | | Facebook URL |
| instagram | string | | Instagram URL |
| linkedin | string | | LinkedIn URL |
| promotion_video_url | string | | Promotional video URL |
| youtube | string | | YouTube URL |
| phone | string | | Phone number |
| fast_shipping_page_publish | boolean | | Fast shipping page flag |
| options | json | | Arbitrary settings (array cast) — includes `minimumOrderAmount` |

## Resource: SettingResource

Returns 18 fields — 4 translatable strings, 2 media URLs, 3 contact fields, 4 social links, 1 video URL, 1 boolean flag, `minimumOrderAmount`, 1 json object.

| Field | Type | Source | Description |
|-------|------|--------|-------------|
| `minimumOrderAmount` | float | `options.minimumOrderAmount` | Min cart total to place order; enforced in `CheckoutRepository::verify()` |

## Where `minimumOrderAmount` Is Used

| File | Line | Usage |
|------|------|-------|
| `SettingResource.php` | 34 | Exposed as top-level field in API response |
| `CheckoutRepository.php` | 39 | Read from `settings.options.minimumOrderAmount` |
| `CheckoutRepository.php` | 61-63 | Throws 400 if `total < minimumOrderAmount` |
| `SettingsSeeder.php` | 126 | Default value: 0 |

## Caching

- **Public endpoint:** No caching — `Settings::first()` runs on every request
- **Admin endpoint:** The model has a commented-out static `getData()` method with 24h cache that is not used by the public controller
- **Recommendation:** Add `Cache::remember()` with 600s TTL and channel-scoped key
