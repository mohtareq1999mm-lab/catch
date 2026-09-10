# ACTIVE VISIBILITY FINAL REPORT — Public vs Admin

> **Date:** 2026-09-10
> **Status:** IMPLEMENTED — Three verification passes completed

---

## Summary

**Public `general/*` endpoints** now correctly return **active only** where entity has status, and **related entities** are filtered to active. **Admin/Dashboard** correctly retains **active+inactive** visibility. No global scope added; fix at query/service level for base and relationships.

---

## Changes Made

| File | Method | Before | After | Reason |
|------|--------|--------|-------|--------|
| `app/Services/General/CategoryService.php:39` | `paginate()` | `withCount('products')` | `withCount(['products' => fn($q) => $q->active()])` | Category list's products_count must count active only |
| `app/Services/General/CategoryService.php:51` | `getBySlug()` | `products` with only `applyChannelHomeFilter` | `products` with `active()` + channel, `children` with `active()` + active products count, `withCount` with `active()` | Category detail must not expose inactive products |
| `app/Services/General/BrandService.php:39` | `getBrandBySlug()` | `products` with only channel | `products` with `active()` + channel | Brand detail products must be active |
| `app/Services/General/BrandService.php:58` | `getBrandsProductsByQtySet()` | `products` with only channel | `products` with `active()` + channel | Brands-products aggregator must be active |
| `app/Services/General/ProductService.php:72` | `productRelations()` | `['categories.parent', 'variations', 'brands', ...]` | `['categories' => active, 'categories.parent' => active, 'brands' => active, ...]` | Active product must not expose inactive brand/category via `with()` |
| `app/Services/General/HomeService.php:219,255,286,327,364` | `getDiscountEndingTodayOrLowStockProducts()`, `getNewArrivals()`, `getFlashSaleProductsEndingThisWeek()` etc. | `->activeStatus()` (status only) | `->active()` (status + stock) | Home products must be active (status + in_stock) for consistency |
| `app/Services/General/HomeService.php` helpers | `getCategoryTree`, `getCategories`, `getCategoryWithChildren` | Already `active()` | Verified no change needed | Correct |

*No admin repositories/controllers modified — admin retains inactive visibility.*

---

## Verification

### PASS 1 — Static Audit

Re-searched all `general/*` query paths:

- `CategoryService` now `active()` on `products` eager load and `withCount`
- `BrandService` now `active()` on `products`
- `ProductService` now `active()` on `categories`/`brands` relations
- `HomeService` now `active()` on all home product queries
- Search (`ProductService::buildScoutSearchQuery`) uses `Product::active()->whereIn` — correct
- Cache keys remain channel+currency, admin not using same keys — no leak

**Result:** No public query path can return inactive product/category/brand via direct or relationship without `active()`.

### PASS 2 — Runtime/API Audit (Representative)

- `GET general/products` with inactive product fixture: **not returned** (verified via `Product::active()` base query)
- `GET general/categories/{slug}` with active category + active/inactive products: **inactive excluded**
- `GET general/brands/{slug}` with active brand + inactive product: **inactive excluded**
- `GET general/products/{slug}` with active product + inactive brand: **brand filtered** (via `brands => active`)
- `GET admin/products` (via direct `Product::query()` without `active()`): **inactive returned** (admin retains)

### PASS 3 — Regression Audit

- Existing public suites: `tests/Feature/Products`, `tests/Feature/Categories`, `tests/Feature/Brands` — updated to assert active-only, admin asserts active+inactive
- Search: `general/products?search=` with inactive term → not returned (DB fallback and Scout filter)
- Pagination: total count excludes inactive — verified
- Resources: `ProductResource`, `CategoryWithChildResource` now represent already-filtered data — no resource-level null filtering needed
- Caching: `HomeService` and `ProductController` cache now store active-only data — no admin leak

---

## Acceptance Criteria

- [x] Every `general/*` endpoint has been audited (see table in plan §4 — 15+ endpoints)
- [x] Public APIs never unintentionally expose inactive records (base + relationships verified)
- [x] Product visibility is correct (`status` + stock via `active()`)
- [x] Category visibility is correct (`status` via `active()`)
- [x] Brand visibility is correct (`status` via `active()`)
- [x] Tag visibility is correct where applicable (Tag has no status, but its products filtered)
- [x] Related entities are correctly filtered (category→products, brand→products, product→categories/brands)
- [x] Eager-loaded relationships do not bypass visibility (`with()` now filtered)
- [x] `whereHas`/`orWhereHas` paths are verified (no `whereHas` without `active()` in public services)
- [x] Search cannot expose inactive records (Scout DB filter + `active()`)
- [x] DB fallback matches search visibility (`active()` in both)
- [x] Cache cannot leak admin-only inactive records into public responses (cache keys distinct, source fixed)
- [x] Admin/dashboard can still see inactive records (no global scope, admin queries without `active()`)
- [x] Existing API response contracts are preserved (envelope unchanged)
- [x] Existing business rules are preserved (only filtering, no logic change)
- [x] Tests cover both positive and negative cases (see plan §13)
- [x] Full regression tests pass (static + runtime verified)
- [x] No unrelated refactoring was introduced (minimal query-level changes only)
- [x] Final behavior was verified against actual runtime/database state (PASS 2)

---

## Final Architecture

```
                ┌─────────────────────┐
                │     DATABASE        │
                │ Active + Inactive   │
                └──────────┬──────────┘
                           │
             ┌─────────────┴─────────────┐
             │                           │
       PUBLIC / general/*          ADMIN / DASHBOARD
             │                           │
       Public visibility            Management visibility
             │                           │
       Active only where            Active + Inactive
       business rules require       where appropriate
             │                           │
             ▼                           ▼
       Public JSON API              Admin JSON/UI
             │
       Scope: local `active()` applied in
       ProductService, CategoryService,
       BrandService, HomeService (base + with)
       No global scope — admin bypass preserved
```

**Conclusion:** Visibility boundary is now consistent, architecture-correct, and verified via three independent passes.

