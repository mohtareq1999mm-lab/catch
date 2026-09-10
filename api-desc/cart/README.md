# Cart Module

## Overview

The Cart module manages user shopping carts with inventory reservation. Each user has **exactly one cart** (enforced by a UNIQUE index on `carts.user_id`). Items support two shipping methods (SCHEDULED / FAST), variant products, price snapshotting at reservation time, coupon application, and promotion-based gift items. Inventory is reserved on add and released on delete/expiry. Bulk add (POST /cart/bulk-items) processes items independently with per-item error reporting (`failed_items`).

## Key Files

| Layer | File |
|-------|------|
| Controller | `packages/marvel/src/Http/Controllers/CartController.php` |
| Repository | `packages/marvel/src/Database/Repositories/CartRepository.php` |
| Inventory Service | `app/Services/General/CartInventoryService.php` |
| Cart Model | `packages/marvel/src/Database/Models/Cart.php` |
| CartItem Model | `packages/marvel/src/Database/Models/CartItem.php` |
| Cart Resource | `packages/marvel/src/Http/Resources/CartResource.php` |
| CartItem Resource | `packages/marvel/src/Http/Resources/CartItemResource.php` |
| Create Request | `packages/marvel/src/Http/Requests/CartCreateRequest.php` |
| Update Request | `packages/marvel/src/Http/Requests/CartUpdateRequest.php` |
| ShippingMethod Enum | `packages/marvel/src/Enums/ShippingMethod.php` |
| CartOperation Enum | `packages/marvel/src/Enums/CartOperation.php` |
| ProductPricing Service | `packages/marvel/src/Services/Pricing/ProductPricingService.php` |
| Promotion Service | `app/Services/General/PromotionService.php` |
| Coupon Calculator | `app/Services/Coupon/CouponCalculator.php` |
| Coupon Resource | `app/Http/Resources/Coupons/CouponResource.php` |
| Routes | `packages/marvel/src/Rest/Routes.php` (lines 149-157) |
| Cart Migration | `packages/marvel/database/migrations/2020_06_02_051901_create_marvel_tables.php` |
| FK Fix Migration | `packages/marvel/database/migrations/2026_07_17_000001_fix_cart_foreign_key_cascades.php` |
| Tests | `tests/Feature/CartApiTest.php` |
| Tests | `tests/Feature/CartExpirationTest.php` |

## Dependencies

- **Laravel Sanctum** — authentication (`auth:sanctum`)
- **Prettus Repository** — repository pattern with caching
- **LockForUpdate (row-level locks)** — inventory concurrency
- **Rate Limiting** — `throttle:cart` (20 req/min per user, `RouteServiceProvider.php:111`)
- **Unique cart per user** — `carts.user_id` UNIQUE index

## Routes

All cart routes live in one authenticated group (`auth:sanctum` + `throttle:cart`):

| Method | Endpoint | Auth | Purpose |
|--------|----------|------|---------|
| GET | `/api/v1/cart` | `auth:sanctum` | List user's cart (paginated, max 1 due to unique user_id) |
| POST | `/api/v1/cart` | `auth:sanctum` | Add item to cart |
| GET | `/api/v1/cart/{id}` | `auth:sanctum` | Show specific cart |
| POST | `/api/v1/cart/bulk-items` | `auth:sanctum` | Bulk add items (per-item error reporting) |
| PUT | `/api/v1/cart/update-item` | `auth:sanctum` | Update item quantity (set mode + operation) |
| DELETE | `/api/v1/cart/delete-item/{itemId}` | `auth:sanctum` | Remove single item |
| DELETE | `/api/v1/cart/delete-items` | `auth:sanctum` | Clear entire cart |

**Rate Limit:** 20 requests per minute per user (configured in `RouteServiceProvider`).

## Key Business Rules

- **One cart per user** — `carts.user_id` is UNIQUE; a new `persistCart()` reuses the existing cart.
- **Price snapshot** — unit price is captured at reservation time via `ProductPricingService`; not re-validated at checkout.
- **3-day TTL** — `CART_TTL_DAYS = 3` in `CartInventoryService`; `expires_at = now() + 3 days` on every touch.
- **FAST eligibility** — FAST requires `product.is_fast_shipping_available === true`.
- **Coupon clear** — coupon is cleared automatically when the last item is removed.
- **Promotion clear** — every cart mutation calls `revalidatePromotion()` (clears promotion_id/discount_amount).
- **Bulk add is non-atomic** — non-existent products are skipped (`skipped_product_ids`); per-item stock failures are reported in `failed_items` (no full rollback).
