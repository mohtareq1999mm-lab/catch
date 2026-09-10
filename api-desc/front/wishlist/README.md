# Wishlist Module — Frontend (Authenticated API)

## Overview

The Wishlist module lets an authenticated user save products for later. It supports adding, listing, toggling, removing (including per-variant removal) and a guest-safe "is in wishlist" check. A dedicated `my-wishlists` endpoint returns the user's wishlist products in the standard paginated ProductResource shape.

> **Security hardening:** As of this investigation, all mutating/listing endpoints (`toggle`, `apiResource`, `my-wishlists`) are now protected by `auth:sanctum`. `in_wishlist` remains public and returns `false` for guests.

## Key Files

| Layer | File |
|-------|------|
| Controller | `packages/marvel/src/Http/Controllers/WishlistController.php` |
| Product Controller (myWishlists) | `packages/marvel/src/Http/Controllers/ProductController.php` |
| Repository | `packages/marvel/src/Database/Repositories/WishlistRepository.php` |
| Resource | `Marvel\Http\Resources\WishlistResource.php` |
| Create Request | `Marvel\Http\Requests\WishlistCreateRequest.php` |
| Model | `Marvel\Database\Models\Wishlist.php` |
| Table migration | `packages/marvel/database/migrations/2021_10_12_193855_create_reviews_table.php` (lines 32-41) |
| Routes | `packages/marvel/src/Rest/Routes.php` (lines 380-386) |
| Pricing | `packages/marvel/src/Services/Pricing/ProductPricingService.php` (Frozen ADR authority) |

## Routes

Base URL prefix: `/api/v1`. All routes except `in_wishlist` require `auth:sanctum`.

| Method | Endpoint | Purpose | Auth |
|--------|----------|---------|------|
| GET | `/api/v1/wishlists` | List current user's wishlist products | auth:sanctum |
| POST | `/api/v1/wishlists` | Add a product (duplicate → 400) | auth:sanctum |
| DELETE | `/api/v1/wishlists/{product_id}` | Remove a product (`?product_variant_id=` for variants) | auth:sanctum |
| POST | `/api/v1/wishlists/toggle` | Add or remove a product | auth:sanctum |
| GET | `/api/v1/wishlists/in_wishlist/{product_id}` | Check if product is in the current user's wishlist | public (guest-safe) |
| GET | `/api/v1/my-wishlists` | Paginated wishlist products (ProductResource) | auth:sanctum |

## Dependencies

- **Authentication** — `auth:sanctum` middleware on all authenticated routes
- **Pricing Service** (`ProductPricingService`, Frozen ADR) — computes `current_price`, `price_after_discount`, `price_after_flash_sale` on Product serialization (no hidden SQL in pricing flows)
- **Product model** — `variations`, `attributeProducts`, `flash_sales` relations used by WishlistResource
- **Translations** — `MESSAGE.ADDED_TO_WISHLIST_SUCCESSFULLY`, `ERROR.ALREADY_ADDED_TO_WISHLIST_FOR_THIS_PRODUCT`, `MESSAGE.REMOVED_FROM_WISHLIST_SUCCESSFULLY` in `resources/lang/{en,ar}/message.php`
