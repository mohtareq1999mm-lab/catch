# Fast Shipping Channel

> **Version:** 1.0  
> **Last updated:** 2026-07-07  
> **Codebase:** Store Customer API (`app/Http/Controllers/Api/General`)

---

## 1. Feature Overview

### What It Does

The Fast Shipping Channel modifies the behaviour of all Product-related API endpoints by transparently adding `WHERE is_fast_shipping_available = 1` to every Product query, when the client sends the `X-Channel: fast-shipping` HTTP header. The filtering is invisible to controllers and services — no code in those layers needs to check the channel.

In addition, a dedicated set of endpoints (`/api/general/fast-shipping/*`) provides:
- Real-time status of the fast shipping service (operating hours, availability, fee).
- A paginated, searchable list of fast-shipping-eligible products.
- A specialised checkout flow for fast shipping orders (MyFatoorah payment, governorate validation, ETA calculation).
- A list of the user's past fast shipping orders.

### Why It Exists

The feature enables an e-commerce store to operate two concurrent "views" of the same product catalogue:
- **Home channel** — shows all products.
- **Fast Shipping channel** — shows only products that can be delivered within a short time window (typically 2 hours), to a specific governorate, during specific operating hours.

This avoids the need to maintain a separate product catalogue or a separate API endpoint. A single set of endpoints changes behaviour based on the header.

### Which Problem It Solves

Before this feature, the frontend had to explicitly filter products by `is_fast_shipping_available = true` on every request. This led to:
- Duplicate filter logic across multiple frontend screens.
- Inconsistent filtering (some screens forgot to filter).
- Tight coupling between frontend and backend filtering concerns.

The Global Scope approach moves the filtering to the database layer, where it is guaranteed to be applied on every Product query, for every endpoint, without exception — as long as the channel header is set.

---

## 2. Architecture

### Component Diagram

```
┌─────────────────────────────────────────────────────────────────────┐
│                        HTTP Request                                │
│                        X-Channel: fast-shipping                     │
└──────────────────────────┬──────────────────────────────────────────┘
                           │
                           ▼
┌─────────────────────────────────────────────────────────────────────┐
│  Kernel / Route                                                      │
│  ├── Global Middleware Stack                                         │
│  │   └── ChannelMiddleware                                           │
│  │       ├── Reads X-Channel header                                  │
│  │       ├── Normalizes (lowercase + trim)                           │
│  │       ├── Validates against Channel enum                          │
│  │       └── Sets ChannelContext singleton                           │
│  └── Route matched                                                   │
└──────────────────────────────────────────────────────────────────────┘
                           │
                           ▼
┌──────────────────────────────────────────────────────────────────────┐
│  Controller                                                          │
│  (e.g., ProductController::index())                                  │
│  └── Calls Service method                                            │
└──────────────────────────────────────────────────────────────────────┘
                           │
                           ▼
┌──────────────────────────────────────────────────────────────────────┐
│  Service                                                             │
│  (e.g., ProductService::buildFilteredBaseQuery())                    │
│  └── Calls Product::query()->active()->...                           │
└──────────────────────────────────────────────────────────────────────┘
                           │
                           ▼
┌──────────────────────────────────────────────────────────────────────┐
│  Eloquent Model :: Product                                           │
│  └── newQuery() is called                                            │
│      └── Global Scopes are applied                                   │
│          └── FastShippingScope::apply()                              │
│              ├── Checks config('channel.enabled')                    │
│              ├── Reads ChannelContext::isFastShipping()              │
│              └── If true: adds WHERE is_fast_shipping_available = 1  │
└──────────────────────────────────────────────────────────────────────┘
                           │
                           ▼
┌──────────────────────────────────────────────────────────────────────┐
│  Database (MySQL / Postgres)                                         │
│  └── Executes query WITH the extra WHERE clause                      │
└──────────────────────────────────────────────────────────────────────┘
                           │
                           ▼
┌──────────────────────────────────────────────────────────────────────┐
│  Result set → Product Models (already filtered)                      │
│      │                                                               │
│      ▼                                                               │
│  API Resource (e.g., ProductMiniResource)                            │
│      │                                                               │
│      ▼                                                               │
│  JSON Response                                                       │
└──────────────────────────────────────────────────────────────────────┘
```

### Layer Responsibilities

