# Request Flows — Coupon Module (Public API)

## Flow 1: List Coupons

```
Client → GET /api/v1/general/coupons?limit=5
         ↓
    [api] middleware group
         ↓
    CouponController@index(Request)
         ↓
    CouponService::getCoupons($request)
         ↓
    Coupon::valid()
        ->when(search) → search('name', $term, $locale)
        ->when(start_date, end_date) → where between created_at
        ->when(couponsId) → whereIn('id', $ids)
        ->orderBy('id', 'desc')
        ->limit(5)
        ->get()
         ↓
    Collection of valid Coupon models
         ↓
    CouponResource::collection
        → id, name (translated), slug, image {desktop, mobile},
          borderColor, borderless
         ↓
    Response: 200
    { "status": 200, "message": "...", "success": true, "data": [...] }
```

## Flow 2: Apply Coupon — Success

```
Client → POST /api/v1/general/coupons/apply { "code": "SUMMER10" }
         ↓
    [auth:sanctum] → authenticate user
         ↓
    CouponController@applyCoupon(Request)
         ↓
    CouponService::addCouponToCart('SUMMER10')
         ↓
    DB::transaction
         ↓
    Get user's cart → exists? YES
         ↓
    Cart has coupon 'SUMMER10' already? NO
         ↓
    CouponOrchestrator::validateByCode('SUMMER10', $user, $items)
        ├─ Coupon exists? YES (where code = 'SUMMER10')
        ├─ CouponAssignmentValidator: assigned to this user? YES
        ├─ CouponValidator: active, within dates, limiter OK? YES
        └─ Return: valid
         ↓
    CouponCalculator::calculate($coupon, $cart->total_price)
        ├─ Percentage 10% → discount = $10.00
        └─ finalPrice = $90.00
         ↓
    Update cart: set coupon = 'SUMMER10', save
         ↓
    Return: { total_price: 90.00, coupon_discount: 10.00, free_shipping: false }
         ↓
    Response: 200
```

## Flow 3: Apply Coupon — Already Applied

```
Client → POST /api/v1/general/coupons/apply { "code": "SUMMER10" }
         ↓
    User's cart already has coupon = 'SUMMER10'
         ↓
    Return: ['already_applied' => true]
         ↓
    Response: 200 { "message": "Coupon Already Applied", "data": { "already_applied": true } }
```

## Flow 4: Apply Coupon — Invalid Code

```
Client → POST /api/v1/general/coupons/apply { "code": "INVALID" }
         ↓
    CouponOrchestrator::validateByCode('INVALID', ...)
        ├─ Coupon exists? NO (where code = 'INVALID')
        └─ Return: invalid('not_found')
         ↓
    Return: null
         ↓
    Response: 400 { "message": "Invalid coupon code...", "success": false }
```
