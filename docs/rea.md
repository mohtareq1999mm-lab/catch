# Coupon System - Architecture Analysis

## Status
Not Started (Production Audit pending)

---

## Models

| Model | Table | Purpose |
|-------|-------|---------|
| `Marvel\Database\Models\Coupon` | `coupons` | Core coupon entity — code, discount, dates, limiter |
| `Marvel\Database\Models\CouponAssignment` | `coupon_assignments` | Per-user coupon grant — max_uses, used, expires_at |
| `Marvel\Database\Models\CouponUsage` | `coupon_usages` | Global usage log — tracks any user using any coupon once |
| `Marvel\Database\Models\CouponAssignmentUsage` | `coupon_assignment_usages` | Append-only audit log for assigned coupon consumption |
| `Marvel\Database\Models\CouponShop` | `coupon_shop` | Pivot — coupon belongs to shop |

**Key Relationships on Coupon:**
- `products()` — BelongsToMany via `coupon_product`
- `users()` — BelongsToMany via `coupon_usages` (with pivot: order_id, used_at)
- `assignments()` — HasMany CouponAssignment

---

## Coupon Assignment System

### Answer: YES, you CAN add coupons to users.

The `coupon_assignments` table links a coupon to a specific user with:
- `max_uses` — how many times the user may use this coupon
- `used` — how many times consumed so far
- `expires_at` — optional per-assignment expiry

### How it works:

**Public coupon** (zero assignments):
- Any authenticated user can use it once
- Validated via `CouponUsage` (prevents reuse by same user)

**Assigned/Restricted coupon** (one or more assignments):
- Only assigned users can use it
- Validated via `CouponAssignmentValidator`:
  - Check: user has an assignment row
  - Check: assignment not expired
  - Check: `used < max_uses`
- Consumption tracked in `CouponAssignmentUsage` (append-only)

### But: No REST API for managing assignments.

There are **zero routes** for creating, listing, updating, or deleting `CouponAssignment` records. Assignments can only be created:
- Directly via `CouponAssignment::create([...])`
- In seeders
- In tests

This means there is no way for an admin to assign a coupon to a user through the API currently.

---

## Routes

### Package routes (`packages/marvel/src/Rest/Routes.php`)

| Method | URI | Middleware | Action |
|--------|-----|-----------|--------|
| GET\|HEAD | `/coupons` | `permission:view_coupons` | `index` (paginated list) |
| POST | `/coupons` | `permission:create_coupon` | `store` |
| GET | `/coupons/{coupon}` | `permission:view_coupons` | `show` |
| PUT\|PATCH | `/coupons/{coupon}` | `permission:update_coupon` | `update` |
| DELETE | `/coupons/{coupon}` | `permission:delete_coupon` | `destroy` |
| POST | `/coupons/verify` | — | `verify` (commented out) |
| POST | `/coupons/add-to-cart` | `auth:sanctum` | `addCouponToCart` |
| POST | `/approve-coupon` | SUPER_ADMIN | `approveCoupon` |
| POST | `/disapprove-coupon` | SUPER_ADMIN | `disApproveCoupon` |

**Nested in vendor group** (`role:vendor`):
| PUT | `/coupons/{coupon}` | vendor | `update` |

**Nested in super_admin group:**
| POST | `/coupons` | super_admin | `store` |
| DELETE | `/coupons/{coupon}` | super_admin | `destroy` |

### General routes (`routes/api.php`)

| Method | URI | Middleware | Action |
|--------|-----|-----------|--------|
| GET | `/api/v1/general/coupons` | — | `index` (public list) |
| POST | `/api/v1/general/coupons/apply` | `auth:sanctum` | `applyCoupon` |

---

## Controllers

### 1. `Marvel\Http\Controllers\CouponController`
- Package controller (admin/vendor facing)
- Uses `CouponRepository` for CRUD
- Middleware: permission-based
- Note: `verify()` method is **commented out** — dead endpoint

### 2. `App\Http\Controllers\Api\General\CouponController`
- Public/general controller
- Uses `App\Services\General\CouponService`
- Two endpoints: `index` (list), `applyCoupon` (apply to cart)

---

## Services