| Layer | Class | Responsibility |
|-------|-------|----------------|
| Enum | `App\Enums\Channel` | Defines accepted channel values |
| Context | `App\Contexts\ChannelContext` | Holds the active channel for the current request |
| Middleware | `App\Http\Middleware\ChannelMiddleware` | Reads header, resolves channel, sets context |
| Global Scope | `App\Models\Scopes\FastShippingScope` | Adds WHERE clause to Product queries |
| Registration | `App\Providers\AppServiceProvider` | Registers scope and context singleton |
| Config | `config/channel.php` | Header name, default, strict mode, enabled flag |
| Model | `Marvel\Database\Models\Product` | Has `is_fast_shipping_available` field and `scopeFastShippingAvailable` |
| Controller | `App\Http\Controllers\Api\General\FastShippingController` | Fast-shipping-specific endpoints |
| Service | `App\Services\General\FastShippingService` | Fast-shipping business logic |
| Repository | `Marvel\Database\Repositories\FastShippingRepository` | Fast-shipping settings and validation |
| Service | `App\Services\General\CartInventoryService` | Cart/inventory management (uses Product query with scope) |

---

## 3. Request Flow

### 3.1 Complete Execution Trace

```
Step 1:  Client sends GET /api/general/products
         Header: X-Channel: fast-shipping

Step 2:  Kernel applies global middleware.
         ChannelMiddleware::handle() is called.
           - Reads config('channel.header') → 'X-Channel'
           - Reads $request->header('X-Channel') → 'fast-shipping'
           - Normalizes: 'fast-shipping'
           - Validates: Channel::isValid('fast-shipping') → true
           - Creates: Channel::FAST_SHIPPING
           - Sets: app(ChannelContext::class)->setChannel(Channel::FAST_SHIPPING)

Step 3:  Route matched: GET /api/general/products
         Controller: ProductController@index

Step 4:  ProductController::index($request)
           - No 'type' query param → enters non-strategy path
           - Calls $this->productService->buildFilteredBaseQuery($request)

Step 5:  ProductService::buildFilteredBaseQuery($request)
           - Calls Product::query()->active()
             - Product::query() → Product::newQuery()
               - Laravel applies ALL global scopes
               - FastShippingScope::apply() executes:
                 - config('channel.enabled') → true
                 - ChannelContext::isFastShipping() → true
                 - Adds: $builder->where('is_fast_shipping_available', true)
             - scopeActive() adds:
               - WHERE status = 1
               - AND (in_stock = 1 OR (COALESCE(stock_quantity,0) - COALESCE(reserved_quantity,0)) > 0)
           - with(['categories', 'variations', 'brands'])
           - withAvg(['reviews'], 'rating')
           - withCount(['reviews'])
           - Applies search/filter if present

Step 6:  $query->orderBy('id', 'desc')->paginate($limit)
           - SQL executed:
             SELECT *, (SELECT AVG(rating) FROM reviews WHERE ...) as reviews_avg_rating,
                    (SELECT COUNT(*) FROM reviews WHERE ...) as reviews_count
             FROM products
             WHERE is_fast_shipping_available = 1      ← added by scope
               AND status = 1                           ← added by scopeActive()
               AND (in_stock = 1 OR ...)                ← added by scopeActive()
             ORDER BY id DESC
             LIMIT 15 OFFSET 0

Step 7:  Collection of Product models returned (only fast-shipping-eligible products)

Step 8:  ProductController wraps in ProductCollectionMini, adds filters, returns JSON
```

### 3.2 Middleware Flow Detail

```
ChannelMiddleware::handle($request, $next)
│
├── $context = app(ChannelContext::class)      // scoped singleton
├── $header = config('channel.header')         // 'X-Channel'
├── $value = $request->header($header)
│
├── If $value is null or empty:
│   │   $channel = Channel::tryFrom(config('channel.default', 'home'))
│   │   $context->setChannel($channel)
│   │   return $next($request)
│   │
├── $normalized = strtolower(trim($value))
│
├── If !Channel::isValid($normalized):
│   │   If strict mode: throw BadRequestHttpException
│   │   Else: fallback to default channel
│   │
├── $channel = Channel::tryFrom($normalized) ?? Channel::HOME
│   $context->setChannel($channel)
│   return $next($request)
```

---

## 4. Backend Implementation

### 4.1 Channel Enum

**File:** `app/Enums/Channel.php`

```php
enum Channel: string
{
    case HOME = 'home';
    case FAST_SHIPPING = 'fast-shipping';
}
```

A backed string enum. Two values exist:
- `home` — default, shows all products.
- `fast-shipping` — activates the scope.

