# Fast Shipping — Backend Architecture

## Architecture Layers

### Two-Layer Architecture

```
┌────────────────────────────────────────────────────────┐
│                   PUBLIC API (app/)                    │
│  FastShippingController → FastShippingService          │
│  - status() - public availability check               │
│  - products() - list eligible products                │
│  - checkout() - create fast order (auth)              │
│  - orders() - list my fast orders (auth)              │
├────────────────────────────────────────────────────────┤
│                   ADMIN API (packages/marvel/)          │
│  FastShippingController → FastShippingRepository       │
│  - getSettings() - view configuration                 │
│  - updateSettings() - modify configuration            │
├────────────────────────────────────────────────────────┤
│                   SHARED INFRASTRUCTURE                 │
│  FastCheckoutRequest (validation)                     │
│  FastShippingScope (global Eloquent scope)            │
│  ChannelContext / ChannelMiddleware (channel header)   │
│  Channel enum                                         │
│  ShippingMethod enum                                  │
│  Permission enum (view-fast-shipping, update-fast-    │
│    shipping)                                          │
└────────────────────────────────────────────────────────┘
```

---

## Admin Endpoints

| Method | URI | Middleware | Controller Method |
|--------|-----|-----------|-------------------|
| GET | `/api/v1/fast-shipping/settings` | `permission:view-fast-shipping` | `FastShippingController@getSettings` |
| PUT | `/api/v1/fast-shipping/settings` | `permission:update-fast-shipping` | `FastShippingController@updateSettings` |
| PUT | `/api/v1/governorates/{id}/fast-shipping` | `auth:sanctum` | `GovernorateController@toggleFastShipping` |
| PUT | `/api/v1/products/{id}/fast-shipping` | `auth:sanctum, email.verified` | `ProductController@toggleFastShipping` |

---

## Public Endpoints

| Method | URI | Middleware | Controller Method |
|--------|-----|-----------|-------------------|
| GET | `/api/v1/fast-shipping/status` | None (public) | `FastShippingController@status` |
| GET | `/api/v1/fast-shipping/products` | None (public) | `FastShippingController@products` |
| POST | `/api/v1/fast-shipping/checkout` | `auth:sanctum` | `FastShippingController@checkout` |
| GET | `/api/v1/fast-shipping/orders` | `auth:sanctum` | `FastShippingController@orders` |

---

## Controller Flow

### Admin: getSettings

```
Request → FastShippingController@getSettings
    → FastShippingRepository::getSettings()
        → Cache::remember('fast_shipping_settings', 3600, ...)
            → Settings::first()->options['fast_shipping']
    ← Response: { enabled, available, duration_minutes, fee,
                  opens_at, closes_at, available_again_at }
```

### Admin: updateSettings

```
Request (validated JSON) → FastShippingController@updateSettings
    → FastShippingRepository::updateSettings($data)
        → DB::transaction
            → Settings::lockForUpdate()->first()
            → Merge $data into options['fast_shipping']
            → Settings::update()
        → Cache::forget('fast_shipping_settings')
    ← Response: 200 OK
```

### Public: status

```
Request → FastShippingController@status
    → FastShippingService::getStatus()
        → FastShippingRepository::getStatus()
            → isGloballyEnabled()
            → isWithinWorkingHours()
            → calculate available_again_at
    ← Response: { enabled, available, duration_minutes, fee,
                  opens_at, closes_at, available_again_at }
```

### Public: products

```
Request (search, limit, page) → FastShippingController@products
    → FastShippingService::getFastShippingProducts()
        → Product::active()->fastShippingAvailable()
            ->with(categories, variations, flash_sales)
            ->withAvg(reviews->approved, rating)
            ->withCount(reviews->approved)
            ->when(search, filter by name/description)
            ->paginate(limit)
    ← Response: paginated product list
```

### Public: checkout

```
Request (validated by FastCheckoutRequest) → FastShippingController@checkout
    → getActiveCartForUser($user)
    → ensureCartReservation($cart)
    → FastShippingService::createFastOrder($request)
        → Validate governorate exists
        → Load cart items (fast shipping only)
        → FastShippingRepository::validateCheckout()
            → isGloballyEnabled?
            → isWithinWorkingHours?
            → isGovernorateEnabled?
            → areProductsFastEligible?
        → DB::transaction (lockForUpdate)
            → Re-validate coupon if present
            → Calculate totals (with promotions)
            → Calculate ETA
            → Resolve shipping price
            → OrderCreationService::createOrder()
            → OrderCreationService::createOrderItems()
            → OrderCreationService::finalizeOrder()
            → CartInventoryService::finalizeItemsByShippingMethod()
        → Handle payment (online/cod/pay_at_cashier)
    ← Response: order + payment redirect
```

### Public: orders

```
Request → FastShippingController@orders
    → FastShippingService::paginateFastOrders($request)
        → Order::fast()->forUser($user)
            ->with(orderItems.product.media,
                   orderItems.productVariant.attributeProducts.attributeValue)
            ->paginate($limit)
    ← Response: paginated order list
```

---

## Repository Layer (FastShippingRepository)

**File:** `packages/marvel/src/Database/Repositories/FastShippingRepository.php`

