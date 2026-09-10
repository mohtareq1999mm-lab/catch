# Review Module

## Overview

The Review module manages product reviews for the e-commerce platform. Customers can submit ratings and comments for products they have purchased. Reviews support moderation through an approval toggle, and include abuse reporting and feedback (helpful/not helpful) mechanisms.

**Authentication:** All endpoints require `auth:sanctum` — there are no public review endpoints.

## Key Files

| Layer | File |
|-------|------|
| Controller | `packages/marvel/src/Http/Controllers/ReviewController.php` |
| Repository | `packages/marvel/src/Database/Repositories/ReviewRepository.php` |
| Model | `packages/marvel/src/Database/Models/Review.php` |
| Resource | `packages/marvel/src/Http/Resources/ReviewResource.php` |
| Create Request | `packages/marvel/src/Http/Requests/ReviewCreateRequest.php` |
| Update Request | `packages/marvel/src/Http/Requests/ReviewUpdateRequest.php` |
| Routes | `packages/marvel/src/Rest/Routes.php` (lines 214-215, 344-346, 457-463) |
| Permissions | `packages/marvel/src/Enums/Permission.php` |
| Reviews Migration | `packages/marvel/database/migrations/2021_10_12_193855_create_reviews_table.php` |
| Constants | `packages/marvel/config/constants.php` |
| Tests | `tests/Feature/ProductCrudTest.php` (review section, lines 617-740) |

## Dependencies

- **Spatie Media Library** (`InteractsWithMedia`) — review image management (commented out in requests)
- **Laravel SoftDeletes** — soft delete support
- **Prettus Repository** — repository pattern with caching

## Permissions

| Permission | Required For |
|------------|-------------|
| `approve-reviews` | PATCH /reviews/{id}/toggle-approve |
| `delete-reviews` | DELETE /reviews/{id} |

Additional customer-level permissions exist in the Permission enum (`create-review`, `update-review`, `delete-review`) but are not enforced via controller middleware — the controller only gates `approve-reviews` and `delete-reviews`.

## Routes

| Method | Endpoint | Purpose |
|--------|----------|---------|
| GET | `/api/v1/reviews` | List reviews by product_id (paginated) |
| POST | `/api/v1/reviews` | Create a review (rate-limited, 5/min) |
| GET | `/api/v1/reviews/{id}` | Show a single review |
| PUT | `/api/v1/reviews/{id}` | Update a review (rate-limited, 5/min) |
| DELETE | `/api/v1/reviews/{id}` | Delete a review |
| PATCH | `/api/v1/reviews/{id}/toggle-approve` | Toggle review approval status |

Rate limiting is applied to `store` and `update` via the `throttle:content` middleware (5 requests per minute per user).
