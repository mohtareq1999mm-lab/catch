# Promotion Module — Backend Architecture

## Overview

The Promotion module manages promotional offers on the platform. Promotions are cart-level, separate from product-level pricing (ProductPricingService). Only one promotion per order (no stacking). Promotions are applied before coupons. The system uses a Strategy Pattern with three strategy types: Percentage, Fixed Rate, and Gift.

All monetary calculations use integer cents to avoid float rounding errors. Proportional allocation with the largest remainder method is used for discount distribution across matched cart items.

## Endpoints

### Admin API (`/api/v1/promotions`)

| Method | URL | Auth | Permission | Purpose |
|--------|-----|------|------------|---------|
| GET | `/api/v1/promotions` | `auth:sanctum` | `view-promotion` | List promotions (paginated, filterable, sortable) |
| POST | `/api/v1/promotions` | `auth:sanctum` | `create-promotion` | Create a new promotion |
| GET | `/api/v1/promotions/{id}` | `auth:sanctum` | `view-promotion` | Show promotion by ID |
| PUT | `/api/v1/promotions/{id}` | `auth:sanctum` | `update-promotion` | Update promotion |
| DELETE | `/api/v1/promotions/{id}` | `auth:sanctum` | `delete-promotion` | Delete promotion |

### Vendor API (scoped)

| Method | URL | Auth | Purpose |
|--------|-----|------|---------|
| PUT | `/api/v1/promotions/{id}` | `auth:sanctum` | Update promotion (vendor scope) |

### Store Owner API (scoped)

| Method | URL | Auth | Purpose |
|--------|-----|------|---------|
| POST | `/api/v1/promotions` | `auth:sanctum` | Create promotion |
| DELETE | `/api/v1/promotions/{id}` | `auth:sanctum` | Delete promotion |

### Public API (`/api/v1/general/promotions`)

| Method | URL | Auth | Purpose |
|--------|-----|------|---------|
| GET | `/api/v1/general/promotions` | Public | List valid promotions (filterable by date/IDs/slug) |
| GET | `/api/v1/general/promotions/{slug}` | Public | Get promotion by slug with enriched products |

### Checkout API (`/api/v1/checkout/promotions`)

| Method | URL | Auth | Purpose |
|--------|-----|------|---------|
| GET | `/api/v1/checkout/promotions` | `auth:sanctum` | Get eligible promotions for current user's cart |

## Route Definitions

### Admin Routes
**File:** `packages/marvel/src/Rest/Routes.php`

```
Line 238: Route::apiResource('promotions', PromotionController::class);                    // Full CRUD (super admin)
Line 628: Route::apiResource('promotions', PromotionController::class, ['only' => ['update']]);  // Vendor scope
Line 688: Route::apiResource('promotions', PromotionController::class, ['only' => ['store', 'destroy']]);  // Store owner scope
```

Authentication middleware and permission middleware are applied in the controller constructor, not at the route level.

### Public Routes
**File:** `routes/api.php`

```
Line 54: Route::get('promotions', [PromotionController::class, 'index']);                              // Prefix: /api/v1/general
Line 55: Route::get('promotions/{slug}', [PromotionController::class, 'getPromotionBySlug']);          // Prefix: /api/v1/general
```

These are nested within a `Route::prefix('general')` group.

### Checkout Route
**File:** `routes/api.php`

```
Line 77: Route::get('checkout/promotions', [OrderController::class, 'eligiblePromotions'])->middleware('auth:sanctum');
```

## Middleware

### Admin Controller (`Marvel\Http\Controllers\PromotionController`)

| Method | Middleware |
|--------|-----------|
| `index` | `permission:view-promotion` (via constructor) |
| `show` | `permission:view-promotion` (via constructor) |
| `store` | `permission:create-promotion` (via constructor) |
| `update` | `permission:update-promotion` (via constructor) |
| `destroy` | `permission:delete-promotion` (via constructor) |

Auth (`auth:sanctum`) is applied at the route group level in `Routes.php`.

### Public Controller (`App\Http\Controllers\Api\General\PromotionController`)

No middleware — fully public access.

## Controller Flow

### Admin Controller (`Marvel\Http\Controllers\PromotionController`)
**File:** `packages/marvel/src/Http/Controllers/PromotionController.php`