`Channel::values()` returns `['home', 'fast-shipping']`.  
`Channel::isValid(?string)` returns `true` if the value is null or one of the accepted values. Null is accepted to allow the middleware to fall back to default without throwing.

### 4.2 ChannelContext

**File:** `app/Contexts/ChannelContext.php`

A plain class registered as a **scoped singleton** in the service container (`AppServiceProvider::register()` line 37):

```php
$this->app->scoped(ChannelContext::class, function () {
    return new ChannelContext();
});
```

This means one instance per request. All components that call `app(ChannelContext::class)` receive the same instance within a single request.

**State:**
- `private Channel $channel` — initialized to `Channel::HOME`.

**Methods:**
- `setChannel(Channel)` — used by the middleware.
- `getChannel(): Channel` — returns the current channel.
- `isFastShipping(): bool` — returns `true` when channel is `FAST_SHIPPING`.
- `isHome(): bool` — returns `true` when channel is `HOME`.

### 4.3 ChannelMiddleware

**File:** `app/Http/Middleware/ChannelMiddleware.php`

Registered globally (typically in `Kernel.php`). This middleware runs on every API request.

**Behaviour table:**

| X-Channel header | Strict mode | Result |
|-----------------|-------------|--------|
| Not sent | any | Channel defaults to `home` |
| `home` | any | Channel = HOME |
| `fast-shipping` | any | Channel = FAST_SHIPPING |
| `FAST-SHIPPING` | any | Normalized to `fast-shipping` |
| `invalid-value` | `false` | Falls back to `home` (no error) |
| `invalid-value` | `true` | 400 Bad Request |
| Empty string | any | Treated as not sent, defaults to `home` |

### 4.4 FastShippingScope

**File:** `app/Models/Scopes/FastShippingScope.php`

This is the core of the feature. It implements `Illuminate\Database\Eloquent\Scope`.

```php
public function apply(Builder $builder, Model $model): void
{
    if (!config('channel.enabled', true)) {
        return;                              // Feature disabled globally
    }
    $context = app(ChannelContext::class);
    if ($context->isFastShipping()) {
        $builder->where('is_fast_shipping_available', true);
    }
}
```

**Why Global Scope was chosen:**
- Guarantees the filter is applied to **every** Product query, including relationship queries, eager loading, and `find()`.
- Zero code changes needed in controllers or services.
- Cannot be forgotten by developers adding new endpoints.
- The entire feature can be disabled via `config('channel.enabled')` without touching any endpoint code.

### 4.5 Registration

**File:** `app/Providers/AppServiceProvider.php`

Two registrations happen in this file:

1. **ChannelContext scoped singleton** (line 37-39):
   ```php
   $this->app->scoped(ChannelContext::class, function () {
       return new ChannelContext();
   });
   ```

2. **Global scope on Product** (line 71):
   ```php
   Product::addGlobalScope(new FastShippingScope());
   ```

### 4.6 Product Model Changes

**File:** `packages/marvel/src/Database/Models/Product.php`

**Field:**
- `is_fast_shipping_available` is in `$fillable` (line 47).

**Cast:**
- `is_fast_shipping_available => 'boolean'` (line 84).

**Scope:**
```php
public function scopeFastShippingAvailable($query)
{
    return $query->where('is_fast_shipping_available', true);
}
```
This scope is used explicitly in `FastShippingService::getFastShippingProducts()` (line 39) for the dedicated products endpoint, but is **not** needed generally because the global scope handles it.

### 4.7 Configuration

**File:** `config/channel.php`

| Key | Type | Default | Env variable | Description |
|-----|------|---------|-------------|-------------|
| `default` | string | `'home'` | `CHANNEL_DEFAULT` | Channel when no header is sent |
| `accepted` | array | `['home', 'fast-shipping']` | — | List of valid channel values |
| `header` | string | `'X-Channel'` | `CHANNEL_HEADER` | HTTP header name |
| `strict` | bool | `false` | `CHANNEL_STRICT` | If true, invalid headers throw 400 |
| `enabled` | bool | `true` | `CHANNEL_ENABLED` | Master switch for the entire feature |

### 4.8 FastShippingController

**File:** `app/Http/Controllers/Api/General/FastShippingController.php`

