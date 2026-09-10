# Wishlist Module — Backend Jira Tasks

---

## Task 1: Protect All Wishlist Routes with `auth:sanctum`

**Priority:** Critical
**Component:** Routes
**Effort:** Trivial
**Status:** ✅ Done
**Files:**
- `packages/marvel/src/Rest/Routes.php`

**Description:** Wrap `wishlists/toggle`, `wishlists` apiResource, and `my-wishlists` in an `auth:sanctum` group. `in_wishlist` stays public (guest-safe).

**Acceptance Criteria:**
- [x] All authenticated endpoints return 401 for guests
- [x] `in_wishlist` still works for guests (data=false)

---

## Task 2: Fix User Scoping in `index()`

**Priority:** Critical
**Component:** WishlistController
**Effort:** Trivial
**Status:** ✅ Done
**Files:**
- `packages/marvel/src/Http/Controllers/WishlistController.php`

**Description:** Scope the wishlist query to `$request->user()->id` so users only see their own items.

**Acceptance Criteria:**
- [x] `GET /wishlists` returns only the current user's products
- [x] Other users' entries are never leaked

---

## Task 3: Fix Variant Removal in `destroy()` / `delete()`

**Priority:** High
**Component:** WishlistController
**Effort:** Small
**Status:** ✅ Done
**Files:**
- `packages/marvel/src/Http/Controllers/WishlistController.php`

**Description:** `destroy()` merged `variant_id` but `delete()` read `product_variant_id`. Align both on `product_variant_id`; use `whereNull` for simple products.

**Acceptance Criteria:**
- [x] Removing a variant item with `?product_variant_id=` succeeds
- [x] Removing a simple item does not delete variant entries
- [x] Removing a product not in the user's wishlist returns 404

---

## Task 4: Restrict apiResource to Existing Methods

**Priority:** High
**Component:** Routes
**Effort:** Trivial
**Status:** ✅ Done
**Files:**
- `packages/marvel/src/Rest/Routes.php`

**Description:** `apiResource` registered `show`/`update` which had no controller methods (500). Restrict to `->only(['index', 'store', 'destroy'])`.

**Acceptance Criteria:**
- [x] `show`/`update` return 405 (not 500)

---

## Task 5: Fix Prettus NULL Predicate in Duplicate Detection

**Priority:** High
**Component:** WishlistRepository
**Effort:** Small
**Status:** ✅ Done
**Files:**
- `packages/marvel/src/Database/Repositories/WishlistRepository.php`

**Description:** `findOneWhere(['product_variant_id' => null])` generates `= NULL` which never matches. Add `findUserWishlistItem()` with explicit `whereNull`/`where` clauses.

**Acceptance Criteria:**
- [x] Toggling a simple product adds then removes correctly
- [x] Adding a duplicate simple product returns 400
- [x] Same product with different variants are treated as distinct

---

## Task 6: Fix `requiredIf` + `sometimes` Validation Bypass

**Priority:** High
**Component:** WishlistCreateRequest
**Effort:** Small
**Status:** ✅ Done
**Files:**
- `packages/marvel/src/Http/Requests/WishlistCreateRequest.php`

**Description:** `sometimes` + `Rule::requiredIf` skips all rules for absent fields, letting variable products be added without a variant. Remove `sometimes`.

**Acceptance Criteria:**
- [x] Variable product without variant → 422
- [x] Variable product with valid variant → 200
- [x] Simple product without variant → 200

---

## Task 7: Return Resource Collection from `myWishlists`

**Priority:** Medium
**Component:** ProductController
**Effort:** Small
**Status:** ✅ Done
**Files:**
- `packages/marvel/src/Http/Controllers/ProductController.php`

**Description:** Return `ProductResource::collection($paginator)` instead of the raw paginator for standard `{ data, meta, links }` serialization.

**Acceptance Criteria:**
- [x] Response uses standard paginated shape
- [x] `meta.total` / `meta.per_page` present

---

## Task 8: Add Feature Tests for All Wishlist Endpoints

**Priority:** High
**Component:** Tests
**Effort:** Medium
**Status:** ✅ Done
**Files:**
- `tests/Feature/WishlistApiTest.php` (new)

**Description:** 36 tests / 106 assertions covering auth, scoping, store, toggle, destroy, in_wishlist, my-wishlists, validation, structure, and edge cases.

**Acceptance Criteria:**
- [x] All 5 authenticated endpoints return 401 for guests
- [x] User scoping on index, in_wishlist, my-wishlists
- [x] Duplicate → 400 translated message
- [x] Variant add/remove flows
- [x] Validation failures → 422
- [x] show/update → 405

---

## Task 9: Add Unique Constraint for Wishlist Entries (Recommended, Not Implemented)

**Priority:** Medium
**Component:** Database / migration
**Effort:** Small
**Status:** 🔧 Open
**Files:**
- `wishlists` table migration

**Description:** Prevent duplicate rows at the DB level. A plain unique index on `(user_id, product_id, product_variant_id)` won't work because NULLs are distinct in MySQL/SQLite. Use a generated sentinel column (`COALESCE(product_variant_id, 0)`) with a unique index, or `NULLS NOT DISTINCT` on PostgreSQL 15+.

**Acceptance Criteria:**
- [ ] Concurrent duplicate inserts are rejected by the DB
- [ ] Simple (NULL variant) and variant rows both protected
- [ ] Existing app-layer guard retained (defense in depth)