```
GET /promotions
  → PromotionController@index(Request)
    → $this->repository (PromotionRepository)
      → Apply filters: search(name/code/type), status, type, type_amount
      → orderBy($orderBy, $sort)
    → $query->paginate($limit)
    → PromotionResource::collection($promotions)
    → $this->apiResponse(FETCH_DATA_SUCCESSFULLY, 200, true, [...pagination data...])

POST /promotions
  → PromotionController@store(PromotionRequest)
    → $this->repository->storePromotion($request)
      → Extract data from request
      → Normalize promotion data (sync value/discount)
      → Generate slug via makeSlug()
      → Create promotion
      → Sync products (product_ids) and gift products (gift_products/gift_product_ids)
      → Upload desktop image (collection: promotions-desktop, disk: promotions)
      → Upload mobile image (collection: promotions-mobile, disk: promotions)
      → On failure: log, throw MarvelBadRequestException
    → PromotionResource::make($promotion)
    → $this->apiResponse(CREATED_PROMOTION_SUCCESSFULLY, 201, true, ...)

GET /promotions/{id}
  → PromotionController@show($id)
    → $this->repository->findOrFail($id)
    → PromotionResource::make($promotion)
    → $this->apiResponse(FETCH_DATA_SUCCESSFULLY, 200, true, ...)
    → On failure: 404

PUT /promotions/{id}
  → PromotionController@update(UpdatePromotionRequest, $id)
    → $this->repository->updatePromotion($id, $request)
      → Find promotion
      → Extract data from request
      → Normalize promotion data
      → Regenerate slug via makeSlug()
      → Update promotion
      → Sync products and gift products
      → Update desktop/mobile images if provided
      → On failure: log, throw MarvelBadRequestException
    → $promotion->load('products')
    → PromotionResource::make($promotion)
    → $this->apiResponse(UPDATED_PROMOTION_SUCCESSFULLY, 200, true, ...)

DELETE /promotions/{id}
  → PromotionController@destroy($id)
    → $this->repository->findOrFail($id)
    → $promotion->delete()
    → $this->apiResponse(DELETED_PROMOTION_SUCCESSFULLY, 200, true)
    → On failure: 404
```

### Public Controller (`App\Http\Controllers\Api\General\PromotionController`)
**File:** `app/Http/Controllers/Api/General/PromotionController.php`

```
GET /general/promotions
  → PromotionController@index(Request)
    → If slug query param: delegate to getPromotionBySlug($slug)
      → PromotionDataService::getPromotionBySlug($slug)
        → Promotion::search('slug', $slug, locale)->first()
        → Load products with channel filter
        → Enrich with pricing
      → PromotionResource::make($promotion)
    → Else:
      → PromotionDataService::paginatePromotion($request)
        → Promotion::valid()
          → Filter by start_date / end_date / promotionsId (comma-separated or array)
          → Order by id (default desc), limit (default 10)
      → PromotionResource::collection($promotions)
    → $this->apiResponse(FETCH_DATA_SUCCESSFULLY, 200, true, ...)

GET /general/promotions/{slug}
  → PromotionController@getPromotionBySlug($slug)
    → PromotionDataService::getPromotionBySlug($slug)
      → Promotion::search('slug', $slug, locale)->first()
      → Load products with channel filter
      → Enrich with pricing via ProductService::enrichCollectionWithPricing()
    → PromotionResource::make($promotion)
    → If not found: 404
```

## Repository

**File:** `packages/marvel/src/Database/Repositories/PromotionRepository.php`
**Extends:** `BaseRepository`

| Method | Description |
|--------|-------------|
| `model()` | Returns `Promotion::class` |
| `boot()` | Pushes `RequestCriteria` for search/filter |
| `storePromotion($request)` | Transactional create with slug, product/gift sync, image upload |
| `updatePromotion($id, $request)` | Transactional update with slug regeneration, product/gift sync, image update |
| `normalizePromotionData($data)` | Syncs `value` and `discount` fields (both must have same value) |
| `syncPromotionProducts($promotion, $request)` | Syncs `product_ids`, `gift_product_ids`, and `gift_products` |

