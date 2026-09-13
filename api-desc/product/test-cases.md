# Product Module — Test Cases

> Dual-surface: Admin `apiResource` (`products.index|store|show|update|destroy`, bulk-delete, destroy-all, import/export) + Storefront listing/detail (`GET /api/v1/general/products`, `GET /api/v1/general/products/{slug}`). Verified 2026-09-13.

## Existing Tests

**File:** `tests/Feature/ProductCrudTest.php` — **62 tests**, all passing.

Also: `ProductAdminTest.php` (17) + `ProductProductionHardenTest.php` (28) + `AttributesProductionHardenTest.php` (32) = 139 total. Storefront: `tests/Feature/General/ProductsEndpointTest.php` + `tests/Feature/ImportExport/*` + `tests/Unit/ProductPricingServiceTest.php`.

## Recommended Test Cases (already covered)

### TC-PRD-001: Guest can list admin products requires auth
```php
public function test_guest_cannot_list_admin_products()
```
- No auth token
- `GET /api/v1/products`
- Assert 401 (admin `auth:sanctum`)

### TC-PRD-001b: Guest can list storefront products (public)
```php
public function test_guest_can_list_storefront_products()
```
- No auth token
- `GET /api/v1/general/products`
- Assert 200 with `data.data`, `filters`, `categories`, `links`

### TC-PRD-002: Guest can show storefront product by slug (public)
```php
public function test_guest_can_show_storefront_product_by_slug()
```
- Create product
- `GET /api/v1/general/products/{slug}` as guest
- Assert 200 with `price/current_price/currency`

### TC-PRD-003: Guest cannot create product
```php
public function test_guest_cannot_create_product()
```
- `POST /api/v1/products` as guest
- Assert 401

### TC-PRD-004: Guest cannot update product
```php
public function test_guest_cannot_update_product()
```
- Create product
- `PUT /api/v1/products/{id}` as guest
- Assert 401

### TC-PRD-005: Guest cannot delete product
```php
public function test_guest_cannot_delete_product()
```
- Create product
- `DELETE /api/v1/products/{id}` as guest
- Assert 401

### TC-PRD-006: Admin can create simple product
```php
public function test_admin_can_create_simple_product()
```
- Auth as admin with `create-product`
- POST with `name{en,ar}, description{en,ar}, price, categories, images, in_stock, has_discount=false, has_flash_sale=false, product_type=simple`
- Assert 201, `item_type:PHYSICAL` default, `price_including_tax` present

### TC-PRD-007: Admin can create variable product with variants
```php
public function test_admin_can_create_variable_product()
```
- Auth as admin
- POST with `product_type=variable` + `variants[{price,quantity,attribute_values:[id],sku?}]`
- Assert 201, `variants` in response with `attributes`

### TC-PRD-008: Admin can create product without variants (simple)
```php
public function test_admin_can_create_product_without_variants()
```
- Assert 201, `product_type=simple`

### TC-PRD-009: Admin can show product by ID
```php
public function test_admin_can_show_product_by_id()
```
- Create product
- `GET /api/v1/products/{id}` with admin token
- Assert 200

### TC-PRD-010: Admin can show product by slug
```php
public function test_admin_can_show_product_by_slug()
```
- Create product with known slug
- `GET /api/v1/products/{slug}` with admin token
- Assert 200

### TC-PRD-010b: Storefront slug not found / channel mismatch → 404
```php
public function test_storefront_unknown_slug_returns_404()
```
- `GET /api/v1/general/products/unknown-slug` as guest
- Assert 404 `MESSAGE.NOT_FOUND`

### TC-PRD-011: Show nonexistent product returns 404
```php
public function test_show_nonexistent_product_returns_404()
```
- `GET /api/v1/products/99999` with admin token → 404

### TC-PRD-012: Admin can update product name
```php
public function test_admin_can_update_product_name()
```
- Create product, `PUT /api/v1/products/{id}` with new `name{en}`
- Assert 200

### TC-PRD-013: Admin can update product with new variants (old deleted)
```php
public function test_admin_can_update_product_with_new_variants()
```
- Create variable product, PUT with new `variants` array
- Assert 200, old `product_variants` removed

### TC-PRD-014: Admin can delete product (soft delete)
```php
public function test_admin_can_delete_product()
```
- Create product, `DELETE /api/v1/products/{id}` → 200, `deleted_at` not null, hidden from both admin+storefront listings

### TC-PRD-015: Delete nonexistent product returns 404
```php
public function test_delete_nonexistent_product_returns_404()
```
- `DELETE /api/v1/products/99999` → 404

### TC-PRD-016: Create product requires name
```php
public function test_create_product_requires_name()
```
- POST without `name` → 422

### TC-PRD-017: Create product requires categories
```php
public function test_create_product_requires_categories()
```
- POST without `categories` → 422

### TC-PRD-018: Create product requires images
```php
public function test_create_product_requires_images()
```
- POST without `images` → 422

