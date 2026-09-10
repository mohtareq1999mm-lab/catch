# Wishlist Module — Changelog (Authenticated API)

## [1.1.0] — 2026-08-04

### Added
- Comprehensive API investigation documentation (`api-desc/front/wishlist/`)
- Feature test suite `tests/Feature/WishlistApiTest.php` — 36 tests / 106 assertions

### Security Hardening
- All wishlist routes (`toggle`, `apiResource`, `my-wishlists`) now protected by `auth:sanctum` — previously unauthenticated (BUG-WISH-001)
- `index()` scoped to `$request->user()->id` — previously returned every user's wishlist (BUG-WISH-002)

### Fixed
- `destroy()`/`delete()` now align on `product_variant_id` query parameter — variant items could not be removed before (BUG-WISH-003)
- `apiResource` restricted to `->only(['index', 'store', 'destroy'])` — `show`/`update` previously 500'd (BUG-WISH-004)
- Prettus `findOneWhere(['product_variant_id' => null])` replaced with explicit `whereNull`/`where` lookup — `= NULL` never matched in SQL (BUG-WISH-005)
- Removed `sometimes` from `product_variant_id` validation rules — `requiredIf` + `sometimes` silently bypassed validation for absent fields (BUG-WISH-006)
- `myWishlists` now returns `ProductResource::collection($paginator)` — standard `{ data, meta, links }` shape instead of raw paginator (BUG-WISH-007)
- Duplicate wishlist items now return a translated 400 `ALREADY_ADDED_TO_WISHLIST_FOR_THIS_PRODUCT` instead of a generic error

### Known Issues
1. **No unique DB constraint** on `(user_id, product_id, product_variant_id)` — duplicates prevented only at the application layer. Plain unique index doesn't work with nullable variant column (BUG-WISH-008).
2. **`in_wishlist` is product-level** — ignores `product_variant_id` (BUG-WISH-009, by design).

## [1.0.0] — (pre-existing implementation)

### Existing Behaviour (now hardened above)
- 6 wishlist endpoints: list, add, toggle, remove (with variant support), guest-safe in-wishlist check, paginated my-wishlists
- Per-variant wishlist entries (`product_variant_id` nullable)
- WishlistResource reads Product pricing accessors backed by `ProductPricingService` (Frozen ADR authority)
