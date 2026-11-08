# Manual Cross-Selling — Implementation Plan (v2)

> **Status:** PHASE ONE COMPLETE — READ-ONLY DISCOVERY AND PLAN UPDATE
> **Plan version:** v2 (supersedes v1)
> **Created:** 2026-11-08
> **Repository:** `D:\work\meem`
> **Branch / HEAD:** `main` / `39b2c331b7582462936a272daa6fa3184652be57`
> **Framework:** Laravel 10.30.1 (`php artisan --version`)
> **Implementation status:** NOT STARTED. No application code, migration, route, config, frontend source, test, or production data has been changed by this task.
> **Approval required:** YES. Phase Two must not begin until the user explicitly approves this plan.

---

## Evidence legend

| Tag | Meaning |
|---|---|
| `[VERIFIED]` | Confirmed by reading current source or by a command executed during discovery. |
| `[VERIFIED-NEGATIVE]` | Confirmed that the item does not exist in this repository. |
| `[TESTED — EXISTING]` | Covered by an existing test that was inspected, not re-run for this document. |
| `[INFERRED]` | Follows logically from verified code, but was not executed end-to-end. |
| `[UNKNOWN]` | Not present in this repository, or not decidable from repository evidence. |
| `[RISK]` | Regression, security, data-integrity, or operational concern. |
| `[DECIDED — USER]` | Final business decision supplied by the user; not open for reinterpretation. |
| `[PROPOSED]` | Design recommendation that still requires approval before implementation. |

---

## 1. Executive Summary

### 1.1 Goal

Add a **manual, ordered, directional cross-selling list** to products, integrated into the existing product admin workflow and the existing storefront product-detail endpoint.

- A merchandiser chooses recommended products per source product.
- Recommendations are independent per product: `A → B` does not create `B → A`.
- The storefront product-detail response exposes recommendations as `cross_sell_products`.
- Only **eligible** recommendations are returned, in saved order, up to **8**.
- All saved relationships remain persisted and visible in Admin, including currently unavailable ones.
- The feature is **display-only**; no Cart, Checkout, Order, Pricing, Inventory, or Payment behavior changes.

### 1.2 Integration decision

No new public route. No new admin route. No new permission.

| Surface | Existing endpoint | Change |
|---|---|---|
| Storefront product detail | `GET /api/v1/general/products/{slug}` | Add `cross_sell_products` key. |
| Admin product create | `POST /api/v1/products` | Accept optional ordered `cross_sell_ids`; return cross-sell keys. |
| Admin product update | `PUT/PATCH /api/v1/products/{product}` | Accept optional ordered `cross_sell_ids`; sync ordered links; return cross-sell keys. |
| Admin product show | `GET /api/v1/products/{product}` | Return cross-sell keys with saved/eligible counts and availability. |
| Admin product search (picker) | `GET /api/v1/products?search={term}&limit=20` | Reused as-is. |

`[VERIFIED]` — the routes above exist and are the canonical product surfaces (see §3).

The user cited `general/product.slug`; the actual route is `GET /api/v1/general/products/{slug}`. `[VERIFIED]` `routes/api.php:51,87-88`.

### 1.3 Persistence summary

New directional pivot table `product_cross_sells`:

```text
product_cross_sells
  id                   bigint unsigned PK
  product_id           FK products.id, cascade delete
  related_product_id   FK products.id, cascade delete
  sort_order           unsigned int, default 0
  created_at, updated_at
  UNIQUE (product_id, related_product_id)
  INDEX  (product_id, sort_order)
```

`A → B` is stored as one row. No reverse row is created.

### 1.4 Final decisions applied

| Old plan (v1) | v2 (final) |
|---|---|
| Max **4 saved** relationships | **No maximum on saved relationships** |
| `config/cross_selling.php` + `cross_sell_max_items` | **Fixed storefront display limit of 8**, exposed as `storefront_cross_sell_limit` in the admin response |
| Table `product_cross_sell` (singular) | Table **`product_cross_sells`** (plural) |
| Inactive/out-of-stock targets hidden from admin | **All saved targets visible in Admin** with availability status and reasons |
| Storefront empty array only | Storefront returns at most 8 eligible, ordered; frontend omits section when empty |

Old v1 artifacts (`cross_sell_max_items`, max-4 validation, singular table name) are **obsolete** and must not be implemented.

### 1.5 Delivery shape

Expected production change set:

- 1 additive migration.
- 1 new validation rule.
- 2 model relations.
- 1 repository ordered sync method + 1 call site.
- 2 request rule additions.
- 1 admin resource block + 1 helper.
- 3 controller eager-load additions.
- 1 storefront service constant + 1 method + 1 call site.
- 1 storefront resource key.
- 2 activity translation keys + 2 validation translation files.
- Test-schema support + 2 new test files.

No new package, config file, queue, event bus, service container binding, route, or permission.

---

## 2. Final Business Decisions

### D1 — Independent directional recommendations `[DECIDED — USER]`

- Each product owns its own list.
- `Product A → [B, C]` does not create `Product B → A`.
- No reciprocal-link automation.
- Enforcement: one pivot row per directed pair; reads filter by `product_id = source`.

### D2 — Maximum 8 **available** products on the storefront `[DECIDED — USER]`

- The limit applies to **eligible products displayed**, not to saved rows.
- Example accepted by the user: 9 saved relationships (5 available, 4 unavailable) → storefront shows 5.
- Admin retains every saved relationship, available or not.
- No arbitrary maximum on saved relationships.
- Enforcement: `ProductService::STOREFRONT_DISPLAY_LIMIT = 8` applied to the storefront query.

### D3 — No section when empty or all unavailable `[DECIDED — USER]`

- No configured recommendations → no section.
- Recommendations exist but none eligible → no section (not an empty section).
- Fewer than 8 eligible → show only the eligible ones.
- Backend contract: `cross_sell_products: []` when nothing passes eligibility; the storefront UI renders nothing for an empty array.

### D4 — Display-only `[DECIDED — USER]`

Must not:

- add products to the cart,
- alter Cart or Checkout behavior,
- alter order creation,
- alter pricing,
- reserve or mutate inventory,
- alter payment behavior,
- add Add-to-Cart controls as part of this feature.

Enforcement: no writes outside `product_cross_sells`; no new calls into cart/checkout/order/payment/inventory services.

### D5 — Unavailable products `[DECIDED — USER]`

- Remain saved in the database.
- Remain visible in Admin.
- Show a clear unavailable status and reason(s) derived from existing project rules.
- Hidden from the storefront.
- Can be replaced by adding other recommendations.
- Become visible again automatically when they satisfy the canonical storefront rules.
- Never silently deleted or overwritten because they became unavailable.

### D6 — Single-shop `[DECIDED — USER]`

- No `shop_id`, store ownership, vendor ownership, or tenant column in the pivot.
- No multi-vendor logic.
- Existing shop fields on products are not used to restrict cross-sell.

### 2.1 Derived rules

| ID | Rule | Enforcement |
|---|---|---|
| BR-01 | Directional only; no reverse row. | Pivot rows keyed by source. |
| BR-02 | Saved list may be unbounded. | No `max` on `cross_sell_ids`; only array/integrity rules. |
| BR-03 | Storefront returns at most 8 eligible, in saved order. | SQL filters + `orderByPivot(sort_order)` + `limit(8)`. |
| BR-04 | Unavailable targets stay saved and visible in Admin. | Admin relation includes trashed; storefront filters. |
| BR-05 | Empty result means no section. | Empty array; frontend behavior documented. |
| BR-06 | Display-only. | No side effects outside the pivot. |
| BR-07 | Self, duplicates, non-existent IDs rejected. | Custom rule + `distinct` + repository guard. |
| BR-08 | Existing `related_products` unchanged. | Separate relation/key; regression tests. |
| BR-09 | No new routes or permissions. | Reuse existing product endpoints/middleware. |
| BR-10 | Order preserved exactly as submitted. | `sort_order = 1..N` on sync; ordered relation. |

---

## 3. Verified Current Architecture

### 3.1 Repository layout

| Layer | Location | Relevance |
|---|---|---|
| Application layer | `app/` | Storefront controller/service/resources, custom validation rules, channel context |
| Vendored commerce kernel | `packages/marvel/` | Product model, repository, admin controller/requests/resources, admin routes |
| Routes | `routes/api.php`, `packages/marvel/src/Rest/Routes.php` | Public and admin product surfaces |
| Migrations | `database/migrations/` | Additive pivot migration |
| Tests | `tests/` | PHPUnit 10 + MySQL `catch` + manual schema trait |
| Admin UI | **not in this repository** | Backend contract only; UI work is external |

`[VERIFIED]` — both `app/` and `packages/marvel/` contain product code; ownership must follow existing boundaries (see §3.8).

### 3.2 Storefront product-detail flow

```text
GET /api/v1/general/products/{slug}
  -> routes/api.php:51 (prefix v1/general), :88 (products/{slug})
  -> App\Http\Controllers\Api\General\ProductController::getProductBySlug (:239-250)
  -> App\Services\General\ProductService::getProductBySlug (:235-270)
  -> App\Http\Resources\Product\ProductResource (:23-77)
  -> Marvel\Traits\ApiResponse::apiResponse
```

Verified anchors:

| Fact | Evidence |
|---|---|
| Route group and detail route | `routes/api.php:51,87-88` |
| Controller action | `app/Http/Controllers/Api/General/ProductController.php:239-250` |
| Service injection | `app/Http/Controllers/Api/General/ProductController.php:33-37` |
| Detail query + eager loads | `app/Services/General/ProductService.php:237-257` |
| Channel filter call | `app/Services/General/ProductService.php:259` |
| 404 path | `app/Services/General/ProductService.php:263-265`; controller `:245-247` |
| `related_products` assignment | `app/Services/General/ProductService.php:267` |
| Pricing enrichment | `app/Services/General/ProductService.php:66-69, 269` |
| `related_products` serialization | `app/Http/Resources/Product/ProductResource.php:72` |
| Mini product shape | `app/Http/Resources/Product/ProductMiniResource.php:19-53` |
| Envelope | `packages/marvel/src/Traits/ApiResponse.php:9-22` |

`[VERIFIED]` — `php artisan route:list --path=products` confirmed:

```text
GET|HEAD  api/v1/general/products/{slug}  App\Http\Controllers\Api\General\ProductController@getProductBySlug
```

There is **no** `GET /api/v1/general/product/{slug}` route. `[VERIFIED-NEGATIVE]`

### 3.3 Existing `related_products` behavior

`ProductService::fetchRelated()` (`app/Services/General/ProductService.php:645-666`):

