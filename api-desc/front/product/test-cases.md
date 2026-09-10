# Test Cases - Product Feature

## Current Coverage

**8 test files in `tests/Feature/` + 1 Unit test:**

| Test File | Lines | Focus |
|-----------|-------|-------|
| `ProductCrudTest.php` | 807 | Full CRUD with validation, permissions, authorization |
| `ProductAdminTest.php` | 322 | Admin endpoints, create/show/update/delete with permission checks |
| `ProductFilterTest.php` | 139 | Filtering by brand, attribute, price range, dimensions, combinations |
| `ProductProductionHardenTest.php` | 610 | N+1, pricing, flash sale, discount, bulk delete, large datasets |
| `ProductExportTest.php` | 139 | Export endpoint, file download, auth, filters |
| `ProductImportTest.php` | 1207 | Import with progress, cancellation, validation, CSV edge cases |
| `ProductTagTest.php` | 386 | Product-tag relationship, filtering by tags |
| `tests/Unit/ProductPricingServiceTest.php` | Unit | Pricing service unit tests |

---

## Existing Test Methods Summary

### ProductCrudTest (807 lines)

| # | Test | Description |
|---|------|-------------|
| 1 | Authorization: no_perm user cannot list | 403 |
| 2 | Authorization: view-only cannot create/update/delete | 403 |
| 3 | Validation: requires name, description, categories, images, product_type, in_stock, has_discount, has_flash_sale | 422 |
| 4 | Validation: invalid product_type | 422 |
| 5 | Validation: invalid category | 422 |
| 6 | Validation: invalid images | 422 |
| 7 | List: pagination | Correct page/meta |
| 8 | List: search | Filtered results |
| 9 | List: sorting | Correct order |
| 10 | Show: by ID | Full product data |
| 11 | Show: 404 for nonexistent | 404 |
| 12 | Store: full product creation with variants | 201 |
| 13 | Store: pricing and discounts | Correct calculation |
| 14 | Update: partial update | 200 |
| 15 | Update: dimension update | Updated correctly |
| 16 | Update: pricing recalculation | New prices calculated |
| 17 | Delete: single | 200, soft-deleted |
| 18 | Delete: bulk | Multiple deleted |
| 19 | Delete: destroyAll | All deleted |

### ProductFilterTest (139 lines)

| # | Test | Description |
|---|------|-------------|
| 1 | Filter by brand | Brand filtered correctly |
| 2 | Filter by attribute (size, color) | Attribute filtered |
| 3 | Filter by price range | Price bounded |
| 4 | Combined filters | Multiple filters applied |
| 5 | Filter by dimension (weight) | Weight filtered |

### ProductImportTest (1207 lines)

| # | Test | Description |
|---|------|-------------|
| 1 | Import with valid CSV | Products created |
| 2 | Import progress tracking | Status updates |
| 3 | Import cancellation | Cancelled mid-process |
| 4 | Import validation errors | Invalid rows tracked |
| 5 | CSV edge cases | Empty, malformed, encoding |

---

## Recommended Additional Tests

### Feature Tests

| # | Test | Description |
|---|------|-------------|
| FT-001 | Public listing with all strategy types | Each strategy returns correct products |
| FT-002 | Public listing with multiple filters | Combined brand + category + price |
| FT-003 | Public listing with full-text search | Meilisearch returns correct matches |
| FT-004 | Product detail with all relations | Categories, brands, tags, reviews returned |
| FT-005 | Product detail with variants | Variable product returns variants |
| FT-006 | Product detail with flash sale pricing | Flash sale price > discount price |
| FT-007 | Product detail with discount pricing | Discount applied correctly |
| FT-008 | Create product with minimum fields | Only required fields |
| FT-009 | Create variable product with variants | Variants created |
| FT-010 | Update product status | Status change persisted |
| FT-011 | Bulk delete with empty IDs | 422 |
| FT-012 | Fast shipping toggle | Flag toggled |
| FT-013 | Draft products listing | Only draft status |
| FT-014 | Low stock products listing | stock < 10 |

### Integration Tests

| # | Test | Description |
|---|------|-------------|
| IT-001 | GraphQL products query with pagination | Correct pagination |
| IT-002 | GraphQL createProduct mutation | Product created |
| IT-003 | GraphQL updateProduct mutation | Product updated |
| IT-004 | GraphQL deleteProduct mutation | Product deleted |
| IT-005 | Rental price calculation with all parameters | Complete breakdown |
| IT-006 | Best selling products ordered by volume | Correct order |
| IT-007 | Popular products by order count | Correct count |

### Edge Case Tests

| # | Test | Description |
|---|------|-------------|
| EC-001 | Product with 0 stock | Not in active listing |
| EC-002 | Product with expired discount | discount_active = false |
| EC-003 | Product with no images | Handles gracefully |
| EC-004 | Product with 50+ categories | Many-to-many works |
| EC-005 | Product with no variants (variable type declared) | Inconsistent state |
| EC-006 | Search with special characters | Handled safely |
| EC-007 | Price with many decimal places | Rounded correctly |
| EC-008 | Flash sale that has ended | flash_sale_active = false |
| EC-009 | Import with 10,000+ rows | Memory efficient |
| EC-010 | Export with 10,000+ products | Background job works |

### API Contract Tests

| # | Test | Description |
|---|------|-------------|
| CT-001 | Public list resource has all fields | id, name, slug, price, current_price, ratings, image |
| CT-002 | Public detail resource has all fields | Full product schema |
| CT-003 | Admin resource matches expected structure | All admin fields |
| CT-004 | Response type assertions | Float vs int vs string |

---

## Test Implementation Notes

```php
// Example test for product pricing hierarchy
class ProductPricingTest extends TestCase
{
    use RefreshDatabase;

    /** @test */
    public function test_flash_sale_overrides_product_discount()
    {
        $product = Product::factory()->create([
            'price' => 100,
            'has_discount' => true,
            'discount_type' => 'percentage',
            'discount_amount' => 20,
            'has_flash_sale' => true,
        ]);
        $flashSale = FlashSale::factory()->create([
            'discount_type' => 'fixed',
            'discount_amount' => 50,
        ]);
        $product->flash_sales()->attach($flashSale);

        $response = $this->getJson("/api/v1/general/products/{$product->slug}");

        $response->assertStatus(200);
        // Flash sale (50% off 100 = 50) > discount (20% off 100 = 80)
        $this->assertEquals(50, $response->json('data.current_price'));
    }

    /** @test */
    public function test_strategy_based_listing()
    {
        $response = $this->getJson('/api/v1/general/products?type=new_arrivals');

        $response->assertStatus(200)
            ->assertJsonStructure([
                'data' => [['id', 'name', 'slug', 'current_price']],
                'meta' => ['current_page', 'last_page', 'per_page', 'total']
            ]);
    }
}
```
