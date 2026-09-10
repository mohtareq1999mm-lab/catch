# Cart Module — Frontend (Authenticated API)

## Overview

The Cart module manages the authenticated user's shopping cart. It handles item addition/updating/deletion, inventory reservation with 3-day TTL, promotion/pricing enrichment, coupon application and discount calculation, shipping method splitting (SCHEDULED vs FAST), and bulk operations. The CartResource response includes `subtotal`, `coupon_discount`, and `total_after_coupon` fields for frontend display of coupon savings.

## Key Files

| Layer | File |
|-------|------|
| Controller | `packages/marvel/src/Http/Controllers/CartController.php` |
| Repository | `packages/marvel/src/Database/Repositories/CartRepository.php` |
| Inventory Service | `app/Services/General/CartInventoryService.php` |
| Resource | `Marvel\Http\Resources\CartResource.php` |
| Item Resource | `Marvel\Http\Resources\CartItemResource.php` |
| Create Request | `Marvel\Http\Requests\CartCreateRequest.php` |
| Update Request | `Marvel\Http\Requests\CartUpdateRequest.php` |
| Model (Cart) | `Marvel\Database\Models\Cart.php` |
| Model (CartItem) | `Marvel\Database\Models\CartItem.php` |
| Routes | `packages/marvel/src/Rest/Routes.php` (lines 838-846) |

## Routes

All routes require `auth:sanctum` + `throttle:cart`. Base URL prefix: `/api/v1`

| Method | Endpoint | Purpose |
|--------|----------|---------|
| GET | `/api/v1/cart` | List user carts (paginated) |
| POST | `/api/v1/cart` | Add item to cart |
| GET | `/api/v1/cart/{id}` | Show specific cart |
| POST | `/api/v1/cart/bulk-items` | Add multiple items at once |
| PUT | `/api/v1/cart/update-item` | Update item quantity |
| DELETE | `/api/v1/cart/delete-item/{itemId}` | Remove single item |
| DELETE | `/api/v1/cart/delete-items` | Clear entire cart |

## Dependencies

- **Laravel DB Transactions** — atomic cart operations with `lockForUpdate()` pessimistic locking
- **Pricing Service** (`ProductPricingService`) — calculates current product/variant price (incl. flash sales, discounts)
- **PromotionService** — checks eligibility for promotions
- **CartInventoryService** — inventory reservation/release with stock management
- **CartRepository** — persistence with promotion revalidation
- **Spatie Media Library** — product thumbnail images (via ProductMiniResource)
