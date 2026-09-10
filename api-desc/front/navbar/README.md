# Navigation Bar Module

## Overview

The Navigation Bar module provides the hierarchical category tree used to render the main navigation bar (navbar) on the frontend storefront. It exposes a single public read-only endpoint that returns a nested tree of active categories with their images and children.

This endpoint is consumed by the frontend to build the main menu navigation, mega-menu, and sidebar category navigation.

## Key Files

| Layer | File |
|-------|------|
| Controller | `app/Http/Controllers/Api/General/HomeController.php` |
| Service | `app/Services/General/HomeService.php` |
| Resource | `app/Http/Resources/Category/CategoryNavbarResource.php` |
| Model | `packages/marvel/src/Database/Models/Category.php` |
| Route | `routes/api.php` (line 38) |
| Channel Middleware | `app/Http/Middleware/ChannelMiddleware.php` |
| Channel Context | `app/Contexts/ChannelContext.php` |
| Channel Enum | `app/Enums/Channel.php` |
| Channel Config | `config/channel.php` |
| HasChannelFilter Trait | `app/Traits/HasChannelFilter.php` |
| Translation (EN) | `resources/lang/en/message.php` |
| Translation (AR) | `resources/lang/ar/message.php` |

## Dependencies

- **Laravel Cache** — 120-second TTL, channel-scoped cache keys
- **Spatie Translatable** (`HasTranslations`) — bilingual category names (en/ar)
- **Spatie Media Library** (`InteractsWithMedia`) — category desktop/mobile images
- **Laravel SoftDeletes** — excludes soft-deleted categories
- **Channel Middleware** — channel-based filtering via `X-Channel` header

## Routes

| Method | Endpoint | Auth | Purpose |
|--------|----------|------|---------|
| GET | `/api/v1/general/nav-data` | Public | Fetch hierarchical category tree for navbar |

## Permissions

No authentication or permissions required. This is a public endpoint.

## Related Modules

- **Categories** (`api-desc/categories/`) — The underlying category CRUD and hierarchy management
- **Home** (`GET /api/v1/general/home`) — Homepage data using the same service