- Reads source product's category IDs.
- Returns `active()` products in those categories, excluding the source.
- Applies `productRelations()`, approved-review aggregates, and `applyChannelHomeFilter()`.
- Assigned to `related_products` at `:267`; serialized with `ProductMiniResource` at resource `:72`.

Decision: `related_products` is not modified, not reused as storage, and not replaced. Manual cross-sell gets a separate relation and response key. `[DECIDED — USER]`

### 3.4 Existing admin product flow

| Concern | Evidence | Finding |
|---|---|---|
| Route group | `packages/marvel/src/Rest/Routes.php:135` | `auth:sanctum`, `throttle:admin`, `lang` |
| Product resource routes | `packages/marvel/src/Rest/Routes.php:252-267` | `apiResource('products')` at `:267` |
| Method permissions | `packages/marvel/src/Http/Controllers/ProductController.php:105-114` | `view-products`, `create-product`, `update-product`, `delete-product` |
| Permission constants | `packages/marvel/src/Enums/Permission.php:36-37,145-148` | Exact string values verified |
| Admin list/search | `packages/marvel/src/Http/Controllers/ProductController.php:123-156` | `search` at `:126,133-135`; cached list at `:153` |
| Store handler | `packages/marvel/src/Http/Controllers/ProductController.php:238-259` | Repository call, `loadMissing`, cache flush, response |
| Show handler | `packages/marvel/src/Http/Controllers/ProductController.php:317-325` | `fetchSingleProduct`, response |
| Show relation loading | `packages/marvel/src/Http/Controllers/ProductController.php:334-355` | Loads categories, flash_sales, banners, sliders, brands, reviews, tags, barcodes |
| Update handler | `packages/marvel/src/Http/Controllers/ProductController.php:364-379` | Repository call, `loadMissing`, cache flush, response |
| Writable columns | `packages/marvel/src/Database/Repositories/ProductRepository.php:53-81` | Whitelist; inventory columns intentionally excluded |
| Store transaction | `packages/marvel/src/Database/Repositories/ProductRepository.php:111-168` | `beginTransaction` `:114`; `syncRelation` `:149`; `commit` `:158` |
| Update transaction | `packages/marvel/src/Database/Repositories/ProductRepository.php:171-314` | `beginTransaction` `:174`; `syncRelation` `:297`; `commit` `:304` |
| Relation sync | `packages/marvel/src/Database/Repositories/ProductRepository.php:376-403` | categories, brands, banners, sliders, tags, flash sales |
| Audited sync | `packages/marvel/src/Database/Repositories/ProductRepository.php:409-436` | **Sorts IDs** at `:417-422`; cannot preserve merchant order |
| Admin resource | `packages/marvel/src/Http/Resources/product/ProductResource.php:67` | `related_products` only |
| Audit writer | `app/Audit/ActivityAuditService.php:50-60` | `recordModel(Model, event, logName, description, old, new, ...)` |

`[VERIFIED]` — `php artisan route:list --path=api/v1/products` confirmed `GET`, `POST`, `PUT/PATCH`, `DELETE` product resource routes with product permission middleware.

### 3.5 Product model facts

| Fact | Evidence |
|---|---|
| Namespace | `packages/marvel/src/Database/Models/Product.php:3` |
| Soft deletes | `:29` (`use ... SoftDeletes ...`) |
| Casts | `:105-115` (`status` integer, `in_stock` boolean, `is_fast_shipping_available` boolean, stock integers) |
| SKU hook | `:131-143` |
| Fast-shipping global scope | `:143` (`static::addGlobalScope(new FastShippingScope)`) |
| `categories()` | `:330-336` (plain belongsToMany, no status filter) |
| `tags()` | `:354-357` |
| `orders()` | `:364` |
| `scopeActiveStatus()` | `:573-576` (`status = 1`) |
| `scopeActive()` | `:578-587` (status + stock + category-active rules) |

### 3.6 Caching

| Surface | Behavior | Evidence |
|---|---|---|
| Storefront detail | Not cached | No cache wrapper in `getProductBySlug` |
| Admin product list | Cached by full URL | `ProductController.php:153` |
| Admin store/update | Flush `FrontendResource::PRODUCTS` | `:257`, `:375` |

Implication: cross-sell changes are visible on the next storefront detail request; no new cache tag or invalidation hook is required. `[VERIFIED]`

`[RISK]` — if the detail endpoint is cached later, cross-sell changes will need explicit invalidation. Record this in the implementation review.

### 3.7 Admin UI absence

`[VERIFIED-NEGATIVE]` — the admin dashboard frontend is not in this repository:

- `docs/audits/payment-gateway-settings-unification-audit.md:56` states the admin dashboard UI is not in this repository.
- `resources/js` contains only `app.js`, `bootstrap.js`, `file-operations.js`, `products-cursor-pagination.js`.
- `resources/views` contains emails and a welcome view, not an admin SPA.
- Repository-wide search for `cross_sell`, `crossSell`, `product_cross` returned no existing implementation.

Consequence: §7 is a **backend contract and UI behavior specification**. Exact component paths and framework choices remain `[UNKNOWN]` until the dashboard repository is provided. No UI file may be fabricated in this repository.

### 3.8 Ownership decision

| Component | Owner | Reason |
|---|---|---|
| Pivot migration | `database/migrations/` | Additive persistence shared by app and Marvel |
| `Product::crossSellProducts()`, `crossSellProductsAdmin()` | `packages/marvel/src/Database/Models/Product.php` | Model already owns all product relations |
| Ordered sync | `packages/marvel/src/Database/Repositories/ProductRepository.php` | Product writes and relation syncing already live here |
| Validation rule | `app/Rules/ValidCrossSellIds.php` | Existing Marvel requests already consume `App\Rules\*` (e.g. `UniqueProductBarcode`) |
| Request rules | `packages/marvel/src/Http/Requests/ProductCreateRequest.php`, `ProductUpdateRequest.php` | Existing product validation |
| Admin response | `packages/marvel/src/Http/Resources/product/ProductResource.php` | Existing admin product resource |
| Controller eager loads | `packages/marvel/src/Http/Controllers/ProductController.php` | Existing admin controller |
| Storefront read | `app/Services/General/ProductService.php` | Existing storefront relation loading and pricing enrichment |
| Storefront response | `app/Http/Resources/Product/ProductResource.php` | Existing storefront detail resource |

This preserves the application/Marvel boundary: product **writes** stay in Marvel; storefront **read** behavior stays in `app/`. `[VERIFIED]` precedent: `packages/marvel/src/Http/Requests/ProductUpdateRequest.php:109` references `\App\Rules\UniqueProductBarcode`.

---

## 4. Canonical Product Availability Rules

### 4.1 Authoritative rule set

A product is storefront-eligible only when **all** of the following hold:

| # | Condition | Evidence |
|---|---|---|
| A1 | `products.status = 1` | `Product::scopeActiveStatus()` `packages/marvel/src/Database/Models/Product.php:573-576` |
| A2 | `in_stock = true` **OR** `(COALESCE(stock_quantity,0) - COALESCE(reserved_quantity,0)) > 0` | `Product::scopeActive()` `:580-582` |
| A3 | Product has no categories **OR** at least one category has `categories.status = 1` | `Product::scopeActive()` `:583-586`; `Category::scopeActive()` `packages/marvel/src/Database/Models/Category.php:66-67` |
| A4 | Product is not soft-deleted | `SoftDeletes` `Product.php:29` (global scope) |
| A5 | Channel rule at request time | `applyChannelHomeFilter()` `app/Traits/HasChannelFilter.php:9-20`; `FastShippingScope` `app/Models/Scopes/FastShippingScope.php:12-23` |

### 4.2 Channel semantics (verified)

`config/channel.php`:

- `default` = `home`
- `accepted` = `home`, `fast-shipping`
- `header` = `X-Channel`
- `strict` = false
- `enabled` = true (default)

`ChannelMiddleware` is part of the `api` middleware group (`app/Http/Kernel.php:42-51`). `ChannelContext` defaults to `Channel::HOME`.

| Channel | Additional filter | Evidence |
|---|---|---|
| `home` | `is_fast_shipping_available = false` | `app/Traits/HasChannelFilter.php:15-17` |
| `fast-shipping` | `is_fast_shipping_available = true` | `app/Models/Scopes/FastShippingScope.php:20-21` |
| channel disabled | No channel filter | `HasChannelFilter.php:11-13`; `FastShippingScope.php:14-16` |

`[VERIFIED]` — these two channel filters are mutually exclusive by design. A product is channel-eligible in exactly one channel when the feature is enabled.

### 4.3 Derived unavailability reasons (Admin)

Reasons are computed only from verified project data. No new availability definition is invented.

| Reason code | Trigger | Source |
|---|---|---|
| `deleted` | `deleted_at IS NOT NULL` on target product | SoftDeletes `Product.php:29` |
| `inactive` | `status !== 1` | Same as A1 |
| `out_of_stock` | `in_stock = false` AND `stock_quantity - reserved_quantity <= 0` | Same as A2 |
| `inactive_category` | Product has ≥1 non-deleted category AND none has `status = 1` | Same as A3 |
| (channel) `home: false` | `channel.enabled` AND `is_fast_shipping_available = true` | Same as `HasChannelFilter` |
| (channel) `fast_shipping: false` | `channel.enabled` AND `is_fast_shipping_available = false` | Same as `FastShippingScope` |

`[PROPOSED]` — `unavailability_reasons` contains product-level reasons only. Channel visibility is reported separately as `storefront_channels: {home: bool, fast_shipping: bool}` so merchandisers can see that a product is fully eligible but visible in one channel only.

### 4.4 Storefront enforcement

The storefront query applies the same rules by reusing:

- `->active()` (A1–A3),
- default SoftDeletes scope (A4),
- `applyChannelHomeFilter()` + `FastShippingScope` (A5).

No duplicated availability logic is introduced. `[VERIFIED]` — all four mechanisms exist and are used by the existing detail/list queries.

---

## 5. Database Design

### 5.1 Migration