### `App\Services\General\CouponService`
- `getCoupons()` — list valid coupons with filters (search, date range, IDs)
- `addCouponToCart($code)` — transaction wrapper: validate via `CouponOrchestrator`, update cart
- `calcPrice()` / `calcPriceByCode()` — price calculation delegates to `CouponCalculator`

### `App\Services\Coupon\CouponOrchestrator`
- Entry point for full coupon validation
- Calls `CouponAssignmentValidator` if user provided
- Then calls `CouponValidator`

### `App\Services\Coupon\CouponAssignmentValidator`
- Validates per-user assignment (only when coupon has assignments)
- Checks: exists, not expired, usage quota not exceeded

### `App\Services\Coupon\CouponValidator`
- Validates general coupon rules: status, dates, global limiter, per-user already-used, product eligibility

### `App\Services\Coupon\CouponCalculator`
- Pure calculation: percentage, fixed_rate, free_shipping
- Respects `max_discount_amount` for percentage discounts

---

## Resources

### `Marvel\Http\Resources\CouponResource` (package)
- Full resource with: id, code, name, image, borderColor, borderless, discount, discount_type (translated), max_discount_amount, dates, limiter, used, status, is_valid (validated), is_assigned, assignments, created_at

### `App\Http\Resources\Coupons\CouponResource` (app)
- Lightweight: id, name, slug, code, image, borderColor, borderless

### `App\Http\Resources\Coupons\CouponAssignmentResource`
- Assignment-specific: id, coupon_id, user_id, user (when loaded), max_uses, used, assigned_at, expires_at

---

## Coupon Consumption During Order Creation

**`OrderService.php:622-670`** handles coupon consumption:
1. Load coupon by code from the order
2. Check if coupon has assignments
3. If assigned: find assignment, check quota, create CouponAssignmentUsage
4. Increment `coupon.used` and `assignment.used`
5. Fire `AssignedCouponConsumed` event after commit

Uses `lockForUpdate()` to prevent race conditions on concurrent order creation.

---

## Observers

**`CouponObserver`** logs activity:
- `created` → `coupon_created`
- `updated` → `coupon_updated` / `coupon_enabled` / `coupon_disabled`
- `deleted` → `coupon_deleted`

---

## Migrations

| Migration | Table | Key Columns |
|-----------|-------|-------------|
| `2026_06_17_000001` | `coupon_product` | coupon_id, product_id |
| `2026_07_12_000002` | (adds free_shipping to discount_type enum) | — |
| `2026_07_15_000003` | `coupon_assignments` | coupon_id, user_id, max_uses, used, assigned_at, expires_at |
| `2026_07_15_000004` | `coupon_assignment_usages` | coupon_assignment_id, order_id, used_at |

---

## Test Coverage

| Test File | Tests | Coverage |
|-----------|-------|----------|
| `CouponSystemTest.php` | — | API endpoints |
| `CouponsProductionHardenTest.php` | — | Hardening: validation, auth, assignments |
| `AssignedCouponSystemTest.php` | — | Assignment validation + consumption |
| `CouponValidatorTest.php` | Unit | Validator |
| `CouponCalculatorTest.php` | Unit | Calculator |

---

## Architecture Flow

```
GET /api/v1/general/coupons
  → App\CouponController::index
  → CouponService::getCoupons()
  → Coupon::valid()->scope
  → App\CouponResource

POST /api/v1/general/coupons/apply
  → App\CouponController::applyCoupon
  → CouponService::addCouponToCart()
  → CouponOrchestrator::validateByCode()
     → CouponAssignmentValidator::validate()
     → CouponValidator::validate()
  → CouponCalculator::calculate()
  → Cart::update(['coupon' => $code])

POST /coupons/add-to-cart  (package route)
  → Marvel\CouponController::addCouponToCart
  → CouponRepository::addCouponToCart($code)
  → CouponValidator::validateByCode()
  → Cart::update(['coupon' => $code])

Admin CRUD:
  → Marvel\CouponController
  → CouponRepository (storeCoupon, updateCoupon)
  → Coupon model
  → Marvel\CouponResource

Order consumption:
  → OrderService::createOrder (internal)
  → CouponAssignment::lockForUpdate()
  → CouponAssignmentUsage::create()
  → AssignedCouponConsumed event
```