### `storePromotion()` Flow
```
1. Extract $data from request (via $this->dataArray)
2. Generate slug via $this->makeSlug($request)
3. Normalize promotion data (sync value = discount)
4. $this->create($data)
5. syncPromotionProducts($promotion, $request)
   - If product_ids: $promotion->products()->sync($product_ids)
   - If gift_product_ids: sync with quantity=1, variant_id=null
   - If gift_products: sync with per-product quantity + variant_id (validates variant belongs to product)
6. If image-desktop: uploadSingleImage($request, 'image-desktop', $promotion, 'promotions-desktop', 'promotions')
7. If image-mobile: uploadSingleImage($request, 'image-mobile', $promotion, 'promotions-mobile', 'promotions')
8. Return $promotion

On error:
  - MarvelBadRequestException(400): Image upload failed
  - MarvelBadRequestException(400): Generic failure (log + throw)
```

### `updatePromotion()` Flow
```
1. Find promotion by ID
2. Extract $data from request
3. Normalize promotion data (sync value = discount)
4. Regenerate slug via makeSlug() with update ID
5. $promotion->update($data)
6. syncPromotionProducts($promotion, $request)
7. If image-desktop: updateSingleImage() [clears collection, uploads new]
8. If image-mobile: updateSingleImage() [clears collection, uploads new]
9. Return $promotion

On error:
  - MarvelBadRequestException(400): Not found or generic failure
```

## Model

**File:** `packages/marvel/src/Database/Models/Promotion.php`
**Table:** `promotions`
**Traits:** `HasTranslations`, `InteractsWithMedia`, `Sluggable`
**Implements:** `HasMedia`

| Property | Details |
|----------|---------|
| Translatable | `name` |
| Sluggable | Source: `name` |
| Fillable | `name`, `slug`, `type`, `type_amount`, `value`, `discount`, `max_discount_amount`, `code`, `required_quantity_type`, `minimum_order_amount`, `apply_to`, `limiter`, `usage`, `start_at`, `end_at`, `status` |
| Casts | `start_at`/`end_at` → date, `status` → boolean, `usage`/`limiter`/`required_quantity_type` → integer, `value`/`discount`/`minimum_order_amount`/`max_discount_amount` → float |
| Media Collections | `promotions-desktop`, `promotions-mobile` |

### Scopes

| Scope | Description |
|-------|-------------|
| `scopeActive($q)` | `where('status', true)` |
| `scopeValid($q)` | `where('status', true)` + usage < limiter (or null limiter) + start_at <= today + end_at >= today |
| `scopeSearch($q, $field, $term, $locale)` | Searches with `like` |

### Model Events (boot)

| Event | Behavior |
|-------|----------|
| `addGlobalScope('order')` | Default order by `created_at desc` |
| `creating` | Auto-generate `code` if empty |
| `saving` | Sync `value` ⟷ `discount` (both fields always kept equal) |

### Helper Methods

| Method | Description |
|--------|-------------|
| `isValid()` | Checks status + date range + usage < limiter |
| `isGiftPromotion()` | `type_amount === PromotionMountType::GIFT` |
| `isPercentagePromotion()` | `type_amount === PromotionMountType::PERCENTAGE` |
| `isFixedRatePromotion()` | `type_amount === PromotionMountType::FIXED_RATE` |
| `isRequiredQuantityTrue($qty)` | Checks if cart quantity meets required_quantity_type |
| `discountAmount($price, $qty)` | Calculates discount amount (with percentage cap) |
| `calcPrice($price, $qty)` | Returns price after discount |
| `appliesToAllProducts()` | `apply_to === 'all_products'` |
| `applyGift($qty)` | Returns associated products for gift promotions |
| `typeByLang()` | Returns localized type description |
| `generateUniqueCode($promotion)` | Auto-generates unique code with prefix (ALL/PRO) |

### Relationships

| Relation | Type | Pivot | Foreign |
|----------|------|-------|---------|
| `products()` | BelongsToMany | `promotion_product` (`promotion_id`, `product_id`) | `product_id` |
| `giftProducts()` | BelongsToMany | `promotion_gift_products` (`promotion_id`, `product_id`, `quantity`, `product_variant_id`) | `product_id` |

## Promotion Engine Architecture

The Promotion Engine is a Strategy Pattern system with the following components:

### Strategy Interface (`PromotionStrategy`)

