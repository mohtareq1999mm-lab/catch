# Coupon Module

## Overview

The Coupon module manages discount coupons on the e-commerce platform. It provides two API surfaces:

- **Admin API** (`/api/v1/coupons`) — Full CRUD, protected by permissions (super admin + vendor + store owner scoped)
- **Public API** (`/api/v1/general/coupons`) — Read-only listing of valid coupons
- **Apply API** (`/api/v1/coupons/add-to-cart` and `/api/v1/general/coupons/apply`) — Apply coupon to cart (authenticated)

Coupons are cart-level discounts (separate from promotion discounts). Coupons can be: percentage-based (with optional max cap), fixed-rate, or free-shipping. They support translatable names, media uploads (desktop + mobile images), usage tracking, date validity windows, product restrictions, and user assignment (quota/expiry).

## Key Files

| Layer | File |
|-------|------|
| Admin Controller | `packages/marvel/src/Http/Controllers/CouponController.php` |
| Public Controller | `app/Http/Controllers/Api/General/CouponController.php` |
| Repository | `packages/marvel/src/Database/Repositories/CouponRepository.php` |
| Model | `packages/marvel/src/Database/Models/Coupon.php` |
| Admin Resource | `packages/marvel/src/Http/Resources/CouponResource.php` |
| Public Resource | `app/Http/Resources/Coupons/CouponResource.php` |
| Assignment Resource | `app/Http/Resources/Coupons/CouponAssignmentResource.php` |
| Create Request | `packages/marvel/src/Http/Requests/CouponRequest.php` |
| Update Request | `packages/marvel/src/Http/Requests/UpdateCouponRequest.php` |
| Coupon Service | `app/Services/General/CouponService.php` |
| Coupon Orchestrator | `app/Services/Coupon/CouponOrchestrator.php` |
| Coupon Validator | `app/Services/Coupon/CouponValidator.php` |
| Coupon Calculator | `app/Services/Coupon/CouponCalculator.php` |
| Assignment Validator | `app/Services/Coupon/CouponAssignmentValidator.php` |
| Observer | `app/Observers/CouponObserver.php` |
| Event | `app/Events/AssignedCouponConsumed.php` |
| Admin Routes | `packages/marvel/src/Rest/Routes.php` |
| Public Routes | `routes/api.php` |
| Permissions | `packages/marvel/src/Enums/Permission.php` |
| Coupon Migration | `packages/marvel/database/migrations/2024_12_27_000001_create_coupon_usages_table.php` |
| Product Pivot Migration | `database/migrations/2026_06_17_000001_create_coupon_product_table.php` |
| Free Shipping Migration | `database/migrations/2026_07_12_000002_add_free_shipping_to_coupons_discount_type.php` |
| Assignments Migration | `database/migrations/2026_07_15_000003_create_coupon_assignments_table.php` |
| Assignment Usage Migration | `database/migrations/2026_07_15_000004_create_coupon_assignment_usages_table.php` |
| Seeder | `database/seeders/CouponSeeder.php` |
| Seeder | `database/seeders/CouponProductSeeder.php` |
| Tests | `tests/Feature/CouponSystemTest.php` |
| Tests | `tests/Feature/CouponsProductionHardenTest.php` |
| Tests | `tests/Feature/AssignedCouponSystemTest.php` |
| Tests | `tests/Unit/CouponCalculatorTest.php` |
| Tests | `tests/Unit/CouponValidatorTest.php` |

## Dependencies

- **Spatie Translatable** (`HasTranslations`) — bilingual name (en/ar)
- **Spatie Media Library** (`InteractsWithMedia`) — coupon image management
- **Prettus Repository** — repository pattern with search/filter criteria
- **Orchestrator Pattern** — CouponOrchestrator routes validation through Assignment validator + CouponValidator
- **Validator + Calculator Separation** — validation is stateless, calculator is pure math

## Permissions

| Permission | Required For |
|------------|-------------|
| `view-coupons` | GET /coupons, GET /coupons/{id} |
| `create-coupon` | POST /coupons |
| `update-coupon` | PUT /coupons/{id} |
| `delete-coupon` | DELETE /coupons/{id} |

## Routes

### Admin (Full CRUD — super admin)

| Method | Endpoint | Purpose |
|--------|----------|---------|
| GET | `/api/v1/coupons` | List coupons (paginated, filterable, sortable) |
| POST | `/api/v1/coupons` | Create coupon (with images + product associations) |
| GET | `/api/v1/coupons/{id}` | Show coupon by ID |
| PUT | `/api/v1/coupons/{id}` | Update coupon |
| DELETE | `/api/v1/coupons/{id}` | Delete coupon |
| POST | `/api/v1/coupons/verify` | Verify coupon validity |
| POST | `/api/v1/coupons/add-to-cart` | Add coupon to cart (authenticated) |

### Vendor (scoped update)

| Method | Endpoint | Purpose |
|--------|----------|---------|
| PUT | `/api/v1/coupons/{id}` | Update coupon (vendor scope) |

### Store Owner (scoped create + delete)

| Method | Endpoint | Purpose |
|--------|----------|---------|
| POST | `/api/v1/coupons` | Create coupon (store owner scope) |
| DELETE | `/api/v1/coupons/{id}` | Delete coupon (store owner scope) |

### Super Admin (approve/disapprove)

| Method | Endpoint | Purpose |
|--------|----------|---------|
| POST | `/api/v1/approve-coupon` | Approve coupon |
| POST | `/api/v1/disapprove-coupon` | Disapprove coupon |

### Public

| Method | Endpoint | Purpose |
|--------|----------|---------|
| GET | `/api/v1/general/coupons` | List valid coupons |
| POST | `/api/v1/general/coupons/apply` | Apply coupon to cart (authenticated) |

### Dashboard

| Method | Endpoint | Purpose |
|--------|----------|---------|
| GET | `/api/v1/dashboard/coupons` | Coupon analytics |
