# Coupon Module — Backend Architecture

## Overview

The Coupon module manages discount coupons on the platform. Coupons are cart-level discounts applied after promotions (promotions → coupons → subtotal). The system uses an Orchestrator Pattern with three core services: CouponValidator (stateless validation), CouponCalculator (pure math), and CouponAssignmentValidator (user-scoped checks).

All monetary calculations use decimal floats (PHP native), capped at the cart subtotal for fixed-rate discounts. Percentage discounts support an optional `max_discount_amount` cap.

## Endpoints

### Admin API (`/api/v1/coupons`)

| Method | URL | Auth | Permission | Purpose |
|--------|-----|------|------------|---------|
| GET | `/api/v1/coupons` | `auth:sanctum` | `view-coupons` | List coupons (paginated, filterable, sortable) |
| POST | `/api/v1/coupons` | `auth:sanctum` | `create-coupon` | Create a new coupon |
| GET | `/api/v1/coupons/{id}` | `auth:sanctum` | `view-coupons` | Show coupon by ID or code |
| PUT | `/api/v1/coupons/{id}` | `auth:sanctum` | `update-coupon` | Update coupon |
| DELETE | `/api/v1/coupons/{id}` | `auth:sanctum` | `delete-coupon` | Delete coupon |
| POST | `/api/v1/coupons/add-to-cart` | `auth:sanctum` | - | Apply coupon to cart |
| POST | `/api/v1/coupons/verify` | `auth:sanctum` | - | Verify coupon validity |

### Vendor API (scoped)

| Method | URL | Auth | Purpose |
|--------|-----|------|---------|
| PUT | `/api/v1/coupons/{id}` | `auth:sanctum` | Update coupon (vendor scope) |

### Store Owner API (scoped)

| Method | URL | Auth | Purpose |
|--------|-----|------|---------|
| POST | `/api/v1/coupons` | `auth:sanctum` | Create coupon |
| DELETE | `/api/v1/coupons/{id}` | `auth:sanctum` | Delete coupon |

### Super Admin

| Method | URL | Auth | Purpose |
|--------|-----|------|---------|
| POST | `/api/v1/approve-coupon` | `auth:sanctum` | Approve coupon |
| POST | `/api/v1/disapprove-coupon` | `auth:sanctum` | Disapprove coupon |

### Public API (`/api/v1/general/coupons`)

| Method | URL | Auth | Purpose |
|--------|-----|------|---------|
| GET | `/api/v1/general/coupons` | Public | List valid coupons |
| POST | `/api/v1/general/coupons/apply` | `auth:sanctum` | Apply coupon to cart |

## Route Definitions

### Admin Routes
**File:** `packages/marvel/src/Rest/Routes.php`

```
Line ~xx: Route::apiResource('coupons', CouponController::class);                                      // Full CRUD (super admin)
Line ~xx: Route::apiResource('coupons', CouponController::class, ['only' => ['update']]);               // Vendor scope
Line ~xx: Route::apiResource('coupons', CouponController::class, ['only' => ['store', 'destroy']]);     // Store owner scope
Line ~xx: Route::post('coupons/verify', [CouponController::class, 'verify']);
Line ~xx: Route::post('coupons/add-to-cart', [CouponController::class, 'addCouponToCart']);             // auth:sanctum
Line ~xx: Route::post('approve-coupon', [CouponController::class, 'approveCoupon']);                    // super_admin
Line ~xx: Route::post('disapprove-coupon', [CouponController::class, 'disApproveCoupon']);              // super_admin
```

### Public Routes
**File:** `routes/api.php`

```
Line ~xx: Route::get('coupons', [CouponController::class, 'index']);                                   // Prefix: /api/v1/general
Line ~xx: Route::post('coupons/apply', [CouponController::class, 'applyCoupon']);                      // Prefix: /api/v1/general, auth:sanctum
```

### Dashboard Routes
```
Line ~xx: Route::get('dashboard/coupons', [DashboardController::class, 'couponAnalytics']);            // Coupon analytics
```

## Middleware

### Admin Controller (`Marvel\Http\Controllers\CouponController`)

| Method | Middleware |
|--------|-----------|
| `index` | `permission:view-coupons` (via constructor) |
| `show` | `permission:view-coupons` (via constructor) |
| `store` | `permission:create-coupon` (via constructor) |
| `update` | `permission:update-coupon` (via constructor) |
| `destroy` | `permission:delete-coupon` (via constructor) |
| `approveCoupon` | `permission:super_admin` (via constructor) |
| `disApproveCoupon` | `permission:super_admin` (via constructor) |

### Public Controller (`App\Http\Controllers\Api\General\CouponController`)

| Method | Middleware |
|--------|-----------|
| `index` | Public (no auth) |
| `applyCoupon` | `auth:sanctum` |

## Controller Flow

### Admin Controller (`Marvel\Http\Controllers\CouponController`)
**File:** `packages/marvel/src/Http/Controllers/CouponController.php`