```php
interface PromotionStrategy {
    public function eligible(Promotion, Cart, subtotalCents, PromotionEvaluation): bool;
    public function computeOutcome(Promotion, Cart, subtotalCents, PromotionEvaluation): PromotionOutcome;
}
```

### Strategies

| Strategy | Eligible Check | Outcome Type | Calculation |
|----------|---------------|--------------|-------------|
| `PercentagePromotionStrategy` | Abstract + percentage-specific | `DiscountOutcome` | `subtotal * (discount / 100)`, capped by `max_discount_amount` |
| `FixedPromotionStrategy` | Abstract + fixed-specific | `DiscountOutcome` | `min(price, discount_value)` |
| `GiftPromotionStrategy` | Abstract + gift products exist | `GiftOutcome` | Resolves gift products with stock check, variant payload |

### Abstract Base (`AbstractPromotionStrategy`)

Common eligibility checks:
- `$promotion->isValid()` — status, date range, usage limiter
- `$evaluation->matchedSubtotalCents >= minimum_order_amount` (in cents)
- `$promotion->isRequiredQuantityTrue($evaluation->matchedQuantity)`

### PromtionEligibilityResolver

**Single source of truth for eligibility.** Maps `type_amount` to strategy instance.

| Method | Description |
|--------|-------------|
| `eligible($cart, $promotions, $subtotalCents)` | Batch check: maps each promotion through `resolve()`, filters nulls |
| `resolve($cart, $promotion, $subtotalCents)` | Single promotion: checks eligibility, computes outcome, returns `PromotionResult` |
| `matchedEligibility($cart, $promotion, $subtotalCents)` | Scoping: filters cart items to matched products, computes `PromotionEvaluation` |

### PromotionApplicator

Applies the computed outcome to the cart in a **DB transaction**.

| Method | Description |
|--------|-------------|
| `applyOutcome($cart, $promotion, $outcome, $shippingMethod)` | Applies discount/gift outcome with proportional allocation |

#### Discount Allocation Algorithm
```
1. Lock promotion + cart rows (lockForUpdate)
2. Re-evaluate matched eligibility at apply-time
3. For DiscountOutcome:
   - Discount cap: min($subtotalCents, $outcome->amountCents)
   - Proportional distribution using largest remainder method
   - Each item: promotion_id, discount_amount, total_price persisted
   - Cart total_price recalculated from discounted line totals
4. For GiftOutcome:
   - Lock product row
   - Reserve gift item via CartInventoryService::reserveGiftItem()
   - Gift priced at 0
   - Cart total_price recalculated (excluding gifts)
```

### Outcomes

| Class | Properties | Description |
|-------|-----------|-------------|
| `PromotionOutcome` | (abstract) | Base class |
| `DiscountOutcome` | `amountCents: int`, `baseAmountCents: int` | Monetary discount in cents |
| `GiftOutcome` | `giftItems: GiftItem[]` | Gift product items |

### DTOs

| Class | Properties | Description |
|-------|-----------|-------------|
| `PromotionResult` | `promotion`, `discount: float`, `giftItems: GiftItem[]` | Result of eligibility resolution, `toArray()` for API response |
| `PromotionEvaluation` | `matchedItems: Collection`, `matchedSubtotalCents: int`, `matchedQuantity: int` | Which cart items matched the promotion scope |
| `GiftItem` | Immutable, ArrayAccess | `productId`, `productVariantId`, `productVariant`, `productName`, `productSku`, `productImage`, `quantity`, `priceCents`, `isGift` |

## PromotionService

**File:** `app/Services/General/PromotionService.php`
**The orchestrator** — coordinates eligibility, application, and lifecycle.

| Method | Description |
|--------|-------------|
| `eligiblePromotions($cart)` | Loads cart, computes subtotal, fetches valid promotions, returns eligible `Collection<PromotionResult>` |
| `eligiblePromotionsPayload($cart)` | Returns serializable array for API responses |
| `applySelectedPromotion($cart, $promotionId, $selectedGiftProductId, $shippingMethod)` | Applies promotion to cart, returns `CheckoutTotals` |
| `clearPromotionFromCart($cart)` | Removes all promotion data from cart, returns clean `CheckoutTotals` |
| `incrementUsage($promotionId)` | Increments usage count (with `lockForUpdate` + `where('usage', '<', 'limiter')` guard) |
| `decrementUsage($promotionId)` | Decrements usage count (with `where('usage', '>', 0)` guard) |
| `hasEligiblePromotion($cart)` | Boolean check if any eligible promotion exists |
| `removeGiftItems($cart)` | Removes all gift items from cart (releases inventory) |
| `resolveSelectedGiftItem($giftItems, $selectedGiftProductId)` | Selects a specific gift item or defaults to first available |