| Method | Route | Auth | Description |
|--------|-------|------|-------------|
| `status()` | `GET /api/general/fast-shipping/status` | No | Returns service status (enabled, available, hours, fee, ETA) |
| `products()` | `GET /api/general/fast-shipping/products` | No | Paginated list of fast-shipping-eligible products |
| `checkout()` | `POST /api/general/checkout/fast` | Yes | Creates a fast shipping order |
| `orders()` | `GET /api/general/fast-shipping/orders` | Yes | Lists user's fast shipping orders |

### 4.9 FastShippingService

**File:** `app/Services/General/FastShippingService.php`

**Dependencies:**
- `FastShippingRepository` — settings, validation, ETA.
- `PromotionService` — promotion eligibility and application.
- `CartInventoryService` — cart reservation and stock management.

**Key methods:**

| Method | Description |
|--------|-------------|
| `getStatus()` | Delegates to `FastShippingRepository::getStatus()` |
| `getFastShippingProducts($request)` | Product query with `->active()->fastShippingAvailable()` |
| `createFastOrder($request)` | Full checkout flow: validate cart, governorate, promotion, create order, create order items |
| `paginateFastOrders($request)` | Orders with `->fast()->forUser($userId)` |

### 4.10 FastShippingRepository

**File:** `packages/marvel/src/Database/Repositories/FastShippingRepository.php`

Reads settings from `Settings` model under `options.fast_shipping` key.

**Settings managed:**

| Setting | Key | Default |
|---------|-----|---------|
| Enabled | `enabled` | `false` |
| Duration (minutes) | `duration_minutes` | `120` |
| Fee | `fee` | `0` |
| Start hour | `start_hour` | `08:00` |
| End hour | `end_hour` | `22:00` |

**Key methods:**

| Method | Description |
|--------|-------------|
| `isGloballyEnabled()` | Reads `enabled` setting |
| `isWithinWorkingHours()` | Checks if current time is between start and end hour |
| `isGovernorateEnabled($governorate)` | Checks governorate's `is_fast_shipping_enabled` flag |
| `areProductsFastEligible($cartItems)` | Verifies all items have `is_fast_shipping_available = true` |
| `calculateEta()` | Now + duration_minutes |
| `getStatus()` | Assembled status payload |
| `validateCheckout($governorate, $cartItems)` | Runs all validation checks, returns error array |

### 4.11 Affected Controllers (General API)

The FastShippingScope silently affects ALL controllers that query Products. No code changes were needed in these controllers:

| Controller | Endpoint | Effect when X-Channel: fast-shipping |
|-----------|----------|--------------------------------------|
| `HomeController` | `GET /general/home` | Only fast-shipping products in discount/new/flash-sale sections |
| `ProductController` | `GET /general/products` | Only fast-shipping products |
| `ProductController` | `GET /general/products/{slug}` | 404 if product not fast-shipping-eligible |
| `FlashSaleController` | `GET /general/flash-sales` | Only flash sales with fast-shipping products |
| `CategoryController` | `GET /general/categories/{slug}` | Only fast-shipping products in category |
| `BrandController` | `GET /general/brands/{slug}` | Only fast-shipping products in brand |
| `BrandController` | `GET /general/brands-with-products` | Only fast-shipping products |
| `BannerController` | `GET /general/banners/{slug}` | Only fast-shipping products in banner |
| `SliderController` | `GET /general/sliders/{slug}` | Only fast-shipping products in slider |
| `PromotionController` | `GET /general/promotions/{slug}` | Only fast-shipping products in promotion |
| `SearchController` | `GET /general/search` | **Broken** — service method is empty |
| `FastShippingController` | `GET /general/fast-shipping/products` | Explicit `->fastShippingAvailable()` adds redundant filter |

### 4.12 Cache

**File:** `app/Services/General/HomeService.php`

The `getHomeData()` method caches results using `Cache::remember()` with a 120-second TTL. Five cache keys store Product data **without** channel context:

| Cache Key | Contains | Bug |
|-----------|----------|-----|
| `home-discount-products-end-today` | `ProductMiniResource` collection | Cross-channel cache pollution |
| `home-flash-sale-products` | `ProductMiniResource` collection | Cross-channel cache pollution |
| `home-weekly-products` | `ProductMiniResource` collection | Cross-channel cache pollution |
| `home-all-discount-products` | `ProductMiniResource` collection | Cross-channel cache pollution |
| `home-flash-sales-after-9` | `ProductMiniResource` collection | Cross-channel cache pollution |

**Impact:** The first request (regardless of channel) caches Product data. Subsequent requests from a different channel receive the wrong data.

