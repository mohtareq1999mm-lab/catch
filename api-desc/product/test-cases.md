# Product Module — Test Cases

## Existing Tests

**File:** `tests/Feature/ProductCrudTest.php` — **62 tests**, all passing.

Also: `ProductAdminTest.php` (17 tests) + `ProductProductionHardenTest.php` (28 tests) + `AttributesProductionHardenTest.php` (32 tests) = 139 total.

## Recommended Test Cases (already covered)

### TC-PRD-001: Guest can list products (public)
```php
public function test_guest_can_list_products()
```
- No auth token
- `GET /api/v1/products`
- Assert 200

### TC-PRD-002: Guest can show product (public)
```php
public function test_guest_can_show_product()
```
- Create product
- `GET /api/v1/products/{id}` as guest
- Assert 200

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
- Auth as admin with create-product permission
- POST with name, description, price, categories, images, in_stock, has_discount=false, has_flash_sale=false, product_type=simple
- Assert 201, check id returned

### TC-PRD-007: Admin can create variable product with variants
```php
public function test_admin_can_create_variable_product()
```
- Auth as admin
- POST with name, description, categories, images, product_type=variable
- Include variants array with price, quantity, attribute_values
- Assert 201, assert variants in response

### TC-PRD-008: Admin can create product without variants
```php
public function test_admin_can_create_product_without_variants()
```
- Auth as admin
- POST with simple product
- Assert 201, assert product_type=simple

### TC-PRD-009: Admin can show product by ID
```php
public function test_admin_can_show_product_by_id()
```
- Create product
- GET /products/{id}
- Assert 200, assert id matches

### TC-PRD-010: Admin can show product by slug
```php
public function test_admin_can_show_product_by_slug()
```
- Create product with known slug
- GET /products/{slug}
- Assert 200

### TC-PRD-011: Show nonexistent product returns 404
```php
public function test_show_nonexistent_product_returns_404()
```
- GET /products/99999
- Assert 404

### TC-PRD-012: Admin can update product name
```php
public function test_admin_can_update_product_name()
```
- Create product
- PUT /products/{id} with new name
- Assert 200, assert name updated

### TC-PRD-013: Admin can update product with new variants
```php
public function test_admin_can_update_product_with_new_variants()
```
- Create variable product with variants
- PUT with new variants array
- Assert 200, old variants removed

### TC-PRD-014: Admin can delete product
```php
public function test_admin_can_delete_product()
```
- Create product
- DELETE /products/{id}
- Assert 200, assert soft-deleted (deleted_at not null)

### TC-PRD-015: Delete nonexistent product returns 404
```php
public function test_delete_nonexistent_product_returns_404()
```
- DELETE /products/99999
- Assert 404

### TC-PRD-016: Create product requires name
```php
public function test_create_product_requires_name()
```
- POST without name
- Assert 422

### TC-PRD-017: Create product requires categories
```php
public function test_create_product_requires_categories()
```
- POST without categories
- Assert 422

### TC-PRD-018: Create product requires images
```php
public function test_create_product_requires_images()
```
- POST without images
- Assert 422

### TC-PRD-019: Invalid product_type returns 422
```php
public function test_create_product_invalid_product_type()
```
- POST with product_type=invalid
- Assert 422

### TC-PRD-020: List products paginates
```php
public function test_list_products_paginates()
```
- Create 4 products
- GET /products?limit=2
- Assert count=2

### TC-PRD-021: Product list response structure
```php
public function test_product_list_response_structure()
```
- GET /products
- Assert JSON structure has data.data, current_page, total, etc.

### TC-PRD-022: Product show response structure
```php
public function test_product_show_response_structure()
```
- GET /products/{id}
- Assert JSON structure has id, name, slug, price, product_type, etc.

### TC-PRD-023: User without permission cannot create
```php
public function test_view_only_user_cannot_create()
```
- Auth as user with view-products only
- POST /products
- Assert 403

### TC-PRD-024: User without permission cannot update
```php
public function test_view_only_user_cannot_update()
```
- Auth as user with view-products only
- PUT /products/{id}
- Assert 403

### TC-PRD-025: Product is soft-deleted
```php
public function test_product_is_soft_deleted()
```
- Delete product
- Assert database has product with deleted_at set
- Assert index does not return it

## Still Missing Tests

### TC-PRD-026: Review requires product_id for list
- GET /reviews without product_id → 422

### TC-PRD-027: Review create requires rating
- POST /reviews without rating → 422

### TC-PRD-028: Review create requires comment
- POST /reviews without comment → 422

### TC-PRD-029: Has_discount true but missing discount_type
- POST with has_discount=1, no discount_type → 422

### TC-PRD-030: Has_flash_sale true but missing flash_sale_id
- POST with has_flash_sale=1, no flash_sale_id → 422

### TC-PRD-031: Create product with tags
- POST with valid tags array → 201, tags in response

### TC-PRD-032: Create product with invalid tag IDs
- POST with tags=[99999] → 422

### TC-PRD-033: Admin show includes tags
- GET /products/{id} returns tags for admin

### TC-PRD-034: Public product list includes tags
- GET /general/products returns tags in ProductMiniResource

### TC-PRD-035: Public product by slug includes tags
- GET /general/products/{slug} returns tags

### TC-PRD-036: Filter products by tags (slug)
- GET /products?tags=summer returns only tagged products

### TC-PRD-037: Filter products by tags (ID)
- GET /products?tags=1,2 returns products with both tags (AND)

### TC-PRD-038: Tags filter supports numeric ID
- GET /general/products?tags=1,2 works

### TC-PRD-039: Tags filter supports slug
- GET /general/products?tags=summer,winter works

### TC-PRD-040: Update product replaces existing tags
- PUT /products/{id} with new tags array replaces old associations