## PromotionDataService

**File:** `app/Services/General/PromotionDataService.php`

| Method | Description |
|--------|-------------|
| `paginatePromotion($request)` | Paginated list of valid promotions with date/ID filters |
| `getPromotionBySlug($slug)` | Single promotion by slug with enriched products |

## Resources

### Admin Resource (`Marvel\Http\Resources\PromotionResource`)
**File:** `packages/marvel/src/Http/Resources/PromotionResource.php`

```json
{
  "id": "integer",
  "name": "translated string (index) | raw JSON (non-index)",
  "slug": "string",
  "type": "localized type string",
  "discount_type": "string (fixed_rate|percentage|gift)",
  "value": "float",
  "discount": "float",
  "code": "string",
  "minimum_order_amount": "float",
  "required_quantity": "integer|null",
  "apply_to": "string (all_products|specific_products)",
  "products": "[...] // when loaded",
  "gift_products": "[...] // when loaded",
  "image": {
    "desktop": "media url | null",
    "mobile": "media url | null"
  },
  "start_at": "ISO 8601 | null",
  "end_at": "ISO 8601 | null",
  "status": "boolean",
  "is_valid": "boolean",
  "created_at": "ISO 8601"
}
```

### Public Resource (`App\Http\Resources\Promotion\PromotionResource`)
**File:** `app/Http/Resources/Promotion/PromotionResource.php`

```json
{
  "id": "integer",
  "name": "translated string",
  "slug": "string",
  "status": "boolean",
  "image": {
    "desktop": "media url | null",
    "mobile": "media url | null"
  },
  "products": "[...] // when loaded, uses ProductMiniResource"
}
```

**Key difference:** Admin resource includes `type`, `discount_type`, `value`, `discount`, `code`, `minimum_order_amount`, `required_quantity`, `apply_to`, `gift_products`, `start_at`, `end_at`, `is_valid`, `created_at`. Public resource only exposes `id`, `name`, `slug`, `status`, `image`, `products`.

## Request Validation

### PromotionRequest (`Marvel\Http\Requests\PromotionRequest`)
**File:** `packages/marvel/src/Http/Requests/PromotionRequest.php`

| Field | Rules |
|-------|-------|
| `name` | `required`, `array` |
| `name.*` | `required_with:name`, `UniqueTranslationRule::for('promotions', 'name')` |
| `image-desktop` | `required`, `image`, `mimes:jpeg,png,jpg,webp` |
| `image-mobile` | `required`, `image`, `mimes:jpeg,png,jpg,webp` |
| `type` | `required`, `in:price,quantity` |
| `type_amount` | `required`, `in:fixed_rate,percentage,gift` |
| `product_ids` | `required_if:apply_to,specific_products`, `prohibited_if:apply_to,all_products`, `array` |
| `product_ids.*` | `exists:products,id` |
| `gift_products` | `required_if:type_amount,gift`, `array`, `min:1` |
| `gift_products.*.product_id` | `required_with:gift_products`, `exists:products,id` |
| `gift_products.*.product_variant_id` | `nullable`, `exists:product_variants,id` |
| `gift_products.*.quantity` | `sometimes`, `integer`, `min:1` |
| `discount` | `numeric`, `min:0`, `required_if:type,price` (not required when type=quantity with gifts) |
| `max_discount_amount` | `required_if:type_amount,percentage`, `numeric`, `min:1` |
| `required_quantity_type` | `integer`, `min:1`, `required_if:type,quantity` |
| `minimum_order_amount` | `numeric`, `min:0`, `required_if:type,price` (not required when type=quantity) |
| `apply_to` | `required`, `in:all_products,specific_products` |
| `limiter` | `sometimes`, `integer`, `min:1` |
| `start_at` | `sometimes`, `date` |
| `end_at` | `sometimes`, `date`, `after_or_equal:start_at` |
| `status` | `sometimes`, `in:0,1` |