```
CouponController
│
├── index(Request)
│   └── fetchCoupons(Request)
│       ├── apply search (LIKE on code, name)
│       ├── apply status filter
│       ├── apply valid/invalid scope
│       ├── apply ordering
│       └── paginate(limit) → CouponResource::collection()
│
├── store(CouponRequest)
│   └── CouponRepository::storeCoupon($request)
│       ├── create Coupon from $data
│       ├── upload image-desktop → 'coupons-desktop' collection
│       ├── upload image-mobile → 'coupons-mobile' collection
│       ├── sync products if provided
│       └── CouponResource::make()
│
├── show($id)
│   ├── Coupon::findOrFailByIDOrCode($id)
│   └── CouponResource::make()
│
├── update(UpdateCouponRequest, $id)
│   └── CouponRepository::updateCoupon($id, $request)
│       ├── find OrFail
│       ├── update Coupon
│       ├── upload new images if provided (replaces old)
│       ├── sync products if provided
│       └── CouponResource::make()
│
├── destroy($id)
│   ├── Coupon::findOrFail($id)
│   ├── delete (hard)
│   └── Return success
│
├── addCouponToCart(Request)
│   ├── CouponOrchestrator::validateByCode(code, user, items)
│   ├── CouponCalculator::calculate()
│   ├── Update cart coupon code
│   └── Return coupon + discount_amount
│
├── approveCoupon(Request)     // super_admin only
├── disApproveCoupon(Request)  // super_admin only
└── verify()                   // commented out (use GraphQL)
```

### Public Controller (`App\Http\Controllers\Api\General\CouponController`)
**File:** `app/Http/Controllers/Api/General/CouponController.php`

```
CouponController
│
├── index(Request)
│   └── CouponService::getCoupons($request)
│       ├── valid() scope
│       ├── filter by start_date / end_date / ID
│       ├── orderBy id
│       └── paginate(limit) → CouponResource::collection()
│
└── applyCoupon(Request)
    └── CouponService::addCouponToCart($code)
        ├── DB::transaction
        ├── CouponOrchestrator::validateByCode(code, user, items)
        ├── CouponCalculator::calculate()
        ├── Update cart coupon code
        ├── Handle already_applied state
        └── Return coupon data
```

## Repository Methods

**File:** `packages/marvel/src/Database/Repositories/CouponRepository.php`

| Method | Description |
|--------|-------------|
| `storeCoupon(Request)` | Creates coupon in transaction with image uploads |
| `updateCoupon($id, Request)` | Updates coupon in transaction, replaces images |
| `addCouponToCart($code)` | Validates via CouponOrchestrator, updates cart coupon |

## Model Properties

**File:** `packages/marvel/src/Database/Models/Coupon.php`

### Fillable
```php
protected $fillable = [
    'code', 'slug', 'name', 'discount_type', 'discount',
    'max_discount_amount', 'start_date', 'end_date', 'limiter',
    'used', 'status', 'border_color', 'borderless'
];
```

### Casts
| Column | Cast |
|--------|------|
| status | boolean |
| start_date | date |
| end_date | date |
| borderless | boolean |

### Scopes

| Scope | Description |
|-------|-------------|
| `valid()` | status=true, (limiter IS NULL OR used < limiter), (start_date IS NULL OR start_date <= today), (end_date IS NULL OR end_date >= today) |
| `invalid()` | Negation of valid() |
| `search($term)` | LIKE search on code and name |

### Relations

| Relation | Type | Pivot/FK |
|----------|------|----------|
| `products()` | BelongsToMany | `coupon_product` (coupon_id, product_id) |
| `orders()` | HasMany | `orders.coupon` (coupon code) |
| `users()` | BelongsToMany | `coupon_usages` (coupon_id, user_id, order_id, used_at) |
| `couponUsages()` | HasMany | `coupon_usages.coupon_id` |
| `assignments()` | HasMany | `coupon_assignments.coupon_id` |

### Model Events

| Event | Behavior |
|-------|----------|
| `creating` | Auto-generates `code` if empty via `generateUniqueCode()` (prefix: `coupon_` + 7 random uppercase chars) |

## Service Layer

### CouponService (`app/Services/General/CouponService.php`)

| Method | Description |
|--------|-------------|
| `getCoupons(Request)` | Query valid coupons with search, date filters, ID filter, ordering |
| `calcPrice(Coupon, $price)` | Delegates to CouponCalculator |
| `calcPriceByCode(string $code, $price)` | Finds coupon by code then delegates to CouponCalculator |
| `findByCode(string $code)` | Finds coupon by code |
| `addCouponToCart($code)` | Orchestrates validation → calculation → cart update |

### CouponOrchestrator (`app/Services/Coupon/CouponOrchestrator.php`)

| Method | Description |
|--------|-------------|
| `validateByCode(code, user, items)` | Entry point — finds coupon, delegates to validate() |
| `validate(coupon, user, items)` | Runs CouponAssignmentValidator first. If user has assignments → skips already_used check, runs CouponValidator without user. If user has no assignments → runs CouponValidator normally. |

### CouponValidator (`app/Services/Coupon/CouponValidator.php`)

| Method | Checks |
|--------|--------|
| `validate(coupon, user, items)` | status enabled, start_date ≤ today, end_date ≥ today, limiter not reached, user not already used (if user provided), product restrictions (if items provided) |
| `validateByCode(code, user, items)` | Finds coupon by code then validates |

