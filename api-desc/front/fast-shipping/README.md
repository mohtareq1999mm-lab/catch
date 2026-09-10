# Fast Shipping Feature - API Investigation

## Feature Name

Fast Shipping Channel

## Description

The Fast Shipping feature provides a dual-channel e-commerce experience: a **Home channel** (all products) and a **Fast Shipping channel** (products eligible for rapid delivery). It uses a global Eloquent scope on the `Product` model that transparently adds `WHERE is_fast_shipping_available = 1` when the `X-Channel: fast-shipping` header is present. Includes dedicated endpoints for status, products, checkout, and orders.

## Architecture Overview

```
HTTP Request
  Header: X-Channel: fast-shipping
       |
       v
[ChannelMiddleware] → ChannelContext::setChannel(FAST_SHIPPING)
       |
       v
[FastShippingScope (Global)] → WHERE is_fast_shipping_available = 1
       |
       v
[Controller] → [Service] → [Model]
       |
       v
[Customer Endpoints: status, products, checkout, orders]
[Admin Endpoints: settings, toggle, governorates]
```

## Key Endpoints

### Customer-Facing

| Method | URI | Auth | Description |
|--------|-----|------|-------------|
| GET | `/fast-shipping/status` | No | Service status, hours, fee |
| GET | `/fast-shipping/products` | No | Paginated eligible products |
| POST | `/fast-shipping/checkout` | Sanctum | Fast shipping order |
| GET | `/fast-shipping/orders` | Sanctum | User's fast orders |

### Admin

| Method | URI | Permission |
|--------|-----|-----------|
| GET | `/fast-shipping/settings` | `view-fast-shipping` |
| PUT | `/fast-shipping/settings` | `update-fast-shipping` |
| PUT | `/products/{id}/fast-shipping` | Auth |
| PUT | `/governorates/{id}/fast-shipping` | Auth |

## Key Files

| Layer | Path |
|-------|------|
| Controller (Customer) | `app/Http/Controllers/Api/General/FastShippingController.php` |
| Controller (Admin) | `packages/marvel/src/Http/Controllers/FastShippingController.php` |
| Service (Customer) | `app/Services/General/FastShippingService.php` |
| Repository (Settings) | `packages/marvel/src/Database/Repositories/FastShippingRepository.php` |
| Global Scope | `app/Models/Scopes/FastShippingScope.php` |
| Channel Context | `app/Contexts/ChannelContext.php` |
| Channel Enum | `app/Enums/Channel.php` |
| Channel Middleware | `app/Http/Middleware/ChannelMiddleware.php` |
| HasChannelFilter Trait | `app/Traits/HasChannelFilter.php` |
| Config | `config/channel.php` |
| Routes | `routes/api.php`, `packages/marvel/src/Rest/Routes.php` |

## Tech Stack

- **Laravel** with Eloquent ORM
- **Global Eloquent Scope** for transparent channel filtering
- **Channel Context** system (singleton middleware)
- **Spatie Translatable** for settings messages
- **57 test methods** across 6 test files
- **Transaction-based checkout** with full validation