**File:** `database/migrations/2026_11_08_000001_create_product_cross_sells_table.php`

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('product_cross_sells', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')
                ->constrained('products')
                ->cascadeOnDelete();
            $table->foreignId('related_product_id')
                ->constrained('products')
                ->cascadeOnDelete();
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();

            $table->unique(['product_id', 'related_product_id']);
            $table->index(['product_id', 'sort_order']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_cross_sells');
    }
};
```

### 5.2 Column rationale

| Column | Type | Why |
|---|---|---|
| `id` | bigint unsigned PK | Consistent with existing pivots (`category_product`, `product_tag`, `banner_product`) |
| `product_id` | FK → `products.id` | Source product |
| `related_product_id` | FK → `products.id` | Recommended product |
| `sort_order` | unsigned int, default 0 | Merchant order; unsigned int avoids implying a saved-count limit |
| `created_at`, `updated_at` | timestamps | Consistent with existing project pivots |

### 5.3 Constraints and indexes

| Constraint | Purpose |
|---|---|
| `unique(product_id, related_product_id)` | Prevents duplicate recommendations under concurrent writes or direct DB access |
| `index(product_id, sort_order)` | Supports ordered reads for one source |
| FK cascade on both columns | Hard-deleting a product removes outgoing and incoming rows |

### 5.4 Why a pivot table

| Option | Verdict | Reason |
|---|---|---|
| JSON column on `products` | Rejected | No FK integrity, no cascade, no unique constraint, weaker ordering/indexing |
| Separate table with JSON | Rejected | Same integrity issues; extra lifecycle |
| Directional pivot `product_cross_sells` | **Selected** | Matches existing project patterns, enforces integrity, supports ordering, reversible |

`[VERIFIED]` — existing pivots use `id`, FKs, timestamps, and unique constraints (`database/migrations/2026_06_23_000001_create_banner_product_table.php:11-20`).

### 5.5 Directionality

- Stores source → target only.
- Attaching `(A, B)` does not create `(B, A)`.
- Reverse recommendations must be created explicitly.
- Storefront and admin reads filter by `product_id = source`.

### 5.6 Ordering

- Sync writes `sort_order = 1..N` in submitted order.
- Reads order by `sort_order` ascending, then `product_cross_sells.id` ascending as a deterministic tie-breaker.
- Duplicate `sort_order` values cannot occur through the API; the tie-breaker covers direct DB manipulation.

### 5.7 Deletion semantics

| Event | Behavior |
|---|---|
| Source product soft-deleted | Pivot rows remain; admin relation for that product is inaccessible through normal product show; storefront never queries it |
| Target product soft-deleted | Pivot row remains; storefront excludes it; admin shows it with `deleted` reason and allows keeping it |
| Target hard-deleted | FK cascade removes the pivot row |
| Link removed in admin | Pivot row deleted; products untouched |
| Target deactivated / out of stock | Pivot row remains; storefront excludes; admin warns |

`[INFERRED]` — from `SoftDeletes` and FK cascade definitions.

### 5.8 Data-integrity rules

| Rule | Enforced by |
|---|---|
| No duplicate target per source | DB unique + request `distinct` + repository dedupe |
| No self-reference | Custom rule + repository guard |
| Target must exist | Custom rule (`withTrashed()->whereIn`) |
| Soft-deleted target cannot be newly added | Custom rule compares against currently attached IDs |
| Already-attached soft-deleted target can be preserved | Custom rule permits IDs present in the current pivot |
| Ordered set | Repository assigns `sort_order`; relation orders by pivot |
| No orphan rows | FK cascade |

### 5.9 Migration classification

| Attribute | Value |
|---|---|
| Type | Additive schema creation |
| Data-affecting | No |
| Backfill | None |
| Destructive | No in `up()` |
| Reversible | Yes via `down()` (drops table and its data) |
| Production risk | Low (new table only) |
| Lock risk | Low on MySQL for a new table |
| Rollback caveat | Rolling back after merchandisers configure data **destroys that data**; export first |

`[VERIFIED]` — `php artisan db:table product_cross_sells` returned “Table ... doesn't exist”. `php artisan db:show --json` confirmed database `catch` on MySQL 8 and no existing cross-sell table.

### 5.10 Test-schema synchronization

`tests/Concerns/CreatesTestTables.php` early-returns when `users` exists (`:18-20`). The pivot must exist on both fresh and existing test databases.

Add one guarded helper:

```php
private function createProductCrossSellTableIfMissing(): void
{
    if (!Schema::hasTable('products') || Schema::hasTable('product_cross_sells')) {
        return;
    }

    Schema::create('product_cross_sells', function (Blueprint $table) {
        $table->id();
        $table->foreignId('product_id')->constrained('products')->cascadeOnDelete();
        $table->foreignId('related_product_id')->constrained('products')->cascadeOnDelete();
        $table->unsignedInteger('sort_order')->default(0);
        $table->timestamps();
        $table->unique(['product_id', 'related_product_id']);
        $table->index(['product_id', 'sort_order']);
    });
}
```

Call sites:

1. Before the early return at `:18-20` (handles existing test databases).
2. After `product_barcodes` creation at `:156` (handles fresh test databases; `products` is created at `:113`).

`[RISK]` if omitted: `Base table or view not found: product_cross_sells` on existing test databases, or FK failure if created before `products`.

---

## 6. Backend Implementation Plan

### 6.1 Model relations

**File:** `packages/marvel/src/Database/Models/Product.php`
**Insert after:** `tags()` (`:354-357`), before `orders()` (`:364`).

```php
/**
 * Manual, ordered cross-sell recommendations for this product.
 * Directional: A -> B does not imply B -> A.
 */
public function crossSellProducts(): BelongsToMany
{
    return $this->belongsToMany(
        self::class,
        'product_cross_sells',
        'product_id',
        'related_product_id'
    )
        ->withPivot('sort_order')
        ->withTimestamps()
        ->orderByPivot('sort_order')
        ->orderBy('product_cross_sells.id');
}

/**
 * Admin read relation: includes soft-deleted targets and ignores the
 * fast-shipping global scope so merchandisers always see saved rows.
 */
public function crossSellProductsAdmin(): BelongsToMany
{
    return $this->crossSellProducts()
        ->withTrashed()
        ->withoutGlobalScope(FastShippingScope::class);
}
```

Notes:

- `BelongsToMany` is already imported (`Product.php:10`).
- `FastShippingScope` is already imported (`Product.php:5`).
- `orderByPivot()` exists in Laravel 10.
- `crossSellProducts()` intentionally keeps SoftDeletes and FastShippingScope so storefront safety is the default.
- `crossSellProductsAdmin()` is read-only and used only by the admin resource/controller.

### 6.2 Validation rule

**New file:** `app/Rules/ValidCrossSellIds.php`

Responsibilities:

1. Reject the current product ID (self-reference).
2. Reject duplicate IDs (also covered by `distinct`).
3. Reject IDs that do not exist (including trashed check via one query).
4. Reject newly added soft-deleted IDs.
5. Permit currently attached soft-deleted IDs so editing other fields never silently drops saved rows.

Implementation shape:

```php
final class ValidCrossSellIds implements Rule
{
    private ?string $failure = null;

    public function __construct(
        private readonly ?int $sourceProductId = null,
        private readonly array $existingIds = [],
    ) {}

    public function passes($attribute, $value): bool
    {
        if (!is_array($value)) {
            return true; // the 'array' rule reports this
        }

        $ids = array_map('intval', $value);

        if ($this->sourceProductId !== null && in_array($this->sourceProductId, $ids, true)) {
            $this->failure = 'self';
            return false;
        }

        $products = Product::withTrashed()
            ->whereIn('id', array_values(array_unique($ids)))
            ->get()
            ->keyBy('id');

        foreach ($ids as $id) {
            $product = $products->get($id);
            if ($product === null) {
                $this->failure = 'missing';
                return false;
            }
            if ($product->trashed() && !in_array($id, $this->existingIds, true)) {
                $this->failure = 'deleted';
                return false;
            }
        }

        return true;
    }

    public function message(): string
    {
        return match ($this->failure) {
            'self' => __('validation.cross_sell_self'),
            'missing' => __('validation.cross_sell_missing'),
            'deleted' => __('validation.cross_sell_deleted'),
            default => __('validation.cross_sell_invalid'),
        };
    }
}
```

`[VERIFIED]` precedent: `app/Rules/UniqueProductBarcode.php` implements `Rule`, uses a captured failure string, and is referenced from a Marvel FormRequest.

### 6.3 Request validation

#### 6.3.1 `ProductCreateRequest`

- Import `App\Rules\ValidCrossSellIds`.
- Add after the `tags` rules (`packages/marvel/src/Http/Requests/ProductCreateRequest.php:96-97`), before `tax_enabled` (`:102`):

```php
'cross_sell_ids'   => ['sometimes', 'array'],
'cross_sell_ids.*' => ['integer', 'distinct', new ValidCrossSellIds()],
```

Create has no existing IDs; soft-deleted IDs are therefore rejected.

#### 6.3.2 `ProductUpdateRequest`

- Import `App\Rules\ValidCrossSellIds`.
- Compute the current product ID and existing pivot IDs once in `rules()`:

```php
$currentProductId = $this->route('product') !== null && is_numeric($this->route('product'))
    ? (int) $this->route('product')
    : null;

$existingCrossSellIds = $currentProductId === null
    ? []
    : Product::withTrashed()
        ->find($currentProductId)?->crossSellProducts()
        ->withTrashed()
        ->pluck('products.id')
        ->map(fn ($id) => (int) $id)
        ->all() ?? [];
```

- Add after the `tags` rules (`ProductUpdateRequest.php:95-96`), before `tax_enabled` (`:101`):

```php
'cross_sell_ids'   => ['sometimes', 'array'],
'cross_sell_ids.*' => ['integer', 'distinct', new ValidCrossSellIds($currentProductId, $existingCrossSellIds)],
```

Notes:

- No `max` rule: saved relationships are unlimited.
- No `exists:products,id` rule: plain `exists` ignores soft deletes and would accept trashed rows without the “already attached” exception.
- `cross_sell_ids` is **not** added to `ProductRepository::WRITABLE_FIELDS` (`ProductRepository.php:53-81`).
- Validation happens before the transaction; the repository guard remains authoritative if validation is bypassed.

### 6.4 Repository ordered sync

**File:** `packages/marvel/src/Database/Repositories/ProductRepository.php`

Call site in `syncRelation()` after the `tags` block (`:394-396`), before the flash-sale block (`:398`):

```php
if ($request->has('cross_sell_ids')) {
    $this->syncCrossSellRelation($product, $request->input('cross_sell_ids'));
}
```

Method after `syncRelationAudited()` (after `:436`):

```php
/**
 * Sync ordered, directional cross-sell rows.
 *
 * The existing syncRelationAudited() sorts IDs and would destroy merchant
 * order, so this relation is synced separately.
 */