### UpdatePromotionRequest (`Marvel\Http\Requests\UpdatePromotionRequest`)
**File:** `packages/marvel/src/Http/Requests/UpdatePromotionRequest.php`

All fields are `sometimes` (optional on update). Same rules as create, except:
- `name.*` uses `UniqueTranslationRule::for('promotions', 'name')` (no ignore)
- Images are `sometimes` instead of `required`
- `apply_to` is `nullable` instead of `required`
- `limiter`, `start_at`, `end_at` are `nullable`

## Observer

**File:** `app/Observers/PromotionObserver.php`
**Registered in:** `AppServiceProvider` or `EventServiceProvider`

| Event | Behavior |
|-------|----------|
| `created` | Logs activity: `promotion_created` |
| `updated` | If status changed: logs `promotion_activated` / `promotion_deactivated`. If other tracked fields changed: logs `promotion_updated` with old/new values. Skips if only `updated_at` is dirty. |
| `deleted` | Logs activity: `promotion_deleted` |

Tracked fields: `name`, `slug`, `type`, `type_amount`, `value`, `discount`, `max_discount_amount`, `minimum_order_amount`, `apply_to`, `required_quantity_type`, `limiter`, `usage`, `start_at`, `end_at`, `status`.

All observer logging dispatches `LogActivityJob` (queued).

## Media Handling

**Trait:** `Marvel\Traits\MediaManager`

**Disk:** `promotions` (local, `storage/app/public/promotions`, URL: `/public/storage/promotions`)

**Collections:**

| Collection | Type | Upload Method |
|------------|------|---------------|
| `promotions-desktop` | Single image | `uploadSingleImage()` on create, `updateSingleImage()` on update |
| `promotions-mobile` | Single image | `uploadSingleImage()` on create, `updateSingleImage()` on update |

`updateSingleImage()` clears the entire collection before uploading the new file.

## Permission Enum

**File:** `packages/marvel/src/Enums/Permission.php`

| Constant | Value |
|----------|-------|
| `VIEW_PROMOTION` | `view-promotion` |
| `CREATE_PROMOTION` | `create-promotion` |
| `UPDATE_PROMOTION` | `update-promotion` |
| `DELETE_PROMOTION` | `delete-promotion` |

## Constants

**File:** `packages/marvel/config/constants.php`

```php
define('CREATED_PROMOTION_SUCCESSFULLY', APP_NOTICE_DOMAIN . 'MESSAGE.CREATED_PROMOTION_SUCCESSFULLY');
define('UPDATED_PROMOTION_SUCCESSFULLY', APP_NOTICE_DOMAIN . 'MESSAGE.UPDATED_PROMOTION_SUCCESSFULLY');
define('DELETED_PROMOTION_SUCCESSFULLY', APP_NOTICE_DOMAIN . 'MESSAGE.DELETED_PROMOTION_SUCCESSFULLY');
```

## Seeders

**File:** `database/seeders/PromotionSeeder.php`
- Seeds 20 promotions (7 percentage, 7 fixed, 6 gift) with bilingual names (en/ar)
- Each promotion gets random configuration: products, gift products, usage limits, date windows
- Images from `public/images/flash/` or fallback to `picsum.photos`
- Idempotent via random unique codes

## Dependencies