**Fix:** Append the channel to the cache key, e.g.:
```php
$channel = app(ChannelContext::class)->getChannel()->value;
Cache::remember("home-discount-products-end-today:{$channel}", 120, fn() => ...);
```

### 4.13 Search (Scout / Meilisearch)

**File:** `app/Services/General/ProductService.php`

Flow:
1. `Product::search($term)->keys()` queries Meilisearch **without** FastShippingScope.
2. IDs returned from Meilisearch.
3. `Product::query()->active()->whereIn('id', $scoutIds)` re-queries with scope applied.

The scope is applied at step 3. Any product that is NOT fast-shipping-eligible will be excluded from results even if it matched in Meilisearch.

### 4.14 Relationships

All `BelongsToMany` and `BelongsTo` relationships on Product use `Product::newQuery()` internally, which applies FastShippingScope. For example:

- `Category::with('products')->get()` → products filtered.
- `Brand::with('products')->get()` → products filtered.
- `Banner::with('products')->get()` → products filtered.
- `$category->products` → products filtered.
- `$cartItem->product` → product filtered (affects `lockForUpdate()`).

---

## 5. Frontend Integration

### 5.1 Required Header

| Header | Value | Required |
|--------|-------|----------|
| `X-Channel` | `home` or `fast-shipping` | No (defaults to `home`) |

### 5.2 Axios Example

```javascript
// Set globally for all requests
axios.defaults.headers.common['X-Channel'] = 'fast-shipping';

// Or per-request
axios.get('/api/general/products', {
  headers: { 'X-Channel': 'fast-shipping' }
});
```

### 5.3 Fetch Example

```javascript
fetch('/api/general/products', {
  headers: {
    'X-Channel': 'fast-shipping'
  }
});
```

### 5.4 Postman

1. Go to the **Headers** tab.
2. Add a new row:
   - **Key:** `X-Channel`
   - **Value:** `fast-shipping`
3. Send the request.

### 5.5 Behaviour Without Header

If `X-Channel` is not sent, the server defaults to `home` channel. All products are returned without the `is_fast_shipping_available` filter.

### 5.6 Switching Channels

The frontend can switch between channels at any time by changing the header value. There is no session or token affinity — each request is independent.

To show the "full catalogue" for comparison/fallback:
```javascript
fetch('/api/general/products', {
  headers: { 'X-Channel': 'home' }
});
```

To show only fast-shipping products:
```javascript
fetch('/api/general/products', {
  headers: { 'X-Channel': 'fast-shipping' }
});
```

---

## 6. API Behaviour

### 6.1 Normal Mode (X-Channel: home or not sent)

- All Product queries return all active products.
- No `WHERE is_fast_shipping_available` constraint.
- `GET /api/general/fast-shipping/status` returns the service status (may be enabled/disabled).
- `GET /api/general/fast-shipping/products` returns products filtered by `is_fast_shipping_available = true` (this endpoint always applies its own explicit scope regardless of channel).

### 6.2 Fast Shipping Mode (X-Channel: fast-shipping)

- **All** Product queries implicitly add `WHERE is_fast_shipping_available = 1`.
- Products without `is_fast_shipping_available = true` are invisible to all General API endpoints.
- Category product counts reflect only fast-shipping-eligible products.
- Search (Scout) may return fewer results than the Meilisearch index suggests (non-eligible products are filtered out at the Eloquent stage).
- `GET /api/general/fast-shipping/products` works identically (its explicit scope is redundant with the global scope).

### 6.3 Affected Endpoints

| Endpoint | Normal Mode | Fast Shipping Mode |
|----------|-------------|-------------------|
| `GET /general/products` | All active products | Only fast-shipping-eligible products |
| `GET /general/products/{slug}` | Any active product | 404 if product is not fast-shipping-eligible |
| `GET /general/home` | All sections with all products | Sections filtered to fast-shipping products |
| `GET /general/flash-sales` | All flash sales | Flash sales with fast-shipping products |
| `GET /general/flash-sales/{slug}` | All related products | Only fast-shipping products in response |
| `GET /general/categories` | Category list (no Products) | Unaffected |
| `GET /general/categories/{slug}` | All category products | Only fast-shipping products |
| `GET /general/brands/{slug}` | All brand products | Only fast-shipping products |
| `GET /general/banners/{slug}` | All banner products | Only fast-shipping products |
| `GET /general/sliders/{slug}` | All slider products | Only fast-shipping products |
| `GET /general/promotions/{slug}` | All promotion products | Only fast-shipping products |
| `POST /general/checkout` | Normal checkout | Cart product lookup may fail |
| `POST /general/checkout/fast` | Fast checkout | Unaffected |
| `GET /general/fast-shipping/products` | Fast-shipping products only | Same |
| `GET /general/fast-shipping/status` | Service status | Same |
| `GET /general/search` | **Broken** | **Broken** |