private function syncCrossSellRelation(Product $product, $ids): void
{
    $newIds = collect(is_array($ids) ? $ids : [])
        ->map(fn ($id) => (int) $id)
        ->unique()
        ->values();

    if ($newIds->contains((int) $product->id)) {
        throw new HttpException(422, __('validation.cross_sell_self'));
    }

    $oldIds = $product->crossSellProducts()
        ->withTrashed()
        ->get()
        ->pluck('id')
        ->map(fn ($id) => (int) $id)
        ->values();

    if ($oldIds->all() === $newIds->all()) {
        return;
    }

    $sync = [];
    foreach ($newIds as $index => $id) {
        $sync[$id] = ['sort_order' => $index + 1];
    }

    $product->crossSellProducts()->sync($sync);

    ActivityAuditService::recordModel(
        $product,
        'product_cross_sells_synced',
        'products',
        __('activity.product_cross_sells_synced') ?: 'product_cross_sells_synced',
        old: ['ids' => $oldIds->all()],
        new: ['ids' => $newIds->all()],
    );
}
```

Notes:

- Runs inside the existing store/update transaction (`:114-158`, `:174-304`), so a failure rolls back both product and pivot changes.
- `has()` (not `filled`) lets `[]` clear the list; an absent key preserves it.
- `sync()` detaches removed IDs, inserts new IDs, and updates `sort_order` for existing IDs.
- No-op check compares ordered ID lists; reordering is persisted and audited.
- `HttpException` is already imported (`:30`).
- `ActivityAuditService` is already imported (`:6`).

### 6.5 Admin response

**File:** `packages/marvel/src/Http/Resources/product/ProductResource.php`
**Insert after:** `related_products` at `:67`.

```php
$this->mergeWhen($this->relationLoaded('crossSellProductsAdmin'), function () {
    $items = $this->crossSellProductsAdmin
        ->map(fn ($product) => $this->crossSellAdminItem($product))
        ->values();

    return [
        'cross_sell_ids'              => $items->pluck('id')->all(),
        'cross_sell_products'         => $items->all(),
        'saved_cross_sell_count'      => $items->count(),
        'eligible_cross_sell_count'   => $items->where('available', true)->count(),
        'storefront_cross_sell_limit' => \App\Services\General\ProductService::STOREFRONT_DISPLAY_LIMIT,
    ];
}),
```

Helper in the same class:

```php
private function crossSellAdminItem($product): array
{
    $reasons = [];

    if ($product->trashed()) {
        $reasons[] = 'deleted';
    }
    if ((int) $product->status !== 1) {
        $reasons[] = 'inactive';
    }

    $availableQty = (int) ($product->stock_quantity ?? 0) - (int) ($product->reserved_quantity ?? 0);
    if (!$product->in_stock && $availableQty <= 0) {
        $reasons[] = 'out_of_stock';
    }

    $categories = $product->relationLoaded('categories') ? $product->categories : collect();
    if ($categories->isNotEmpty() && !$categories->contains(fn ($category) => (int) $category->status === 1)) {
        $reasons[] = 'inactive_category';
    }

    $productLevelAvailable = $reasons === [];
    $channelEnabled = (bool) config('channel.enabled', true);
    $fastShipping = (bool) $product->is_fast_shipping_available;

    return [
        'id'                     => (int) $product->id,
        'name'                   => $product->getTranslation('name', app()->getLocale()),
        'slug'                   => $product->slug,
        'price'                  => $this->roundMoney($product->price),
        'thumbnail'              => $product->getFirstMediaUrl('products'),
        'status'                 => (bool) $product->status,
        'in_stock'               => (bool) $product->in_stock,
        'deleted'                => $product->trashed(),
        'available'              => $productLevelAvailable,
        'unavailability_reasons' => $reasons,
        'storefront_channels'    => [
            'home'          => $productLevelAvailable && (!$channelEnabled || !$fastShipping),
            'fast_shipping' => $productLevelAvailable && (!$channelEnabled || $fastShipping),
        ],
    ];
}
```

Notes:

- `roundMoney()` already exists as a private method in this resource (`packages/marvel/src/Http/Resources/product/ProductResource.php:163`) and is used by existing price keys.
- `cross_sell_ids` includes soft-deleted targets because the admin relation uses `withTrashed()`.
- `eligible_cross_sell_count` counts product-level eligibility (ignores channel); channel visibility is reported per item. This definition must be approved (see §16 Q-01).
- If categories are not eager-loaded, the category rule is skipped; the controller must eager-load them (see §6.6).

### 6.6 Controller eager loads

**File:** `packages/marvel/src/Http/Controllers/ProductController.php`

Add to `store()` (`:251-256`), `show()`/`fetchSingleProduct()` (`:345-351`), and `update()` (`:369-374`):

```php
'crossSellProductsAdmin.media',
'crossSellProductsAdmin.categories',
```

Reason: `cross_sell_products` needs thumbnails and category status without N+1 queries.

### 6.7 Storefront service

**File:** `app/Services/General/ProductService.php`

Add a public constant:

```php
public const STOREFRONT_DISPLAY_LIMIT = 8;
```

Call site after the existing `related_products` assignment (`:267`), before the final pricing return (`:269`):

```php
$product->setRelation(
    'cross_sell_products',
    $this->enrichCollectionWithPricing($this->fetchCrossSellProducts($product))
);
```

New method near `fetchRelated()` (`:645-666`):

```php
/**
 * Manual cross-sell recommendations for the storefront detail page.
 *
 * Directional, ordered, eligibility-filtered, and capped at
 * STOREFRONT_DISPLAY_LIMIT eligible products.
 */
private function fetchCrossSellProducts(Product $product): Collection
{
    $query = $product->crossSellProducts()
        ->active()
        ->with($this->productRelations())
        ->withAvg(['reviews' => fn (Builder $builder) => $builder->approved()], 'rating')
        ->withCount(['reviews' => fn (Builder $builder) => $builder->approved()])
        ->where('products.id', '!=', $product->id)
        ->limit(self::STOREFRONT_DISPLAY_LIMIT);

    $this->applyChannelHomeFilter($query);

    return $query->get();
}
```

Notes:

- `active()` reuses the canonical availability scope; SoftDeletes and FastShippingScope apply automatically.
- `productRelations()` (`:71-85`) eager-loads categories, variations, brands, media, flash sales, tags, and barcodes.
- `withAvg` / `withCount` populate `ratings` / `ratings_count` consumed by `ProductMiniResource`.
- Pricing stays centralized in `enrichCollectionWithPricing()` / `ProductPricingService`.
- The detail endpoint's `limit` query parameter still controls only `related_products`.

### 6.8 Storefront resource

**File:** `app/Http/Resources/Product/ProductResource.php`
**Insert after:** line `72`.

```php
$this->mergeWhen($this->relationLoaded('cross_sell_products'), fn () => [
    'cross_sell_products' => ProductMiniResource::collection($this->cross_sell_products),
]),
```

Notes:

- `ProductMiniResource` is already imported (`:10`).
- The relationLoaded guard keeps list endpoints unchanged.
- `related_products` remains exactly as-is.

### 6.9 Activity translations

Add to `resources/lang/en/activity.php` after `product_tags_synced` (`:103`) and the Arabic file at the same position:

```php
// en
'product_cross_sells_synced' => 'Product cross-sells synchronized',

// ar
'product_cross_sells_synced' => 'تمت مزامنة المنتجات المقترحة للمنتج',
```

### 6.10 Validation translations

Add to `resources/lang/en/validation.php` and `resources/lang/ar/validation.php`:

```php
// en
'cross_sell_self'    => 'A product cannot recommend itself.',
'cross_sell_missing' => 'The selected product does not exist.',
'cross_sell_deleted' => 'A deleted product cannot be newly added as a recommendation.',
'cross_sell_invalid' => 'The selected recommendation is invalid.',

// ar
'cross_sell_self'    => 'لا يمكن للمنتج أن يوصي بنفسه.',
'cross_sell_missing' => 'المنتج المحدد غير موجود.',
'cross_sell_deleted' => 'لا يمكن إضافة منتج محذوف كتوصية جديدة.',
'cross_sell_invalid' => 'التوصية المحددة غير صالحة.',
```

`[PROPOSED]` — the existing `UniqueProductBarcode` rule hardcodes English strings; this plan prefers translation keys to satisfy project localization rules. If the team prefers parity with the existing hardcoded rule, drop the validation translation files and hardcode the same messages.

### 6.11 Cache behavior

- No new cache tag.
- Storefront detail is uncached; changes appear on the next request.
- Admin store/update already flush `FrontendResource::PRODUCTS` (`ProductController.php:257`, `:375`).
- The admin list cache does not include cross-sell keys because the relation is not loaded there and the resource uses `relationLoaded`.

### 6.12 Error handling

| Case | Expected behavior |
|---|---|
| Invalid `cross_sell_ids` type | `422`, `errors.cross_sell_ids` |
| Self-reference | `422`, `errors.cross_sell_ids` (custom rule); repository guard also returns `422` |
| Duplicate IDs | `422`, `errors["cross_sell_ids.N"]` |
| Non-existent ID | `422` |
| Newly added soft-deleted ID | `422` |
| Preserved already-attached soft-deleted ID | Accepted |
| Product not found on update | Existing `404` behavior |
| Pivot sync failure | Entire product transaction rolls back |
| No saved rows | Admin returns zero counts and empty arrays |

### 6.13 Performance notes

Storefront detail adds:

- 1 pivot join query for up to 8 eligible products.
- Eager loads from `productRelations()`.
- Review aggregate subqueries.
- Pricing enrichment for at most 8 products.

Admin show/update/store adds:

- 1 relation query (including trashed targets).
- Media and category eager loads.
- No per-row queries for reasons (columns + loaded categories only).

`[RISK]` — pricing enrichment can issue per-product queries if relations are missing; `productRelations()` mirrors the existing `related_products` path. Verify with a query-count test.

---

## 7. Admin Dashboard Implementation Plan

### 7.1 Scope constraint

`[VERIFIED-NEGATIVE]` — the Admin Dashboard source is not in this repository. This section defines:

- the backend contract implemented here; and
- the UI behavior the external dashboard must implement.

No dashboard file path may be claimed or created in this repository.

### 7.2 Placement

Add a **Cross-selling** section to the existing product create/edit screen.

Do not add:

- a new page,
- a new navigation item,
- a separate cross-sell CRUD screen,
- a new permission.

### 7.3 Required capabilities

| # | Capability | Backend support |
|---|---|---|
| 1 | Search products | `GET /api/v1/products?search={term}&limit=20` |
| 2 | Select multiple products | Payload `cross_sell_ids` |
| 3 | Add recommendations | Same |
| 4 | Remove recommendations | Same (`cross_sell_ids` without the removed ID) |
| 5 | Reorder recommendations | Submit the array in the new order |
| 6 | Display saved recommendations | `GET /api/v1/products/{id}` |
| 7 | Availability status per saved item | `cross_sell_products[].available`, `unavailability_reasons`, `storefront_channels` |
| 8 | Warning for unavailable items | Same fields |
| 9 | Add alternatives while unavailable rows remain | Submit all saved IDs plus new IDs |
| 10 | Persist order | `sort_order` |
| — | No saved maximum | No `max` validation |
| — | Prevent self | Custom rule + picker excludes current product |
| — | Prevent duplicates | `distinct` + picker excludes selected |

### 7.4 Data flow

```text
Open product form
  -> GET /api/v1/products/{id}
  -> data.cross_sell_ids (ordered, includes saved unavailable targets)
  -> data.cross_sell_products (labels, thumbnails, availability, reasons, channels)
  -> data.saved_cross_sell_count
  -> data.eligible_cross_sell_count
  -> data.storefront_cross_sell_limit (8)