### TC-PRD-019: Invalid product_type returns 422
```php
public function test_create_product_invalid_product_type()
```
- POST with `product_type=invalid` → 422

### TC-PRD-019b: Invalid item_type returns 422
```php
public function test_create_product_invalid_item_type()
```
- POST with `item_type=VIRTUAL` → 422; valid `PHYSICAL|DIGITAL`

### TC-PRD-020: List products paginates (admin + storefront)
```php
public function test_list_products_paginates()
```
- Create 4 products, `GET /api/v1/products?limit=2` with admin → 200 count=2; `GET /api/v1/general/products?limit=2` as guest → 200 count=2 + `filters`

### TC-PRD-021: Product list response structure

- Admin `GET /api/v1/products` → JSON `data.data, current_page, total` + fields `price_including_tax/tax/item_type`
- Storefront `GET /api/v1/general/products` → `data.data, filters, categories, links, currency`

### TC-PRD-022: Product show response structure
- Admin show → `id, name{en,ar}, slug, price, product_type, item_type …`
- Storefront detail → `id, name{en,ar}, slug, price/currency, variants, related_products, filters`

### TC-PRD-023: User without permission cannot create
```php
public function test_view_only_user_cannot_create()
```
- Auth with `view-products` only, `POST /api/v1/products` → 403

### TC-PRD-024: User without permission cannot update
```php
public function test_view_only_user_cannot_update()
```
- Auth with `view-products` only, `PUT /api/v1/products/{id}` → 403

### TC-PRD-025: Product is soft-deleted
- Delete product, assert `deleted_at` set, not returned by either listing nor by slug detail

## Still Missing / Recommended New Tests (storefront dual-surface)

### TC-PRD-026: Review requires product_id for list
- `GET /reviews` without `product_id` → 422

### TC-PRD-027: Review create requires rating
- `POST /reviews` without `rating` → 422

### TC-PRD-028: Review create requires comment
- `POST /reviews` without `comment` → 422

### TC-PRD-029: has_discount true but missing discount_type
- `POST /api/v1/products` with `has_discount=1` no `discount_type` → 422

### TC-PRD-030: has_flash_sale true but missing flash_sale_id
- `POST` with `has_flash_sale=1` no `flash_sale_id` → 422

### TC-PRD-031: Create product with tags
- `POST` with valid `tags:[id1,id2]` → 201, `tags` in response

### TC-PRD-032: Create product with invalid tag IDs
- `POST` with `tags=[99999]` → 422

### TC-PRD-033: Admin show includes tags
- `GET /api/v1/products/{id}` returns `tags` for admin

### TC-PRD-034: Public product list includes tags (storefront)
- `GET /api/v1/general/products` returns `tags` in `ProductMiniResource`

### TC-PRD-035: Public product by slug includes tags
- `GET /api/v1/general/products/{slug}` returns `tags`

### TC-PRD-036: Filter products by tags (slug)
- `GET /api/v1/products?tags=summer` (admin) + `GET /api/v1/general/products?tags=summer` (storefront) → only tagged

### TC-PRD-037: Filter products by tags (ID)
- `GET /api/v1/products?tags=1,2` (AND logic)

### TC-PRD-038: Tags filter supports numeric ID (storefront)
- `GET /api/v1/general/products?tags=1,2` → 200 facets filtered

### TC-PRD-039: Tags filter supports slug (storefront)
- `GET /api/v1/general/products?tags=summer,winter` → 200

### TC-PRD-040: Update product replaces existing tags
- `PUT /api/v1/products/{id}` with new `tags` array → old pivot rows removed

### TC-PRD-041: Storefront `type` enumeration
- `GET /api/v1/general/products?type=best_product_sales` → 200 curated set; with invalid `type=unknown` → 422

### TC-PRD-042: Storefront `type=all` ≡ fallback
- `GET /api/v1/general/products?type=all` same shape as `GET /api/v1/general/products`

### TC-PRD-043: Storefront `order_price` ordering
- `GET /api/v1/general/products?order_price=asc` → prices ascending; `order_price=desc` descending

### TC-PRD-044: Storefront dimension & rating filters
- `GET /api/v1/general/products?height_min=10&height_max=20&rating_min=4` → only matching

### TC-PRD-045: Storefront search Scout fallback
- `GET /api/v1/general/products?search=shirt` returns matches (Scout when enabled, LIKE fallback otherwise)

### TC-PRD-046: Storefront cache & currency
- Two `GET /api/v1/general/products?limit=5` with different `Currency` header → different `currencyAwareCacheKey` (200 both, no 422); `?search=shirt` bypasses cache

### TC-PRD-047: item_type immutability after order
- Create `PHYSICAL`, create `order_item` for it, then `PUT` with `item_type=DIGITAL` → 422

### TC-PRD-048: bulk-delete with >100 ids & destroyAll throttle
- `POST /api/v1/products/bulk-delete` with large array still deletes (or chunks); `DELETE /all` respects `throttle:admin`

