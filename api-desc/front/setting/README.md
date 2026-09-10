# Settings Module — Frontend (Public API)

## Overview

The Settings module exposes platform-wide configuration (site name, SEO metadata, social media links, contact info, logo/favicon media, and arbitrary JSON options). There is exactly one settings record for the entire platform (singleton pattern).

## Key Files

| Layer | File |
|-------|------|
| Controller | `app/Http/Controllers/Api/General/SettingController.php` |
| Service | `app/Services/General/SettingService.php` |
| Resource | `Marvel\Http\Resources\SettingResource.php` |
| Model | `Marvel\Database\Models\Settings.php` |
| Routes | `routes/api.php` |
| Translation (EN) | `resources/lang/en/message.php` |
| Translation (AR) | `resources/lang/ar/message.php` |

## Routes

| Method | Endpoint | Auth | Purpose |
|--------|----------|------|---------|
| GET | `/api/v1/general/settings` | Public | Fetch platform settings |

## Response Fields (18)

`site_name`, `site_desc`, `meta_desc`, `site_copy_right`, `logo`, `favicon`, `site_email`, `email_support`, `facebook`, `instagram`, `linkedin`, `promotion_video_url`, `youtube`, `phone`, `fast_shipping_page_publish`, **`minimumOrderAmount`**, `options`

## Dependencies

- **Spatie Translatable** (`HasTranslations`) — bilingual site name, site desc, meta desc, copyright
- **Spatie Media Library** (`InteractsWithMedia`) — logo, favicon
- **SettingResource** — response transformation (no collection, single resource)