### 6.4 Not Affected Endpoints

| Endpoint | Reason |
|----------|--------|
| `GET /general/settings` | Does not query Products |
| `GET /general/faqs` | Does not query Products |
| `GET /general/content-pages` | Does not query Products |
| `GET /general/coupons` | Does not query Products |
| `GET /general/fast-shipping/orders` | Queries Order, not Product |
| `GET /general/orders` | Queries Order, not Product |

---

## 7. Configuration

### 7.1 Environment Variables

| Variable | Default | Description |
|----------|---------|-------------|
| `CHANNEL_DEFAULT` | `home` | Default channel when header is absent |
| `CHANNEL_HEADER` | `X-Channel` | HTTP header name |
| `CHANNEL_STRICT` | `false` | Throw 400 on invalid header |
| `CHANNEL_ENABLED` | `true` | Master switch for the feature |

### 7.2 Config File

**File:** `config/channel.php`

```php
return [
    'default'  => env('CHANNEL_DEFAULT', 'home'),
    'accepted' => ['home', 'fast-shipping'],
    'header'   => env('CHANNEL_HEADER', 'X-Channel'),
    'strict'   => env('CHANNEL_STRICT', false),
    'enabled'  => env('CHANNEL_ENABLED', true),
];
```

### 7.3 Fast Shipping Service Settings

Stored in `Settings` model under `options.fast_shipping` key. Managed via admin panel or `FastShippingRepository::updateSettings()`.

| Setting | Key | Default | Description |
|---------|-----|---------|-------------|
| Enabled | `enabled` | `false` | Master switch for fast shipping service |
| Duration | `duration_minutes` | `120` | Delivery window in minutes |
| Fee | `fee` | `0` | Additional fee for fast shipping |
| Opens at | `start_hour` | `08:00` | Service start time (24h format) |
| Closes at | `end_hour` | `22:00` | Service end time (24h format) |

---

## 8. Sequence Diagram

```
Client                    ChannelMiddleware         ChannelContext          FastShippingScope          Database
  │                              │                       │                       │                       │
  │  GET /api/general/products   │                       │                       │                       │
  │  X-Channel: fast-shipping    │                       │                       │                       │
  │─────────────────────────────►│                       │                       │                       │
  │                              │                       │                       │                       │
  │                              │  app(ChannelContext)   │                       │                       │
  │                              │──────────────────────►│                       │                       │
  │                              │                       │                       │                       │
  │                              │  setChannel(FAST_SHIPPING)                    │                       │
  │                              │──────────────────────►│                       │                       │
  │                              │                       │                       │                       │
  │                              │  return $next(request)                        │                       │
  │                              │──────────────────────────────────────────────►│ (to controller)       │
  │                              │                       │                       │                       │
  │  (Controller receives request)                       │                       │                       │
  │                              │                       │                       │                       │
  │  Product::query()            │                       │                       │                       │
  │──────────────────────────────────────────────────────►│                       │                       │
  │                              │                       │                       │                       │
  │                              │                       │  isFastShipping()      │                       │
  │                              │                       │◄──────────────────────│                       │
  │                              │                       │  true                 │                       │
  │                              │                       │──────────────────────►│                       │
  │                              │                       │                       │                       │
  │                              │                       │  where(is_fast_shipping_available, true)       │
  │                              │                       │                       │──────────────────────►│
  │                              │                       │                       │                       │
  │                              │                       │                       │  SELECT * FROM products│
  │                              │                       │                       │  WHERE                 │
  │                              │                       │                       │    is_fast_shipping_   │
  │                              │                       │                       │    available = 1        │
  │                              │                       │                       │    AND status = 1      │
  │                              │                       │                       │    AND (...in_stock...)│
  │                              │                       │                       │◄──────────────────────│
  │                              │                       │                       │                       │
  │  JSON Response (filtered)    │                       │                       │                       │
  │◄─────────────────────────────│                       │                       │                       │
  │                              │                       │                       │                       │
```

---

## 9. Developer Notes

### 9.1 Why Global Scope Was Chosen