### CouponCalculator (`app/Services/Coupon/CouponCalculator.php`)

| Method | Description |
|--------|-------------|
| `calculate(coupon, price)` | Returns `['discountAmount', 'finalPrice', 'discountType', 'freeShipping']`. Handles: percentage (with max cap), fixed_rate (capped at price), free_shipping |

### CouponAssignmentValidator (`app/Services/Coupon/CouponAssignmentValidator.php`)

| Method | Checks |
|--------|--------|
| `validate(coupon, user)` | No assignments → public (valid). Not assigned → `not_assigned`. Expired → `assignment_expired`. Quota exhausted → `usage_quota_exceeded`. Valid → returns assignment data. |

## Resources

### Admin CouponResource (`packages/marvel/src/Http/Resources/CouponResource.php`)

| Field | Type | Source |
|-------|------|--------|
| id | int | Coupon |
| code | string | Coupon |
| name | object | Translated |
| image | object | Desktop + mobile URLs from media |
| borderColor | string | Coupon.border_color |
| borderless | bool | Coupon.borderless |
| discount | float | Coupon.discount |
| discount_type | string | Coupon → translated label via typeByLang() |
| max_discount_amount | float | Coupon |
| start_date | date | Coupon |
| end_date | date | Coupon |
| limiter | int | Coupon |
| used | int | Coupon |
| status | bool | Coupon |
| is_valid | bool | CouponValidator::validate() |
| is_assigned | bool | assignments relation non-empty |
| assignments | array | CouponAssignment full array |
| created_at | timestamp | Coupon |

### Public CouponResource (`app/Http/Resources/Coupons/CouponResource.php`)

| Field | Type | Source |
|-------|------|--------|
| id | int | Coupon |
| name | object | Translated |
| slug | string | Coupon |
| image | object | Desktop + mobile URLs |
| borderColor | string | Coupon.border_color |
| borderless | bool | Coupon.borderless |

### CouponAssignmentResource (`app/Http/Resources/Coupons/CouponAssignmentResource.php`)

| Field | Type | Source |
|-------|------|--------|
| id | int | Assignment |
| coupon_id | int | Assignment |
| user_id | int | Assignment |
| user | object | Loaded user (id, name, email) |
| max_uses | int | Assignment |
| used | int | Assignment |
| assigned_at | datetime | Assignment |
| expires_at | datetime | Assignment |

## Observer

**File:** `app/Observers/CouponObserver.php`

| Event | Action |
|-------|--------|
| `created` | Dispatches `LogActivityJob` with created diff |
| `updated` | Dispatches `LogActivityJob` with updated diff (tracks status changes separately) |
| `deleted` | Dispatches `LogActivityJob` with deleted diff |

## Event

**File:** `app/Events/AssignedCouponConsumed.php`

Dispatched after an assigned coupon usage is recorded (inside `DB::afterCommit`). Payload includes `coupon`, `couponAssignment`, `user`, `order`, `remainingUses`, `consumedAt`.

## Enums

| Enum | Values | File |
|------|--------|------|
| CouponType | `FIXED_COUPON = fixed_rate`, `PERCENTAGE_COUPON = percentage`, `FREE_SHIPPING_COUPON = free_shipping` | `packages/marvel/src/Enums/CouponType.php` |
| CouponTargetType | `GLOBAL_CUSTOMER`, `VERIFIED_CUSTOMER` | `packages/marvel/src/Enums/CouponTargetType.php` |
| DiscountType | `PERCENTAGE = percentage`, `FIXED_RATE = fixed_rate`, `FREE_SHIPPING = free_shipping` | `packages/marvel/src/Enums/DiscountType.php` |

## Permissions

| Permission | Description |
|------------|-------------|
| `view-coupons` | View coupon list and details |
| `create-coupon` | Create new coupons |
| `update-coupon` | Update existing coupons |
| `delete-coupon` | Delete coupons |
| `super_admin` | Approve/disapprove coupons |

## Seeders

| File | Description |
|------|-------------|
| `database/seeders/CouponSeeder.php` | Seeds 20 coupons (SUMMER20, WELCOME10, FREESHIP, FLASH25, etc.) with random images |
| `database/seeders/CouponProductSeeder.php` | Seeds random coupon-product pivot associations |

## Complete Dependency Graph

```
CouponController (Admin)
├── CouponRequest / UpdateCouponRequest (validation)
├── CouponRepository
│   ├── Coupon (Model)
│   │   ├── HasTranslations (name)
│   │   ├── InteractsWithMedia (images)
│   │   └── CouponUsage / CouponAssignment (relations)
│   └── MediaManager (image upload)
└── CouponResource (response)

CouponController (Public)
├── CouponService
│   ├── CouponOrchestrator
│   │   ├── CouponAssignmentValidator (user assignment checks)
│   │   └── CouponValidator (status, dates, limits, products)
│   └── CouponCalculator (discount math)
└── CouponResource (public response)

CouponObserver → LogActivityJob (audit trail)
AssignedCouponConsumed Event (post-checkout)
```