Search
  -> GET /api/v1/products?search={term}&limit=20
  -> exclude current product and already selected IDs

Save
  -> POST /api/v1/products or PUT/PATCH /api/v1/products/{id}
  -> include cross_sell_ids in displayed order

Clear
  -> submit cross_sell_ids: []
```

### 7.5 UI behavior specification

| Behavior | Requirement |
|---|---|
| Search | Use the existing admin product list endpoint; debounce input |
| Option display | Name, thumbnail, price, `status`, `in_stock` |
| Exclude self | Picker must not offer the product being edited |
| Duplicates | Picker must not offer already-selected products |
| Reordering | Move up/down buttons at minimum; drag optional |
| Prefill | Use `cross_sell_ids` and `cross_sell_products` from show |
| Unavailable items | Keep visible with reason badges; never auto-remove |
| Counts | Show “Saved N · Eligible M · Storefront shows up to 8” using response fields |
| Channel visibility | Show per-item `storefront_channels` when both differ |
| Save | Send the full ordered `cross_sell_ids` array |
| Empty state | “No cross-sell products selected.” |
| Validation errors | Map `errors.cross_sell_ids` and `errors["cross_sell_ids.N"]` inline |
| Permissions | Editable with `create-product` / `update-product`; read-only with `view-products` only |
| No Add to Cart | Admin and storefront cross-sell are informational only |

### 7.6 Accessibility

- Keyboard-operable reorder controls; not drag-only.
- Do not rely on color alone for unavailable badges.
- Announce count changes and validation errors.
- Use existing dashboard locale conventions; backend `lang` middleware handles translation.

### 7.7 Admin acceptance criteria

- Add/remove/reorder persist after save and reload.
- Order in response equals submitted order.
- Unavailable rows remain after save.
- Alternatives can be added while unavailable rows remain.
- Counts are accurate.
- Self/duplicate/invalid IDs are rejected with inline errors.
- A view-only user cannot mutate.
- No new route or permission is required.

### 7.8 Dashboard unknowns

| Unknown | Impact | Blocking? |
|---|---|---|
| Dashboard repository and component paths | Cannot produce file-level UI changes | Yes for UI implementation; no for backend |
| Product form layout (single page or wizard) | Placement | No |
| Drag-and-drop support | Interaction choice | No |
| Dashboard caching of product show | Stale counts | No; backend authoritative |
| Release owner | Sequencing | No |

---

## 8. Storefront Implementation Plan

### 8.1 Data flow

```text
GET /api/v1/general/products/{slug}
  -> existing active-product query (unchanged)
  -> existing related_products (unchanged)
  -> new cross_sell_products:
       product.crossSellProducts()
         .active()
         .with(productRelations)
         .withAvg/withCount(reviews)
         .where(products.id != source.id)
         .orderByPivot(sort_order), orderBy(pivot.id)
         .limit(8)
         .applyChannelHomeFilter()
       enrich pricing
  -> ProductMiniResource collection
