# Request Flows — Coupon Module

## Flow 1: List Coupons (Admin)

```
Client → GET /api/v1/coupons?search=SUMMER&status=1&page=1&limit=15
         ↓
    [auth:sanctum] middleware → authenticate token
         ↓
    [permission:view-coupons] middleware → check Spatie permission
         ↓
    CouponController@index(Request)
         ↓
    CouponController@fetchCoupons(Request)
         ↓
    Apply filters:
      - search → where code/name LIKE '%SUMMER%'
      - status → where('status', $bool)
      - valid/invalid → scope filter
      - order_by / sort → orderBy($orderBy, $sort)
         ↓
    Coupon::paginate($limit)
         ↓
    CouponResource::collection($coupons) → transform each coupon
         ↓
    Return: { status:200, message, success:true, data: { data[], pagination_meta } }
```

## Flow 2: Create Coupon (Admin)

```
Client → POST /api/v1/coupons (multipart/form-data)
         ↓
    [auth:sanctum] → [permission:create-coupon]
         ↓
    CouponRequest → validation rules (name, images, discount, discount_type, dates, etc.)
         ↓
    Fail? → 422 with field errors
         ↓
    CouponController@store($request)
         ↓
    CouponRepository::storeCoupon($request)
         ↓
    1. Extract data: $request->only($this->dataArray)
    2. Coupon::create($data)
    3. Sync products if provided ($coupon->products()->sync($product_ids))
    4. Upload image-desktop → 'coupons-desktop' collection
    5. Upload image-mobile → 'coupons-mobile' collection
         ↓
    CouponResource::make($coupon)
         ↓
    CouponObserver@created() → dispatches LogActivityJob
         ↓
    Return: { status:201, message, success:true, data }
```

## Flow 3: Show Coupon (Admin)

```
Client → GET /api/v1/coupons/1
         ↓
    [auth:sanctum] → [permission:view-coupons]
         ↓
    CouponController@show($id)
         ↓
    Coupon::findOrFailByIDOrCode($id)  [auto-detects numeric vs string]
         ↓
    Found? → CouponResource::make($coupon) → 200
    Not found? → Throwable(NOT_FOUND) → 404
```

## Flow 4: Update Coupon (Admin)

```
Client → PUT /api/v1/coupons/1 (multipart/form-data)
         ↓
    [auth:sanctum] → [permission:update-coupon]
         ↓
    UpdateCouponRequest → validation (all fields sometimes)
         ↓
    CouponController@update($request, $id)
         ↓
    CouponRepository::updateCoupon($id, $request)
         ↓
    1. Find coupon (findOrFail by ID)
    2. Extract data from request
    3. $coupon->update($data)
    4. Sync products if provided (replaces all)
    5. If image-desktop → updateSingleImage() [clears + uploads]
    6. If image-mobile → updateSingleImage() [clears + uploads]
         ↓
    CouponResource::make($coupon)
         ↓
    CouponObserver@updated() → dispatches LogActivityJob
         ↓
    Return: { status:200, message, success:true, data }
```

## Flow 5: Delete Coupon (Admin)

```
Client → DELETE /api/v1/coupons/1
         ↓
    [auth:sanctum] → [permission:delete-coupon]
         ↓
    CouponController@destroy($id)
         ↓
    Coupon::findOrFail($id)
         ↓
    $coupon->delete()  → hard deletes (no soft delete trait)
         ↓
    Pivot records cascade-deleted (FK ON DELETE CASCADE)
         ↓
    CouponObserver@deleted() → dispatches LogActivityJob
         ↓
    Return: { status:200, message, success:true }
```

## Flow 6: Apply Coupon to Cart (Admin)

```
Client → POST /api/v1/coupons/add-to-cart (JSON: { code: "SUMMER20" })
         ↓
    [auth:sanctum] middleware
         ↓
    CouponController@addCouponToCart(Request)
         ↓
    CouponOrchestrator::validateByCode(code, user, items)
         ↓
    1. Find coupon by code
    2. Run CouponAssignmentValidator:
       - No assignments → public coupon
       - Has assignments, user not assigned → rejected (not_assigned)
       - Assignment expired → rejected (assignment_expired)
       - Quota exceeded → rejected (usage_quota_exceeded)
       - Valid assignment → skips already_used check
    3. Run CouponValidator:
       - Check status enabled
       - Check start_date ≤ today
       - Check end_date ≥ today
       - Check limiter not reached
       - Check user not already_used (if no assignments)
       - Check product restrictions (if items provided)
         ↓
    If invalid → throw exception → 400
         ↓
    CouponCalculator::calculate(coupon, price)
      → returns { discountAmount, finalPrice, discountType, freeShipping }
         ↓
    Update cart: set coupon code on user's active cart
         ↓
    Return: { status:200, message, success:true, data: { coupon, discount_amount } }
```

## Flow 7: List Valid Coupons (Public)

```
Client → GET /api/v1/general/coupons?limit=10&id=1,2,3
         ↓
    CouponController@index(Request)  [no auth]
         ↓
    CouponService::getCoupons($request)
         ↓
    Coupon::valid()
      ├─ where('status', true)
      ├─ where (used < limiter OR limiter IS NULL)
      ├─ where (start_date IS NULL OR start_date <= today)
      └─ where (end_date IS NULL OR end_date >= today)
      ├─ Filter by start_date / end_date
      └─ Filter by id (comma-separated)
         ↓
    orderBy('id', $order) → limit($limit) → paginate()
         ↓
    CouponResource::collection($coupons)
         ↓
    Return: { status:200, message, success:true, data[] }
```

## Flow 8: Apply Coupon (Public API)

```
Client → POST /api/v1/general/coupons/apply (JSON: { coupon_code: "SUMMER20" })
         ↓
    [auth:sanctum] middleware
         ↓
    CouponController@applyCoupon(Request)
         ↓
    CouponService::addCouponToCart(code)
         ↓
    DB::transaction
      ├─ Find coupon by code
      ├─ CouponOrchestrator::validateByCode(code, user, items)
      │   ├─ CouponAssignmentValidator
      │   └─ CouponValidator
      ├─ Already applied? → return already_applied state
      ├─ CouponCalculator::calculate(coupon, price)
      └─ Update cart.coupon
         ↓
    Return: { status:200, message, success:true, data: { coupon, discount_amount } }
```

## Flow 9: Coupon Usage Recording (On Checkout/Order Placement)

```
Order placed with coupon code
    ↓
OrderService or CheckoutService
    ↓
CouponUsage::firstOrCreate([
    'coupon_id' => $coupon->id,
    'user_id'   => $user->id,
    'order_id'  => $order->id,
    'used_at'   => now()
])
    ↓
$coupon->increment('used')
    ↓
If coupon has assignments:
    ├─ $assignment->increment('used')
    ├─ CouponAssignmentUsage::create([...])
    └─ dispatch(new AssignedCouponConsumed(...))
```

## Flow 10: Coupon Approval (Super Admin)

```
Client → POST /api/v1/approve-coupon (JSON: { id: 1 })
         ↓
    [auth:sanctum] → [permission:super_admin]
         ↓
    CouponController@approveCoupon(Request)
         ↓
    Coupon::findOrFail($request->id)
         ↓
    $coupon->update(['is_approve' => true])
         ↓
    Return: { status:200, message, success:true, data: coupon }

Client → POST /api/v1/disapprove-coupon (JSON: { id: 1 })
         ↓
    Same flow → sets is_approve = false
```
