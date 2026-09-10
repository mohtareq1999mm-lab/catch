# Coupon Module — Backend Architecture (Public API)

## Endpoints

| Method | URL | Auth | Purpose |
|--------|-----|------|---------|
| GET | `/api/v1/general/coupons` | Public | List valid coupons |
| POST | `/api/v1/general/coupons/apply` | auth:sanctum | Apply coupon to cart |

## Route Definitions

**File:** `routes/api.php` (lines 61-62)

```php
Route::prefix('v1/general')->middleware('api')->group(function () {
    Route::get('coupons', [CouponController::class, 'index']);
    Route::post('coupons/apply', [CouponController::class, 'applyCoupon'])->middleware('auth:sanctum');
});
```

## Middleware

- `index`: `api` group (throttle, SubstituteBindings, ChannelMiddleware) — no auth
- `applyCoupon`: `api` group + `auth:sanctum` — requires authenticated user

## Request Flow

### Flow 1: List Coupons

```
Client → GET /api/v1/general/coupons?limit=5
         ↓
    CouponController@index(Request)
         ↓
    CouponService::getCoupons($request)
         ↓
    Coupon::valid()
        → when(search) → search name
        → when(start_date, end_date) → filter created_at
        → when(couponsId) → whereIn('id')
        → orderBy('id', $order)
        → limit($limit)
        → get()
         ↓
    CouponResource::collection($coupons)
        → id, name, slug, image, borderColor, borderless
         ↓
    Response: 200
```

### Flow 2: Apply Coupon

```
Client → POST /api/v1/general/coupons/apply { code: "SUMMER10" }
         ↓
    [auth:sanctum] → authenticate user
         ↓
    CouponController@applyCoupon(Request)
         ↓
    CouponService::addCouponToCart('SUMMER10')
         ↓
    DB::transaction
         ↓
    Get authenticated user's cart
         ↓
    Cart exists?
    ├─ NO: return null → 400
    └─ YES:
         ↓
        Cart already has this coupon?
        ├─ YES: return ['already_applied' => true] → 200
        └─ NO:
             ↓
            CouponOrchestrator::validateByCode($code, $user, $items)
                ├─ Coupon exists?
                │   └─ NO: return invalid('not_found')
                ├─ CouponAssignmentValidator::validate() — user-specific assignments
                ├─ CouponValidator::validate() — dates, usage limits, products, quantities
                └─ All pass? return valid($coupon)
             ↓
            Valid?
            ├─ NO: return null → 400
            └─ YES:
                 ↓
                CouponCalculator::calculate($coupon, $cart->total_price)
                 ↓
                Update cart: set coupon code
                 ↓
                Return: { total_price, coupon_discount, free_shipping }
                 ↓
                Response: 200
```

## Key Classes

| Class | Method | Responsibility |
|-------|--------|----------------|
| `CouponController` | `index()` | List valid coupons |
| `CouponController` | `applyCoupon()` | Apply coupon to cart |
| `CouponService` | `getCoupons()` | Query builder for valid coupons |
| `CouponService` | `addCouponToCart()` | Orchestrate cart coupon application |
| `CouponOrchestrator` | `validateByCode()` | Full validation pipeline |
| `CouponValidator` | `validate()` | Date, usage, product, quantity checks |
| `CouponAssignmentValidator` | `validate()` | User-specific assignment checks |
| `CouponCalculator` | `calculate()` | Price discount calculation |

## Model: Coupon

| Column | Type | Description |
|--------|------|-------------|
| id | bigint UNSIGNED | Primary key |
| code | varchar(255) | Unique coupon code |
| slug | varchar(255) | URL slug |
| name | json (translatable) | Coupon name |
| discount_type | varchar(255) | fixed_rate or percentage |
| discount | decimal(10,2) | Discount value |
| max_discount_amount | decimal(10,2), nullable | Max cap for percentage |
| start_date | date, nullable | Validity start |
| end_date | date, nullable | Validity end |
| limiter | int, nullable | Max uses |
| used | int, default 0 | Current use count |
| status | boolean | Active flag |
| border_color | varchar(255), nullable | UI accent color |
| borderless | boolean | UI borderless flag |

Relations: `products()`, `orders()`, `users()`, `couponUsages()`, `assignments()`