| File | Role |
|------|------|
| `packages/marvel/src/Rest/Routes.php` | Admin route definitions |
| `routes/api.php` | Public + checkout route definitions |
| `packages/marvel/src/Http/Controllers/PromotionController.php` | Admin controller |
| `app/Http/Controllers/Api/General/PromotionController.php` | Public controller |
| `app/Http/Controllers/Api/OrderController.php` | Checkout eligibility endpoint |
| `packages/marvel/src/Http/Requests/PromotionRequest.php` | Create validation |
| `packages/marvel/src/Http/Requests/UpdatePromotionRequest.php` | Update validation |
| `packages/marvel/src/Http/Resources/PromotionResource.php` | Admin API resource |
| `app/Http/Resources/Promotion/PromotionResource.php` | Public API resource |
| `packages/marvel/src/Database/Models/Promotion.php` | Model |
| `packages/marvel/src/Database/Models/promotionShop.php` | Shop pivot model |
| `packages/marvel/src/Database/Repositories/PromotionRepository.php` | Repository |
| `packages/marvel/src/Database/Repositories/BaseRepository.php` | Base repository (slug generation, caching) |
| `app/Services/General/PromotionService.php` | Promotion orchestrator |
| `app/Services/General/PromotionDataService.php` | Public data service |
| `app/Services/General/CartInventoryService.php` | Gift item inventory reservation |
| `app/Services/General/PromotionEngine/PromotionEligibilityResolver.php` | Eligibility resolver |
| `app/Services/General/PromotionEngine/PromotionApplicator.php` | Cart outcome applicator |
| `app/Services/General/PromotionEngine/PromotionResult.php` | Eligibility result DTO |
| `app/Services/General/PromotionEngine/PromotionEvaluation.php` | Matched items DTO |
| `app/Services/General/PromotionEngine/Contracts/PromotionStrategy.php` | Strategy interface |
| `app/Services/General/PromotionEngine/Strategies/AbstractPromotionStrategy.php` | Base strategy |
| `app/Services/General/PromotionEngine/Strategies/PercentagePromotionStrategy.php` | Percentage strategy |
| `app/Services/General/PromotionEngine/Strategies/FixedPromotionStrategy.php` | Fixed rate strategy |
| `app/Services/General/PromotionEngine/Strategies/GiftPromotionStrategy.php` | Gift strategy |
| `app/Services/General/PromotionEngine/Outcome/PromotionOutcome.php` | Outcome base |
| `app/Services/General/PromotionEngine/Outcome/DiscountOutcome.php` | Discount outcome |
| `app/Services/General/PromotionEngine/Outcome/GiftOutcome.php` | Gift outcome |
| `app/Services/General/PromotionEngine/DTOs/GiftItem.php` | Gift item DTO |
| `app/Observers/PromotionObserver.php` | Activity logging observer |
| `app/Jobs/LogActivityJob.php` | Queued activity logging |
| `packages/marvel/src/Enums/Permission.php` | Permissions enum |
| `packages/marvel/src/Enums/PromotionType.php` | Type enum (price, quantity) |
| `packages/marvel/src/Enums/PromotionMountType.php` | Mount type enum (fixed_rate, percentage, gift) |
| `packages/marvel/config/constants.php` | Response message constants |
| `packages/marvel/src/Traits/MediaManager.php` | Image upload trait |
| `app/Traits/HasChannelFilter.php` | Channel filtering trait |
| `packages/marvel/database/migrations/2020_04_29_000001_create_promotions_table.php` | Main table migration |
| `packages/marvel/database/migrations/2026_05_03_111116_create_promotion_product_table.php` | Product pivot migration |
| `packages/marvel/database/migrations/2026_05_17_000001_add_selected_promotion_checkout_fields.php` | Gift products table migration |
| `packages/marvel/database/migrations/2026_07_18_000001_make_promotion_gift_product_variant_nullable.php` | Empty migration stub |
| `database/seeders/PromotionSeeder.php` | Seeder (20 promotions) |
| `config/filesystems.php` | Disk configuration (`promotions` disk) |
| `tests/Feature/PromotionFlowTest.php` | Integration flow tests |
| `tests/Feature/PromotionProductionHardenTest.php` | Production harden tests |
| `tests/Unit/PromotionEligibilityResolverTest.php` | Unit tests for resolver |

## Translation Keys Used

| Key | Context |
|-----|---------|
| `MESSAGE.CREATED_PROMOTION_SUCCESSFULLY` | POST response message |
| `MESSAGE.UPDATED_PROMOTION_SUCCESSFULLY` | PUT response message |
| `MESSAGE.DELETED_PROMOTION_SUCCESSFULLY` | DELETE response message |
| `MESSAGE.FETCH_DATA_SUCCESSFULLY` | GET response message |
| `ERROR.NOT_FOUND` | 404 error response |
| `ERROR.COULD_NOT_CREATE_THE_RESOURCE` | 400 create failure |
| `ERROR.COULD_NOT_UPDATE_THE_RESOURCE` | 400 update failure |
| `activity.promotion_created` | Observer: create log |
| `activity.promotion_updated` | Observer: update log |
| `activity.promotion_deleted` | Observer: delete log |
| `activity.promotion_activated` | Observer: status change to active |
| `activity.promotion_deactivated` | Observer: status change to inactive |