Settings are stored in the `settings` table as JSON within the `options` column under the key `fast_shipping`.

### Methods

| Method | Returns | Description |
|--------|---------|-------------|
| `isGloballyEnabled()` | bool | Check if fast shipping is globally enabled |
| `getDurationMinutes()` | int | Get delivery duration (default: 120) |
| `getFee()` | float | Get fast shipping fee (default: 0) |
| `getStartHour()` | string | Get working hours start (default: 08:00) |
| `getEndHour()` | string | Get working hours end (default: 22:00) |
| `isWithinWorkingHours($now)` | bool | Check if current time is within working hours |
| `isGovernorateEnabled($governorate)` | bool | Check if governorate has fast shipping enabled |
| `areProductsFastEligible($cartItems)` | bool | Check if all cart items are fast-shipping eligible |
| `calculateEta($now)` | Carbon | Calculate estimated delivery time |
| `getSettings()` | array | Get all settings (cached for 1 hour) |
| `updateSettings($data)` | Settings | Update settings (DB transaction + cache clear) |
| `getStatus()` | array | Get full status object |
| `validateCheckout($governorate, $cartItems)` | array | Validate all checkout preconditions |
| `getNextAvailableTime()` | ?string | Get next available time if currently unavailable |

### Default Settings

```php
[
    'enabled' => false,
    'duration_minutes' => 120,
    'fee' => 0,
    'start_hour' => '08:00',
    'end_hour' => '22:00',
]
```

---

## Service Layer (FastShippingService)

**File:** `app/Services/General/FastShippingService.php`

### Dependencies

| Dependency | Purpose |
|-----------|---------|
| `FastShippingRepository` | Settings and validation |
| `OrderService` | Cart total calculation |
| `PromotionService` | Promotion eligibility |
| `CartInventoryService` | Cart management and reservation |
| `OrderCreationService` | Order creation in DB |

### Business Rules

1. **Cart must have items** with `shipping_method = FAST`
2. **Governorate must exist** and have `is_fast_shipping_enabled = true`
3. **All cart products** must have `is_fast_shipping_available = true`
4. **Fast shipping must be globally enabled** in settings
5. **Current time must be within working hours**
6. **Coupon is re-validated** if present on cart
7. **Inventory is locked** via `lockForUpdate` during checkout
8. **Order creation uses DB transaction** — rollback on failure

---

## Channel Filtering

### Channel Middleware

```
X-Channel: fast-shipping  →  ChannelContext::isFastShipping() = true
X-Channel: home            →  ChannelContext::isFastShipping() = false
X-Channel: (missing)       →  defaults to 'home'
X-Channel: (invalid)       →  fallback to 'home' (or 400 in strict mode)
```

### FastShippingScope

```php
class FastShippingScope implements Scope
{
    public function apply(Builder $builder, Model $model): void
    {
        if (app(ChannelContext::class)->isFastShipping()) {
            $builder->where('is_fast_shipping_available', true);
        }
    }
}
```

When the X-Channel header is set to `fast-shipping`, the global scope automatically filters ALL product queries to only return fast-shipping-eligible products.

---

## Enums

### ShippingMethod

```php
class ShippingMethod extends Enum
{
    const SCHEDULED = 'SCHEDULED';
    const FAST = 'FAST';
}
```

### Channel

```php
enum Channel: string
{
    case HOME = 'home';
    case FAST_SHIPPING = 'fast-shipping';
}
```

### Permission

```php
const VIEW_FAST_SHIPPING = 'view-fast-shipping';
const UPDATE_FAST_SHIPPING = 'update-fast-shipping';
```

---

## Permissions

Seeded in `database/seeders/PermissionSeeder.php`:

```php
//fast-shipping
'view-fast-shipping',
'update-fast-shipping',
```

| Permission | Endpoint |
|-----------|----------|
| `view-fast-shipping` | GET /api/v1/fast-shipping/settings |
| `update-fast-shipping` | PUT /api/v1/fast-shipping/settings |

---

## Translations

**File:** `resources/lang/{en,ar,de}/checkout.php`

| Key | English | Arabic |
|-----|---------|--------|
| `fast_shipping_unavailable` | Fast shipping is not available at this time. | الشحن السريع غير متاح في هذا الوقت. |
| `fast_shipping_hours_only` | Fast shipping is only available between :start and :end. | الشحن السريع متاح فقط بين :start و :end. |
| `fast_shipping_governorate_unavailable` | Fast shipping is not available in your governorate. | الشحن السريع غير متاح في محافظتك. |
| `fast_shipping_items_ineligible` | One or more items in your cart are not eligible for fast shipping. | واحد أو أكثر من العناصر في سلتك غير مؤهلة للشحن السريع. |

---

## Dependency Graph

```
FastShippingController (Public)
├── FastShippingService
│   ├── FastShippingRepository
│   ├── OrderService
│   ├── PromotionService
│   ├── CartInventoryService
│   └── OrderCreationService
├── CartInventoryService
└── PaymentCheckoutHandler

FastShippingController (Admin)
└── FastShippingRepository
    └── Settings (model)
        └── Cache (fast_shipping_settings, 3600s)

FastShippingScope
└── ChannelContext
    └── config/channel.php

ChannelMiddleware
└── Channel enum
```
