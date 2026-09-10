# Fast Shipping Module

Fast Shipping is an express delivery module that allows customers to receive orders within minutes (configurable duration). It has its own checkout flow, settings, and product eligibility system.

## Key Files

| Layer | File | Purpose |
|-------|------|---------|
| Controller (Admin) | `packages/marvel/src/Http/Controllers/FastShippingController.php` | Settings CRUD |
| Controller (Public) | `app/Http/Controllers/Api/General/FastShippingController.php` | Status, products, checkout, orders |
| Service | `app/Services/General/FastShippingService.php` | Business logic for checkout |
| Repository | `packages/marvel/src/Database/Repositories/FastShippingRepository.php` | Settings CRUD, validation, ETA |
| Scope | `app/Models/Scopes/FastShippingScope.php` | Global scope for product filtering |
| Request | `packages/marvel/src/Http/Requests/FastCheckoutRequest.php` | Fast checkout validation |
| Context | `app/Contexts/ChannelContext.php` | Channel-based filtering |
| Middleware | `app/Http/Middleware/ChannelMiddleware.php` | X-Channel header parsing |
| Enum | `app/Enums/Channel.php` | HOME, FAST_SHIPPING |
| Enum | `packages/marvel/src/Enums/ShippingMethod.php` | SCHEDULED, FAST |
| Config | `config/channel.php` | Channel behavior configuration |

## Permissions

| Permission | Middleware | Endpoint |
|-----------|-----------|----------|
| `view-fast-shipping` | Super Admin | `GET /api/v1/fast-shipping/settings` |
| `update-fast-shipping` | Super Admin | `PUT /api/v1/fast-shipping/settings` |

## Routes

### Admin (Marvel) — `packages/marvel/src/Rest/Routes.php`

| Method | URI | Controller | Auth |
|--------|-----|-----------|------|
| GET | `fast-shipping/settings` | `FastShippingController@getSettings` | Sanctum + Permission |
| PUT | `fast-shipping/settings` | `FastShippingController@updateSettings` | Sanctum + Permission |
| PUT | `governorates/{id}/fast-shipping` | `GovernorateController@toggleFastShipping` | Sanctum |
| PUT | `products/{id}/fast-shipping` | `ProductController@toggleFastShipping` | Sanctum + Verified |

### Public (App) — `routes/api.php`

| Method | URI | Controller | Auth |
|--------|-----|-----------|------|
| GET | `fast-shipping/status` | `FastShippingController@status` | Public |
| GET | `fast-shipping/products` | `FastShippingController@products` | Public |
| POST | `fast-shipping/checkout` | `FastShippingController@checkout` | Sanctum |
| GET | `fast-shipping/orders` | `FastShippingController@orders` | Sanctum |

## Architecture Overview

```
┌─────────────────────────────────────────────────────────────┐
│                    CLIENT                                    │
│  Web App / Mobile App                                       │
└──────────┬──────────────────────────────────────┬──────────┘
           │ X-Channel: fast-shipping              │
           ▼                                        ▼
┌─────────────────────┐              ┌──────────────────────┐
│  Public API          │              │  Admin API           │
│  routes/api.php      │              │  Rest/Routes.php     │
├─────────────────────┤              ├──────────────────────┤
│ FastShippingController│            │ FastShippingController│
│ (App\General)        │              │ (Marvel)             │
│ - status()           │              │ - getSettings()      │
│ - products()         │              │ - updateSettings()   │
│ - checkout()         │              └──────┬───────────────┘
│ - orders()           │                     │
└──────┬──────────────┘                     ▼
       │                      ┌──────────────────────┐
       ▼                      │ FastShippingRepository│
┌─────────────────────┐      │ - settings (cache)    │
│ FastShippingService  │      │ - validation          │
│ - getStatus()        │      │ - ETA calculation     │
│ - getProducts()      │      └──────────────────────┘
│ - createFastOrder()  │
│ - paginateOrders()   │
└──────┬──────────────┘
       │
       ▼
┌─────────────────────┐
│ OrderCreationService │
│ CartInventoryService │
│ PaymentCheckoutHandler│
│ PromotionService     │
└─────────────────────┘
```