The alternative was to add `->where('is_fast_shipping_available', true)` in every service method that queries Products. This would have required changes to ~30 methods. The Global Scope approach:

- Guarantees 100% coverage — no endpoint can forget the filter.
- Makes the feature transparent to developers adding new endpoints.
- Can be toggled with a single config flag.
- Follows Laravel's intended pattern for multi-tenancy and soft-deletes.

### 9.2 Why Middleware Is Used

The middleware intercepts the request **before** any controller or service code runs. This ensures ChannelContext is set before any `Product::query()` call executes. The alternative (reading the header in a ServiceProvider) would be too late for the first query in the request lifecycle.

### 9.3 Why Context Exists

`ChannelContext` decouples the middleware from the scope. The middleware only sets context. The scope only reads context. They never directly reference each other. This allows:

- Unit testing the scope without HTTP (you can manually `setChannel()`).
- Adding more channels without changing middleware logic.
- Changing the header name without changing scope logic.

### 9.4 How Future Channels Can Be Added

1. Add a new case to `App\Enums\Channel`:
   ```php
   case PREMIUM = 'premium';
   ```

2. Add to `config/channel.php` `accepted` array:
   ```php
   'accepted' => ['home', 'fast-shipping', 'premium'],
   ```

3. Create a new class implementing `Illuminate\Database\Eloquent\Scope`:
   ```php
   class PremiumScope implements Scope
   {
       public function apply(Builder $builder, Model $model): void
       {
           if (app(ChannelContext::class)->getChannel() === Channel::PREMIUM) {
               $builder->where('is_premium', true);
           }
       }
   }
   ```

4. Register in `AppServiceProvider::boot()`:
   ```php
   Product::addGlobalScope(new PremiumScope());
   ```

### 9.5 Known Limitations

- **Cache pollution:** 5 cache keys in `HomeService` lack channel context (see section 4.12).
- **Inventory lock under fast-shipping:** `CartInventoryService::lockInventoryRow()` uses `Product::query()` which applies the scope. If a product's `is_fast_shipping_available` flag changes between cart-add and checkout, `lockForUpdate()` will throw `ModelNotFoundException`. Consider using `Product::withoutGlobalScope(FastShippingScope::class)` for inventory operations.
- **Promotion gift products:** Same issue as inventory lock — `PromotionApplicator::applyOutcome()` line 143 uses `Product::query()->lockForUpdate()` under scope.
- **Search endpoint:** `SearchService::search()` is empty (returns `null`). The `GET /api/general/search` endpoint is broken.
- **Duplicate business logic:** `HomeService` duplicates methods from `ProductService` (e.g., `getNewArrivals`, `getAllDiscountProducts`). Changes may need to be applied in both places.

### 9.6 Testing Considerations

- **Unit tests for FastShippingScope:** Create `ChannelContext` instance, set channel, call `apply()`, assert `where` clause added.
- **Feature tests:** Send request with and without `X-Channel` header, assert Product count differs.
- **Caveat:** When using `RefreshDatabase`, the `ChannelContext` state from a previous test may leak. Reset in `setUp()`:
  ```php
  app(ChannelContext::class)->setChannel(Channel::HOME);
  ```

---

## 10. QA Guide

### 10.1 Positive Test Cases

**TC-01: Fast Shipping products are returned with header**
```
Given: X-Channel: fast-shipping
When:  GET /api/general/products
Then:  All returned products have is_fast_shipping_available = true
       Count matches products WHERE is_fast_shipping_available = 1
```

**TC-02: All products are returned without header**
```
Given: No X-Channel header
When:  GET /api/general/products
Then:  All active products returned, including non-fast-shipping ones
```

**TC-03: Single product lookup with header**
```
Given: X-Channel: fast-shipping
When:  GET /api/general/products/{slug of fast-shipping product}
Then:  200 with product data
```

**TC-04: Single product lookup without header**
```
Given: No X-Channel header
When:  GET /api/general/products/{slug of non-fast-shipping product}
Then:  200 with product data
```

**TC-05: Fast shipping status endpoint**
```
When:  GET /api/general/fast-shipping/status
Then:  200 with {enabled, available, duration_minutes, fee, opens_at, closes_at}
```

**TC-06: Fast shipping products endpoint**
```
When:  GET /api/general/fast-shipping/products
Then:  200 with paginated list, all products have is_fast_shipping_available = true
```

### 10.2 Negative Test Cases