```

### 8.2 Behavior rules

| # | Rule |
|---|---|
| S1 | Return only products passing canonical eligibility |
| S2 | Preserve saved order |
| S3 | Return at most 8 eligible products |
| S4 | Exclude soft-deleted, inactive, out-of-stock, category-inactive, and channel-excluded products |
| S5 | Return `cross_sell_products: []` when none eligible |
| S6 | Exclude the source product defensively |
| S7 | Reuse `ProductMiniResource`; no admin-only fields |
| S8 | No Cart/Checkout/pricing/inventory side effects |
| S9 | Do not modify `related_products` |

### 8.3 Frontend rendering contract

- Render the section only when `cross_sell_products` is a non-empty array.
- Render cards in array order.
- Do not add Add-to-Cart buttons as part of this feature.
- Treat the key as optional-additive for backward compatibility.

`[UNKNOWN]` — the storefront frontend source is also not in this repository; only the API contract is delivered here.

---

## 9. API Contract

### 9.1 Storefront detail — request

```http
GET /api/v1/general/products/iphone-15
Accept: application/json
X-Channel: home
```

No new query parameter. The existing `limit` parameter still affects only `related_products`.

### 9.2 Storefront detail — success (abridged)

```json
{
  "status": 200,
  "message": "Data fetched successfully",
  "success": true,
  "data": {
    "id": 12,
    "name": "iPhone 15",
    "slug": "iphone-15",
    "related_products": [],
    "cross_sell_products": [
      {
        "id": 31,
        "name": "MagSafe Charger",
        "slug": "magsafe-charger",
        "price": 149,
        "has_variants": false,
        "item_type": "PHYSICAL",
        "piece_barcode": null,
        "carton_barcode": null,
        "pieces_per_carton": null,
        "current_price": 149,
        "currency": "SAR",
        "quantity": 40,
        "in_stock": true,
        "discount_active": false,
        "flash_sale_active": false,
        "is_fast_shipping_available": false,
        "ratings": 4.7,
        "ratings_count": 12,
        "brands": [],
        "tags": [],
        "image": {
          "thumbnail": "https://cdn.example.com/products/magsafe.jpg",
          "original": []
        }
      }
    ]
  }
}
```

Existing keys are preserved. `cross_sell_products` uses the exact `ProductMiniResource` shape (`app/Http/Resources/Product/ProductMiniResource.php:19-53`).

### 9.3 Storefront detail — no eligible recommendations

```json
{
  "status": 200,
  "message": "Data fetched successfully",
  "success": true,
  "data": {
    "id": 12,
    "slug": "iphone-15",
    "related_products": [],
    "cross_sell_products": []
  }
}
```

### 9.4 Admin create/update — request

```http
PUT /api/v1/products/12
Authorization: Bearer {token}
Content-Type: application/json
Accept: application/json
```

```json
{
  "cross_sell_ids": [31, 44, 57]
}
```

Semantics:

| Input | Behavior |
|---|---|
| Key absent | Preserve existing links on update; create none on create |
| `[]` | Clear all links |
| Ordered list | Replace the full set in this order |
| Duplicate IDs | `422` |
| Self ID | `422` |
| Missing ID | `422` |
| Newly added soft-deleted ID | `422` |
| Already-attached soft-deleted ID | Accepted and preserved |

A minimal update payload is valid: `ProductUpdateRequest` uses `sometimes` for product fields and price is only required when `product_type=SIMPLE` is present (`ProductUpdateRequest.php:70`). `[VERIFIED]`

### 9.5 Admin show/store/update — response additions

```json
{
  "cross_sell_ids": [31, 44, 57],
  "saved_cross_sell_count": 3,
  "eligible_cross_sell_count": 2,
  "storefront_cross_sell_limit": 8,
  "cross_sell_products": [
    {
      "id": 31,
      "name": "MagSafe Charger",
      "slug": "magsafe-charger",
      "price": 149,
      "thumbnail": "https://cdn.example.com/products/magsafe.jpg",
      "status": true,
      "in_stock": true,
      "deleted": false,
      "available": true,
      "unavailability_reasons": [],
      "storefront_channels": {
        "home": true,
        "fast_shipping": false
      }
    },
    {
      "id": 44,
      "name": "Discontinued Case",
      "slug": "discontinued-case",
      "price": 39,
      "thumbnail": "https://cdn.example.com/products/case.jpg",
      "status": false,
      "in_stock": false,
      "deleted": false,
      "available": false,
      "unavailability_reasons": ["inactive", "out_of_stock"],
      "storefront_channels": {
        "home": false,
        "fast_shipping": false
      }
    }
  ]
}
```

- These keys appear only when `crossSellProductsAdmin` is loaded (show/store/update).
- Admin list responses remain unchanged.
- `cross_sell_ids` includes unavailable saved targets.
- Counts and limit let the dashboard avoid hardcoding 8.

### 9.6 Admin validation error

```json
{
  "status": false,
  "message": "Invalid data",
  "success": false,
  "errors": {
    "cross_sell_ids.0": [
      "A product cannot recommend itself."
    ]
  }
}
```

Envelope shape is produced by the existing `ValidationException` handler (`app/Exceptions/Handler.php:177-186`). `[VERIFIED]`

### 9.7 Compatibility classification

| Change | Classification | Notes |
|---|---|---|
| Storefront detail gains `cross_sell_products` | **NON-BREAKING** | Additive key |
| Admin create/update accept `cross_sell_ids` | **NON-BREAKING** | Optional |
| Admin show/store/update gain cross-sell keys | **NON-BREAKING** | Additive |
| `related_products` | **UNCHANGED** | Category-based behavior preserved |
| Database | **ADDITIVE / SAFE** | New table only |
| Permissions/routes | **UNCHANGED** | Existing product surfaces |

---

## 10. Authorization and Validation

### 10.1 Authorization matrix

| Operation | Permission | Evidence |
|---|---|---|
| Storefront detail | Public | `routes/api.php:52` |
| Admin product list/search | `view-products` | `ProductController.php:109` |
| Admin product show | `view-products` | `ProductController.php:109` |
| Admin product create | `create-product` | `ProductController.php:110` |
| Admin product update | `update-product` | `ProductController.php:111` |
| Admin product delete | `delete-product` | `ProductController.php:112` |

No new permission. `[VERIFIED]` — `Permission::VIEW_PRODUCTS = 'view-products'` etc. (`packages/marvel/src/Enums/Permission.php:36-37,145-148`).

### 10.2 Validation rules

| Field | Rules |
|---|---|
| `cross_sell_ids` | `sometimes`, `array` |
| `cross_sell_ids.*` | `integer`, `distinct`, `ValidCrossSellIds` |

No `max`. No `exists` rule (replaced by the custom rule to handle trashed targets correctly).

### 10.3 Security controls

| Concern | Control |
|---|---|
| Mass assignment | `cross_sell_ids` excluded from `WRITABLE_FIELDS`; explicit sync only |
| Self-reference | Custom rule + repository guard |
| Duplicate rows | `distinct` + repository dedupe + DB unique |
| Invalid references | Custom rule with one `whereIn` query |
| Soft-deleted injection | Custom rule allows only already-attached trashed IDs |
| Unauthorized mutation | Existing product permission middleware |
| Storefront data exposure | `ProductMiniResource` only; no admin fields |
| Hidden products | Canonical `active()` + SoftDeletes + channel filters |
| IDOR | Admin routes permission-protected; storefront exposes active products only |
| Transaction rollback | Sync inside existing product transaction |
| Audit | Ordered old/new IDs recorded via `ActivityAuditService` |
| Secrets/PII | No new secrets; cross-sell payload contains product IDs and public product fields only |

### 10.4 Threats considered

| Threat | Assessment | Mitigation |
|---|---|---|
| User without product permission edits cross-sells | Prevented by middleware | No change |
| Huge `cross_sell_ids` payload | PHP request limits + single `whereIn` query; no `max` by decision | Monitor request size; no artificial saved cap |
| Duplicate IDs cause DB error | `distinct` + dedupe + unique | 422 fail-closed |
| Self-reference causes recursion/UI issues | Custom rule + repository guard | 422 |
| Soft-deleted target injected | Custom rule | 422 unless already attached |
| Cross-channel leakage | Channel filters reused | Same as existing product lists |
| XSS in product names | Existing rendering/escaping | No raw HTML added |
| CSRF | Bearer-token API | Existing architecture |

### 10.5 Logging safety

- Audit stores IDs and product module only.
- Do not log request payloads or authorization headers.
- Do not expose SQL errors; existing handler converts them.

---

## 11. Test Plan

### 11.1 Test infrastructure

| Fact | Evidence |
|---|---|
| PHPUnit 10 | `phpunit.xml:1-2` |
| MySQL test database `catch` | `phpunit.xml:21-26` |
| Transactions per test | `DatabaseTransactions` used by `ProductsEndpointTest.php:26`, `ProductCrudTest.php:26` |
| Manual schema bootstrap | `CreatesTestTables` (`tests/Concerns/CreatesTestTables.php:10-20`) |
| Product schema | `tests/Concerns/CreatesTestTables.php:113` |
| Barcode schema | `tests/Concerns/CreatesTestTables.php:156` |

### 11.2 New test files

1. `tests/Feature/General/ProductCrossSellEndpointTest.php`
2. `tests/Feature/ProductCrossSellAdminTest.php`

### 11.3 Storefront matrix

| # | Test | Assertion |
|---|---|---|
| SF-01 | Ordered recommendations | Response IDs equal saved order |
| SF-02 | No recommendations | `cross_sell_products` is `[]` |
| SF-03 | All unavailable | `[]`; no error |
| SF-04 | One available | Exactly one item |
| SF-05 | Inactive target excluded | `status=0` absent |
| SF-06 | Out-of-stock target excluded | `in_stock=false`, `stock_quantity=0`, no reserved stock |
| SF-07 | Soft-deleted target excluded | Absent from response; pivot row still in DB |
| SF-08 | Category-inactive target excluded | Target whose categories are all `status=0` absent |
| SF-09 | More than 8 eligible | Exactly 8 returned, first 8 in order |
| SF-10 | Fewer than 8 eligible | All eligible returned |
| SF-11 | Unavailable excluded before limit | 10 saved, 3 unavailable → first 7 eligible returned |
| SF-12 | Source product excluded | Corrupt self-row does not appear |
| SF-13 | Re-eligible target reappears | Reactivate target → appears in order |
| SF-14 | `related_products` unchanged | Existing category-based relation still correct |
| SF-15 | Home channel filter | `X-Channel: home` excludes fast-shipping-available targets |
| SF-16 | Fast-shipping channel filter | `X-Channel: fast-shipping` includes only fast-shipping-available targets |
| SF-17 | Mini resource shape | No admin-only fields |
| SF-18 | Pricing parity | `current_price`, `discount_active`, `flash_sale_active` consistent |
| SF-19 | 404 unchanged | Unknown slug returns 404 envelope |
| SF-20 | No cart/checkout side effects | Existing cart/checkout tests unaffected; no writes outside pivot |

### 11.4 Admin matrix

| # | Test | Assertion |
|---|---|---|
| AD-01 | Create with `cross_sell_ids` | Ordered pivot rows + response keys |
| AD-02 | Create without key | No pivot rows |
| AD-03 | Update replaces set | Removed rows detached; new rows attached |
| AD-04 | Update reorders | `sort_order` updated; response order matches |
| AD-05 | Update omits key | Existing rows preserved |
| AD-06 | Update with `[]` | All rows detached |
| AD-07 | More than 8 saved | Accepted (no max) |
| AD-08 | Duplicate IDs | 422 |
| AD-09 | Non-existent ID | 422 |
| AD-10 | Newly added soft-deleted ID | 422 |
| AD-11 | Preserved soft-deleted ID | Accepted; row remains; appears with `deleted` reason |
| AD-12 | Self-reference on update | 422 |
| AD-13 | Self-reference bypassing validation | Repository throws 422; transaction rolls back |
| AD-14 | Guest create/update | 401 |
| AD-15 | View-only user | Show 200; update 403 |
| AD-16 | Show response shape | IDs, compact items, counts, limit |
| AD-17 | Unavailable items visible | Inactive/out-of-stock/deleted items present with reasons |
| AD-18 | Channel map | `home` / `fast_shipping` booleans match canonical rules |
| AD-19 | Audit on change | Ordered old/new IDs recorded |
| AD-20 | Audit on no-op | No new audit row |
| AD-21 | Audit on reorder | Recorded because ordered list changed |
| AD-22 | Transaction rollback | Later failure rolls back product + pivot |
| AD-23 | DB unique constraint | Direct duplicate insert fails |
| AD-24 | FK cascade | Hard delete removes source/target rows |
| AD-25 | No N+1 | Bounded queries for compact list (media/categories eager loaded) |
| AD-26 | Backward compatibility | Existing product response keys unchanged |

### 11.5 Regression suites

- `tests/Feature/General/ProductsEndpointTest.php` — list/detail, related products, pricing, channels.
- `tests/Feature/ProductCrudTest.php` — auth matrix and product validation.
- `tests/Feature/ProductAdminTest.php`, `ProductCacheTest.php`, `ProductTagTest.php`, `ProductFilterTest.php`.
- `tests/Feature/ChannelContextTest.php` — channel semantics.
- `tests/Feature/Wms/ProductShowLocationsTest.php`, `ProductStoreUpdateOccupancyTest.php` — relation-loading compatibility.
- `tests/Feature/PricingProductionHardenTest.php`, `ProductionRootCauseRegressionTest.php` — pricing/production guards.
- `tests/Feature/ImportExport/ProductImportInvariantTest.php`, `ProductImportLifecycleTest.php` — import/export unaffected.

### 11.6 Test implementation notes

- Attach rows with `$product->crossSellProducts()->attach($target->id, ['sort_order' => 1]);`.
- Create products directly with `Product::create([...])` in test helpers (`ProductsEndpointTest.php:52-58` pattern).
- Use full detail URL `self::LIST_URL . '/' . $product->slug` (`ProductsEndpointTest.php:205`).
- For channel tests, send `X-Channel` headers; `fast-shipping` is the accepted value.
- For admin create, use multipart `post()` with `UploadedFile::fake()->image()` because files and JSON are not combined (`ProductCrudTest.php:778-795` pattern); use `putJson()` for update-focused tests.
- Reuse permission setup from `ProductCrudTest.php:78-83`.
- Assert exact status codes for new tests.

### 11.7 Commands (implementation phase)

```powershell
php -l app/Rules/ValidCrossSellIds.php
php -l database/migrations/2026_11_08_000001_create_product_cross_sells_table.php
php -l packages/marvel/src/Database/Models/Product.php
php -l packages/marvel/src/Database/Repositories/ProductRepository.php
php -l packages/marvel/src/Http/Requests/ProductCreateRequest.php
php -l packages/marvel/src/Http/Requests/ProductUpdateRequest.php
php -l packages/marvel/src/Http/Resources/product/ProductResource.php
php -l packages/marvel/src/Http/Controllers/ProductController.php
php -l app/Services/General/ProductService.php
php -l app/Http/Resources/Product/ProductResource.php

