# Bug Report — Wishlist Module (Authenticated API)

---

## BUG-WISH-001: No Authentication Middleware on Wishlist Routes

**Severity:** Critical

**Component:** `packages/marvel/src/Rest/Routes.php` (lines 380-386)

**Description:** All four wishlist routes (`toggle`, `apiResource`, `my-wishlists`) were registered **outside** any `auth:sanctum` group. Consequences:

- Every authenticated user lookup (`$request->user()->id`) returned null → 500 on `index`/`store`/`destroy`/`myWishlists`.
- No authentication guard → the module was effectively unusable and insecure if requests happened to succeed.

**Status:** ✅ **Fixed** — routes wrapped in `Route::middleware(['auth:sanctum'])->group(...)`. `in_wishlist` intentionally stays public (guest-safe).

**Regression Coverage:** `test_*_requires_authentication` (5 tests) in `tests/Feature/WishlistApiTest.php`.

---

## BUG-WISH-002: `index()` Returns ALL Users' Wishlist Product IDs (Data Leak)

**Severity:** Critical

**Component:** `packages/marvel/src/Http/Controllers/WishlistController.php` — `index()`

**Description:** The index query filtered only by `product_id` collection without scoping `user_id`, so `GET /wishlists` returned the wishlist of **every user**, exposing product IDs across the whole user base.

**Status:** ✅ **Fixed** — `$request->user()->id` scope added to the repository query before plucking product IDs.

**Regression Coverage:** `test_index_only_returns_current_users_wishlist`, `test_index_not_affected_by_other_users_entries`.

---

## BUG-WISH-003: `destroy()` / `delete()` Cannot Remove Variant Wishlist Items

**Severity:** High

**Component:** `WishlistController.php` — `destroy()` / `delete()`

**Description:** `destroy()` merged a `variant_id` value from the query string, but `delete()` read `$request->product_variant_id`. The two names never matched, so a `product_variant_id` could never be read. Variant items could not be removed via the API (404 instead).

**Status:** ✅ **Fixed** — `destroy()` now merges `product_variant_id` and `delete()` reads `$request->product_variant_id`. The where clause uses `where('product_variant_id', ...)` when present and `whereNull('product_variant_id')` otherwise.

**Regression Coverage:** `test_destroy_variant_wishlist_item`, `test_destroy_simple_product_does_not_delete_variant_entries`.

---

## BUG-WISH-004: `apiResource` Registers `show`/`update` That 500

**Severity:** High

**Component:** `packages/marvel/src/Rest/Routes.php`

**Description:** `Route::apiResource('wishlists', ...)` registers `show` and `update` routes, but `WishlistController` has no `show`/`update` methods. Any request to those routes threw a `BadMethodCallException` → 500.

**Status:** ✅ **Fixed** — restricted to `->only(['index', 'store', 'destroy'])`. `show` and `update` now return 405 Method Not Allowed.

**Regression Coverage:** `test_show_route_returns_405`, `test_update_route_returns_405`.

---

## BUG-WISH-005: Prettus `findOneWhere` Generates `product_variant_id = NULL` (Never Matches)

**Severity:** High

**Component:** `packages/marvel/src/Database/Repositories/WishlistRepository.php`

**Description:** The repository previously used Prettus `findOneWhere(['product_variant_id' => null])`, which produces `WHERE product_variant_id = NULL`. In SQL that predicate never matches (`NULL = NULL` is unknown), so:

- Duplicate detection for simple products silently failed → duplicates could be created.
- Toggling a simple product always behaved as "not present" → re-add instead of remove.

**Status:** ✅ **Fixed** — new `findUserWishlistItem(int $user_id, $product_id, $product_variant_id)` uses an explicit `whereNull('product_variant_id')` for simple products and `where('product_variant_id', $variantId)` for variants.

**Regression Coverage:** `test_toggle_adds_product`, `test_toggle_removes_product`, `test_toggle_simple_product_no_duplicates`, `test_store_duplicate_simple_product_returns_400`.

---

## BUG-WISH-006: `Rule::requiredIf` + `sometimes` Bypasses Variant Validation

**Severity:** High

**Component:** `packages/marvel/src/Http/Requests/WishlistCreateRequest.php`

**Description:** The `product_variant_id` rules were declared with `'sometimes'` combined with `Rule::requiredIf(...)`. In Laravel, `sometimes` runs `passesOptionalCheck()`, which returns `false` for an absent field and **skips all rules — including the implicit `required` added by `requiredIf`**. Result: a variable product without a `product_variant_id` was accepted (200 instead of 422).

**Status:** ✅ **Fixed** — removed `'sometimes'`. Rules now: `Rule::requiredIf(fn () => $product->is_variation)`, `integer`, `exists:product_variants,id`.

**Regression Coverage:** `test_store_variable_product_without_variant_returns_422`, `test_store_variable_product_with_variant_succeeds`.

---

## BUG-WISH-007: `myWishlists` Returns Raw Paginator Instead of Resource Collection

**Severity:** Medium

**Component:** `packages/marvel/src/Http/Controllers/ProductController.php` — `myWishlists()`

**Description:** The endpoint returned the raw paginator object, leaking paginator internals and not producing the standard `ProductResource` serialization used by other product endpoints.

**Status:** ✅ **Fixed** — now returns `ProductResource::collection($this->fetchWishlists($request)->paginate($limit))` → standard `{ data, links, meta }` paginated shape.

**Regression Coverage:** `test_my_wishlists_paginated`.

---

## BUG-WISH-008: No Unique Index on `(user_id, product_id, product_variant_id)`

**Severity:** Medium

**Component:** `wishlists` table migration

**Description:** Duplicates are only prevented at the application layer (`findUserWishlistItem`). A race condition (two concurrent requests) could insert duplicate rows. A plain composite unique index does **not** work because `product_variant_id` is nullable — MySQL/SQLite treat `NULL` values as distinct.

**Status:** 🔧 **Open — Recommendation** — add a generated/stored column as a sentinel (e.g. `COALESCE(product_variant_id, 0)`) and create a unique index on `(user_id, product_id, variant_sentinel)`, or use `NULLS NOT DISTINCT` (PostgreSQL 15+). App-layer guard remains the current protection.

---

## BUG-WISH-009: `in_wishlist` Ignores Variant (By Design)

**Severity:** Info

**Component:** `WishlistController.php` — `inWishlist()`

**Description:** `GET /wishlists/in_wishlist/{product_id}` answers "is this product in the wishlist?" at product level only — it does not consider `product_variant_id`. If a user wishlisted one variant but not another, the check returns `true` for both.

**Status:** ℹ️ **Documented** — deliberate design. Documented in `api.md` so the frontend knows the semantic is product-level.