**TC-07: Single product lookup with header — non-fast-shipping product**
```
Given: X-Channel: fast-shipping
When:  GET /api/general/products/{slug of non-fast-shipping product}
Then:  404 Not Found
```

**TC-08: Invalid channel header (non-strict mode)**
```
Given: X-Channel: invalid-value, CHANNEL_STRICT=false
When:  GET /api/general/products
Then:  200 — falls back to home channel, all products returned
```

**TC-09: Invalid channel header (strict mode)**
```
Given: X-Channel: invalid-value, CHANNEL_STRICT=true
When:  GET /api/general/products
Then:  400 Bad Request with error message
```

**TC-10: Category with non-fast-shipping products under fast-shipping channel**
```
Given: X-Channel: fast-shipping
When:  GET /api/general/categories/{slug}
Then:  Category returned, products_count only counts fast-shipping-eligible products
       Products array only contains fast-shipping-eligible products
```

### 10.3 Header Validation Test Cases

| Test | Header value | Expected result |
|------|-------------|-----------------|
| Lowercase | `fast-shipping` | Accepted |
| Uppercase | `FAST-SHIPPING` | Normalized to `fast-shipping` |
| Mixed case | `Fast-Shipping` | Normalized to `fast-shipping` |
| With spaces | ` fast-shipping ` | Trimmed to `fast-shipping` |
| Empty | `` | Treated as absent, defaults to `home` |
| Null | (not sent) | Defaults to `home` |
| Invalid | `premium` | Falls back to `home` (or 400 if strict) |

### 10.4 Expected API Response Patterns

**Success (normal products endpoint):**
```json
{
    "success": true,
    "message": "Data fetched successfully",
    "data": [
        {
            "id": 1,
            "name": "Product Name",
            "is_fast_shipping_available": true,
            ...
        }
    ],
    "meta": { "current_page": 1, "last_page": 5, "total": 48 }
}
```

**Success (fast-shipping status):**
```json
{
    "success": true,
    "message": "Data fetched successfully",
    "data": {
        "enabled": true,
        "available": true,
        "duration_minutes": 120,
        "fee": 15.00,
        "opens_at": "08:00",
        "closes_at": "22:00",
        "available_again_at": null
    }
}
```

**Error (strict mode invalid header):**
```json
{
    "error": "Invalid channel \"invalid\". Accepted values: home, fast-shipping."
}
```

---

## 11. Frontend Quick Guide

### How to Enable Fast Shipping in 3 Steps

#### Step 1: Send the Header

Add `X-Channel: fast-shipping` to every API request.

**Axios (global):**
```javascript
import axios from 'axios';

axios.defaults.headers.common['X-Channel'] = 'fast-shipping';
```

**Axios (per-request):**
```javascript
axios.get('/api/general/products', {
  headers: { 'X-Channel': 'fast-shipping' }
});
```

#### Step 2: Check Availability

Call the status endpoint before showing the fast shipping UI:

```javascript
const response = await axios.get('/api/general/fast-shipping/status');
const { available, fee, duration_minutes, opens_at, closes_at } = response.data.data;

if (available) {
  // Show "Fast Shipping" option to the user
  // Display: fee, estimated delivery time, working hours
} else {
  // Hide or disable fast shipping option
  // Optionally show: "Available again at {available_again_at}"
}
```

#### Step 3: All Products Are Automatically Filtered

Once `X-Channel: fast-shipping` is set, every product API call returns only fast-shipping-eligible products. The frontend does NOT need to:
- Add `is_fast_shipping_available` filters.
- Hide non-fast-shipping products manually.
- Maintain separate product lists.

### What Changes on the Frontend

| Page | Before (home channel) | After (fast-shipping channel) |
|------|----------------------|-------------------------------|
| Product listing | All products | Only fast-shipping products |
| Product detail | Any product | 404 if not fast-shipping |
| Category page | All category products | Only fast-shipping products in category |
| Search | All matching products | Only fast-shipping among matches |
| Cart | Any product | (cart is independent of channel) |
| Checkout | Normal flow | Use `POST /api/general/checkout/fast` |
| Home page | All sections | Sections show fewer products |

### Need to Show All Products (for comparison)?

Temporarily switch to home channel on specific requests:

```javascript
// Show full catalogue (bypass fast-shipping filter)
const fullCatalogue = await axios.get('/api/general/products', {
  headers: { 'X-Channel': 'home' }
});
```

---

*End of documentation — generated from actual source code at commit-level inspection.*