php artisan migrate
php artisan test --filter=ProductCrossSell
php artisan test --filter=ProductsEndpointTest
php artisan test --filter=ProductCrudTest
php artisan test --filter=ProductAdminTest
php artisan test --filter=ProductCacheTest
php artisan test --filter=ChannelContextTest
php artisan route:list --path=api/v1/products
php artisan config:clear
```

`[VERIFIED]` — no test suite, migration, or PHP file was executed/modified during Phase One except read-only commands (`php artisan --version`, `route:list`, `db:table`, `db:show`, `git`).

### 11.8 Manual verification checklist

- [ ] Storefront returns ordered eligible products, max 8.
- [ ] Unavailable products are absent from storefront.
- [ ] No empty section when nothing eligible.
- [ ] Admin sees all saved items with reasons and counts.
- [ ] Reordering persists.
- [ ] Clearing persists.
- [ ] Omitted key preserves.
- [ ] Self/duplicate/invalid/soft-deleted-new IDs return 422.
- [ ] Existing soft-deleted saved IDs survive unrelated edits.
- [ ] View-only admin cannot mutate.
- [ ] Audit shows ordered old/new IDs.
- [ ] `related_products` unchanged.
- [ ] No route or permission added.

---

## 12. Regression Risks

| # | Risk | Impact | Likelihood | Mitigation |
|---|---|---|---|---|
| R-01 | Reusing `syncRelationAudited()` sorts IDs and destroys order | High | High if reused | Dedicated `syncCrossSellRelation()` |
| R-02 | `cross_sell_ids` added to `WRITABLE_FIELDS` | High | Medium | Explicit exclusion + review |
| R-03 | Test DB missing pivot due to early return, or FK failure on fresh DB | Medium | High | Guarded helper called in both locations |
| R-04 | N+1 media/category queries in admin list | Medium | Medium | Eager-load `crossSellProductsAdmin.media` and `.categories` |
| R-05 | Soft-deleted saved IDs dropped by validation | High | Medium | Permit IDs already attached |
| R-06 | `sync` detaches trashed rows when a client omits them | Medium | Medium | Dashboard submits all saved IDs; document replace semantics |
| R-07 | Storefront hides configured products due to canonical rules | Medium | Medium | Admin warnings; user decision D5 |
| R-08 | Channel rules surprise merchandisers (home excludes fast-shipping) | Medium | Medium | `storefront_channels` map + documentation |
| R-09 | Detail payload growth | Low | Low | Hard cap 8 |
| R-10 | Existing `related_products` changed accidentally | High | Low | Separate relation/key; regression tests |
| R-11 | Admin UI not in repo → integration drift | Medium | Medium | Publish contract; contract tests; coordinate release |
| R-12 | Concurrent admin edits last-write-wins | Low | Low | Existing product update behavior |
| R-13 | Hard delete cascade removes links | Medium | Low | Document; soft delete is normal path |
| R-14 | Detail caching added later without invalidation | Medium | Future | Documented risk; regression note |
| R-15 | Audit volume from frequent reorders | Low | Low | Audit only when ordered set changes |
| R-16 | Category reason computed without eager load | Low | Low | Controller eager-loads categories |
| R-17 | Custom rule query cost for huge payloads | Low | Low | Single `whereIn` query |
| R-18 | Route/permission drift | Low | Low | No route changes; route:list proof |

### 12.1 Compatibility matrix

| Consumer | Before | After | Action |
|---|---|---|---|
| Storefront detail | No `cross_sell_products` | Key present (array) | Deploy backend first; UI ignores until ready |
| Storefront list | Unchanged | Unchanged | None |
| Admin dashboard | No cross-sell keys | Additive keys | Add UI section |
| Admin clients sending full product payload without key | Links preserved | Links preserved | None |
| Admin clients sending `cross_sell_ids: []` | N/A | Clears links | Document |
| Import/export | No cross-sell columns | Unchanged | Out of scope |
| Mobile app | Ignores unknown key | Unchanged | None |

---

## 13. Exact Files Expected to Change

| # | File | Type | Change |
|---|---|---|---|
| 1 | `database/migrations/2026_11_08_000001_create_product_cross_sells_table.php` | New | Pivot table, FKs, unique, index |
| 2 | `app/Rules/ValidCrossSellIds.php` | New | Self/duplicate/existence/soft-delete validation |
| 3 | `packages/marvel/src/Database/Models/Product.php` | Modify | Add `crossSellProducts()`, `crossSellProductsAdmin()` |
| 4 | `packages/marvel/src/Database/Repositories/ProductRepository.php` | Modify | Add ordered sync + call site |
| 5 | `packages/marvel/src/Http/Requests/ProductCreateRequest.php` | Modify | Add `cross_sell_ids` rules |
| 6 | `packages/marvel/src/Http/Requests/ProductUpdateRequest.php` | Modify | Add `cross_sell_ids` rules + existing IDs |
| 7 | `packages/marvel/src/Http/Resources/product/ProductResource.php` | Modify | Add cross-sell keys + helper |
| 8 | `packages/marvel/src/Http/Controllers/ProductController.php` | Modify | Eager-load admin relation in store/show/update |
| 9 | `app/Services/General/ProductService.php` | Modify | Constant, fetch method, call site |
| 10 | `app/Http/Resources/Product/ProductResource.php` | Modify | Add `cross_sell_products` key |
| 11 | `resources/lang/en/activity.php` | Modify | Audit description key |
| 12 | `resources/lang/ar/activity.php` | Modify | Audit description key |
| 13 | `resources/lang/en/validation.php` | Modify | Custom rule messages `[PROPOSED]` |
| 14 | `resources/lang/ar/validation.php` | Modify | Custom rule messages `[PROPOSED]` |
| 15 | `tests/Concerns/CreatesTestTables.php` | Modify | Guarded pivot helper in both call sites |
| 16 | `tests/Feature/General/ProductCrossSellEndpointTest.php` | New | Storefront tests |
| 17 | `tests/Feature/ProductCrossSellAdminTest.php` | New | Admin tests |

### 13.1 Deliberately not changed

| Area | Reason |
|---|---|
| `routes/api.php` | No new public route |
| `packages/marvel/src/Rest/Routes.php` | No new admin route |
| `ProductRepository::WRITABLE_FIELDS` | `cross_sell_ids` is not a column |
| `related_products` logic | Must remain category-based |
| Pricing services | Display-only feature |
| Cart/Checkout/Order/Payment/Inventory services | Out of scope |
| Product import/export | Out of scope |
| Admin dashboard source | Not in this repository |
| API documentation files | Documentation mode is OFF |

### 13.2 Line-level anchors

| File | Anchor |
|---|---|
| `Product.php` | After `tags()` `:354-357`, before `orders()` `:364` |
| `ProductService.php` | Call after `:267`; method near `:645-666`; constant near class top `:26-31` |
| `app/ProductResource.php` | After `:72` |
| `ProductCreateRequest.php` | After tags `:96-97`, before `tax_enabled` `:102` |
| `ProductUpdateRequest.php` | After tags `:95-96`, before `tax_enabled` `:101` |
| `ProductRepository.php` | Call in `syncRelation()` after `:394-396`; method after `:436` |
| Admin `ProductResource.php` | After `:67` |
| `ProductController.php` | Load arrays `:251-256`, `:345-351`, `:369-374` |
| `CreatesTestTables.php` | Before `:18-20`; after `:156` |

---

## 14. Migration and Rollback Strategy

### 14.1 Classification

| Attribute | Value |
|---|---|
| Type | Additive schema creation |
| Reversible | Yes (`down()` drops the table) |
| Data-affecting | No |
| Destructive in `up()` | No |
| Production data mutation | None |
| Backfill | None |

### 14.2 Deployment order

1. Deploy migration (creates empty table).
2. Deploy backend code (storefront returns `[]`; admin accepts/returns empty lists).
3. Verify baseline: storefront detail still works; no cross-sell section.
4. Deploy dashboard UI (external repository).
5. Merchandisers configure recommendations.
6. Deploy storefront UI rendering (external).
7. Monitor error rate and detail latency.

### 14.3 Rollback

| Scenario | Action | Data impact |
|---|---|---|
| Storefront issue | Remove the `cross_sell_products` resource key / service call | None; table and data remain |
| Admin issue | Stop sending `cross_sell_ids` | Saved rows remain |
| Full rollback | `php artisan migrate:rollback` for this migration | **Destroys all cross-sell data** |
| Emergency | Disable storefront UI section | None |

Before any rollback that drops the table, export `product_cross_sells`.

`[RISK]` — rollback after merchandising work destroys configuration; coordinate with the business owner.

---

## 15. Acceptance Criteria

### 15.1 Business decisions

- [ ] D1: Independent, directional lists; no automatic reverse rows.
- [ ] D2: No saved maximum; storefront shows at most 8 eligible products.
- [ ] D3: No section when none exist or none are eligible.
- [ ] D4: No Cart/Checkout/Pricing/Inventory/Payment changes.
- [ ] D5: Unavailable recommendations remain saved and visible in Admin with reasons; hidden from storefront; reappear automatically when eligible.
- [ ] D6: No store/tenant columns or logic.

### 15.2 Backend

- [ ] Migration creates `product_cross_sells` with FKs, unique, and index.
- [ ] Relations return ordered rows; admin relation includes trashed targets.
- [ ] Request validation rejects self, duplicates, missing, and newly added soft-deleted IDs.
- [ ] Existing attached soft-deleted IDs are preserved.
- [ ] Ordered sync runs inside the product transaction.
- [ ] Audit records ordered old/new IDs only on change.
- [ ] No new routes or permissions.

### 15.3 Admin contract

- [ ] Show/store/update return `cross_sell_ids`, compact items, saved count, eligible count, and limit 8.
- [ ] Availability reasons are derived from canonical rules.
- [ ] Channel visibility map is accurate.
- [ ] Admin list response unchanged.

### 15.4 Storefront contract

- [ ] Detail returns ordered eligible products, max 8.
- [ ] Unavailable products excluded.
- [ ] Empty array when nothing eligible.
- [ ] `related_products` unchanged.
- [ ] Response remains additive/backward-compatible.

### 15.5 Verification

- [ ] New tests pass.
- [ ] Regression suites pass.
- [ ] Syntax checks pass.
- [ ] Route inventory unchanged.
- [ ] Independent review completed; blocker/must-fix findings resolved.
- [ ] Diff contains only intended files.
- [ ] Evidence report produced.

---

## 16. Open Questions / Unknowns

| # | Question | Proposed default | Why it matters | Blocking? |
|---|---|---|---|---|
| Q-01 | Does `eligible_cross_sell_count` mean product-level eligibility or channel-specific eligibility? | Product-level eligibility; channel visibility shown per item | One number cannot represent two channels | Yes — admin contract |
| Q-02 | Should soft-deleted saved targets be visible in Admin? | Yes, with `deleted` reason and preserved on save | D5 says all saved remain visible; otherwise edits silently delete them | Yes — validation semantics |
| Q-03 | Should `cross_sell_products` always be present (empty array) or omitted when empty? | Present with `[]` | Frontend can distinguish “no data” from “old backend” | No for backend; yes for contract |
| Q-04 | Confirm fixed 8 constant (no config override) | `ProductService::STOREFRONT_DISPLAY_LIMIT = 8` | Prevents config drift; user decided 8 | Yes — implementation |
| Q-05 | Should validation translations be added, or should messages be hardcoded like `UniqueProductBarcode`? | Add translation keys | Project localization rules | No |
| Q-06 | Should import/export include cross-sells? | No, out of scope | Separate contract expansion | No |
| Q-07 | Dashboard repository and release owner | Provide before UI phase | Required for file-level UI work | Yes for UI only |
| Q-08 | Should the admin list show a “has cross-sells” indicator? | No | Extra query, not required | No |
| Q-09 | Should product duplication copy cross-sells? | No | Separate product behavior | No |
| Q-10 | Should variants/SKUs be recommendable? | Product-level only | Larger model change | No |
| Q-11 | Should reciprocal links be offered as a UI convenience? | No | Not requested; business rule risk | No |
| Q-12 | What is the acceptable detail latency budget? | No material regression vs. current | Performance acceptance | No |

---

## 17. Risks and Mitigations

| # | Risk | Impact | Mitigation | Status |
|---|---|---|---|---|
| K-01 | Order destroyed by sorted sync helper | High | Dedicated ordered sync | Addressed |
| K-02 | `cross_sell_ids` mass assignment | High | Exclude from `WRITABLE_FIELDS` | Addressed |
| K-03 | Test schema drift | Medium | Guarded helper in both call sites | Addressed |
| K-04 | Silent deletion of unavailable rows | High | Admin shows all; validation preserves attached trashed IDs; no auto-prune | Addressed |
| K-05 | N+1 queries | Medium | Eager loads + query-count test | Addressed |
| K-06 | Channel confusion | Medium | `storefront_channels` map; documentation | Addressed |
| K-07 | External UI drift | Medium | Contract + examples + contract tests | Open |
| K-08 | Existing related products regression | High | Separate key/relation + regression tests | Addressed |
| K-09 | Migration rollback data loss | Medium | Export before rollback; documented | Accepted |
| K-10 | Hard-delete cascade surprise | Medium | Document; soft delete normal | Accepted |
| K-11 | Concurrent edits | Low | Existing product behavior | Accepted |
| K-12 | Detail caching later | Medium | Documented regression note | Monitored |
| K-13 | Huge payload with no saved cap | Low | PHP request limits; single query; monitor | Accepted |
| K-14 | Category reason missing when not loaded | Low | Controller eager-loads categories | Addressed |
| K-15 | Route/permission drift | Low | No changes; route:list proof | Addressed |

---

## 18. Implementation Phases and Dependencies

### Phase 0 — Approval gate

- [ ] Approve §2 decisions.
- [ ] Resolve §16 Q-01..Q-04.
- [ ] Confirm no conflicting work on product repository/service/resources.
- [ ] Confirm unrelated working-tree changes stay out of the feature diff.

**Exit:** explicit user approval. No code before this.

### Phase 1 — Persistence foundation

**Files:** migration, `Product.php`, `CreatesTestTables.php`.

**Tasks:**

1. Create pivot migration.
2. Add `crossSellProducts()` and `crossSellProductsAdmin()`.
3. Add guarded test-schema helper and both call sites.

**Exit:**

- `php artisan migrate` succeeds.
- Existing test DB gets the table.
- Relation returns ordered rows.
- Unique constraint and cascade verified.

**Depends on:** Phase 0.

### Phase 2 — Admin write path

**Files:** `ValidCrossSellIds.php`, both product requests, `ProductRepository.php`, activity translations, validation translations.

**Tasks:**

1. Add validation rule.
2. Add request rules.
3. Add ordered sync + call site.
4. Add translation keys.

**Exit:**

- Create/update persist ordered rows.
- Omitted key preserves; `[]` clears.
- Self/duplicate/missing/new-deleted rejected.
- Preserved deleted IDs accepted.
- Transaction rollback verified.

**Depends on:** Phase 1.

### Phase 3 — Admin read path

**Files:** admin `ProductResource.php`, admin `ProductController.php`.

**Tasks:**

1. Add response keys + helper.
2. Eager-load admin relation in store/show/update.
3. Verify counts/reasons/channel map.

**Exit:**

- Show returns all saved items, counts, limit, reasons.
- Existing response keys unchanged.
- No N+1.

**Depends on:** Phase 1, Phase 2.

### Phase 4 — Storefront read path

**Files:** `ProductService.php`, storefront `ProductResource.php`.

**Tasks:**

1. Add constant, fetch method, call site.
2. Add response key.

**Exit:**

- Ordered, eligible, max 8.
- Empty array when none.
- `related_products` unchanged.
- No side effects.

**Depends on:** Phase 1.

### Phase 5 — Tests and verification

**Files:** two new test files; regression suites.

**Tasks:**

1. Implement SF matrix.
2. Implement AD matrix.
3. Run focused and regression suites.
4. Run syntax checks and route inventory.
5. Independent review; fix blocker/must-fix findings; re-run.

**Exit:**

- All required tests pass.
- No new routes.
- Review findings resolved.
- Evidence report produced.

**Depends on:** Phases 1–4.

### Phase 6 — Dashboard handoff (external)

**Tasks:**

1. Provide API contract/examples.
2. Dashboard team implements the section.
3. Contract tests or staging verification.

**Exit:**

- Admin acceptance criteria met in the dashboard repository.

**Depends on:** Phase 3 contract.

### Phase 7 — Rollout

1. Deploy migration.
2. Deploy backend.
3. Verify `cross_sell_products: []` baseline.
4. Deploy dashboard.
5. Configure recommendations.
6. Deploy storefront rendering.
7. Monitor.

Rollback per §14.3.

**Depends on:** Phase 5 and, for UI, Phase 6.

---

## Appendix A — Evidence index

| Topic | Reference |
|---|---|
| Storefront route group | `routes/api.php:51` |
| Storefront detail route | `routes/api.php:87-88` |
| Storefront detail handler | `app/Http/Controllers/Api/General/ProductController.php:239-250` |
| Storefront service injection | `app/Http/Controllers/Api/General/ProductController.php:33-37` |
| Detail service | `app/Services/General/ProductService.php:235-270` |
| Related products | `app/Services/General/ProductService.php:645-666` |
| Pricing enrichment | `app/Services/General/ProductService.php:66-69` |
| Product relations | `app/Services/General/ProductService.php:71-85` |
| Storefront detail resource | `app/Http/Resources/Product/ProductResource.php:72` |
| Mini resource | `app/Http/Resources/Product/ProductMiniResource.php:19-53` |
| Active scope | `packages/marvel/src/Database/Models/Product.php:578-587` |
| Active status scope | `packages/marvel/src/Database/Models/Product.php:573-576` |
| Soft deletes | `packages/marvel/src/Database/Models/Product.php:29` |
| Casts | `packages/marvel/src/Database/Models/Product.php:105-115` |
| Fast-shipping scope registration | `packages/marvel/src/Database/Models/Product.php:143` |
| Product categories relation | `packages/marvel/src/Database/Models/Product.php:330-336` |
| Product tags relation | `packages/marvel/src/Database/Models/Product.php:354-357` |
| Category active scope | `packages/marvel/src/Database/Models/Category.php:66-67` |
| Channel home filter | `app/Traits/HasChannelFilter.php:9-20` |
| Fast-shipping global scope | `app/Models/Scopes/FastShippingScope.php:12-23` |
| Channel config | `config/channel.php` |
| API middleware group | `app/Http/Kernel.php:42-51` |
| Admin route group | `packages/marvel/src/Rest/Routes.php:135` |
| Product resource routes | `packages/marvel/src/Rest/Routes.php:252-267` |
| Admin controller permissions | `packages/marvel/src/Http/Controllers/ProductController.php:105-114` |
| Admin list/search | `packages/marvel/src/Http/Controllers/ProductController.php:123-156` |
| Admin store | `packages/marvel/src/Http/Controllers/ProductController.php:238-259` |
| Admin show | `packages/marvel/src/Http/Controllers/ProductController.php:317-355` |
| Admin update | `packages/marvel/src/Http/Controllers/ProductController.php:364-379` |
| Repository writable fields | `packages/marvel/src/Database/Repositories/ProductRepository.php:53-81` |
| Repository store transaction | `packages/marvel/src/Database/Repositories/ProductRepository.php:111-168` |
| Repository update transaction | `packages/marvel/src/Database/Repositories/ProductRepository.php:171-314` |
| Relation sync | `packages/marvel/src/Database/Repositories/ProductRepository.php:376-403` |
| Audited sync | `packages/marvel/src/Database/Repositories/ProductRepository.php:409-436` |
| Create request rules | `packages/marvel/src/Http/Requests/ProductCreateRequest.php:53,96-102` |
| Update request rules | `packages/marvel/src/Http/Requests/ProductUpdateRequest.php:53,60,70,95-101` |
| Admin resource | `packages/marvel/src/Http/Resources/product/ProductResource.php:67` |
| Admin `roundMoney()` | `packages/marvel/src/Http/Resources/product/ProductResource.php:163` |
| Permission constants | `packages/marvel/src/Enums/Permission.php:36-37,145-148` |
| Audit service | `app/Audit/ActivityAuditService.php:50-60` |
| Activity translations | `resources/lang/en/activity.php:99-104`; `resources/lang/ar/activity.php:99-104` |
| Existing custom rule precedent | `app/Rules/UniqueProductBarcode.php`; referenced at `ProductUpdateRequest.php:109` |
| Validation error envelope | `app/Exceptions/Handler.php:177-186` |
| Test schema early return | `tests/Concerns/CreatesTestTables.php:18-20` |
| Test products table | `tests/Concerns/CreatesTestTables.php:113` |
| Test barcodes table | `tests/Concerns/CreatesTestTables.php:156` |
| PHPUnit/DB config | `phpunit.xml:1-2,21-26` |
| Storefront test conventions | `tests/Feature/General/ProductsEndpointTest.php:24-58,205-210` |
| Admin test conventions | `tests/Feature/ProductCrudTest.php:24-126,170-215,778-795` |
| Dashboard absence | `docs/audits/payment-gateway-settings-unification-audit.md:56` |

## Appendix B — Repository state at Phase One

```
Branch: main
HEAD:   39b2c331b7582462936a272daa6fa3184652be57
Laravel: 10.30.1
```

Pre-existing working-tree changes (unrelated to this feature):

```
 M .env.example
 M .phpunit.cache/test-results
 M app/Console/Commands/SyncCurrencyRates.php
 M app/Console/Kernel.php
 M app/Http/Controllers/Api/Admin/Wms/PackingController.php
 M app/Services/Currency/CurrencyService.php
 M app/Services/Currency/ExchangeRateSyncService.php
 M app/Services/Currency/Providers/FrankfurterProvider.php
 M app/Services/Fulfillment/FulfillmentService.php
 M app/Services/Fulfillment/PackingService.php
 M config/currency.php
 M resources/lang/ar/wms.php
 M resources/lang/en/wms.php
 M tests/Feature/Currency/CurrencySchedulerTest.php
 M tests/Feature/Currency/ExchangeRateProviderTest.php
 M tests/Feature/Currency/ExchangeRateSyncTest.php
 M tests/Feature/Currency/MandatoryCurrencyGateTest.php
 M tests/Feature/Wms/WmsPackingTest.php
?? app/Exceptions/ExchangeRateApiDisabledException.php
?? docs/features/
?? tests/Feature/Currency/ExchangeRateApiToggleTest.php
```

These must not be mixed into the cross-selling implementation diff.

## Appendix C — Not verified in Phase One

| Item | Status |
|---|---|
| Test suite execution | NOT RUN (read-only phase) |
| Migration execution | NOT RUN |
| Admin UI source location/behavior | `[UNKNOWN]` — not in this repository |
| Storefront UI source location/behavior | `[UNKNOWN]` — not in this repository |
| Production data volume/performance | `[UNKNOWN]` |
| Channel-specific admin count semantics | Awaiting approval (Q-01) |
| Validation translation vs hardcoded messages | Awaiting approval (Q-05) |

---

**End of plan. Implementation has NOT started. Awaiting explicit user approval for Phase Two.**
