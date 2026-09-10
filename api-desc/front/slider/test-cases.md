# Test Cases - Slider Feature

## Current Coverage

**1 test file — `tests/Feature/SliderApiTest.php`** with 29 test methods.

---

## Existing Test Methods

### List (index)

| # | Test | Description |
|---|------|-------------|
| 1 | `test_authenticated_user_can_list_sliders` | Authenticated user gets paginated list |
| 2 | `test_guest_gets_401_for_list_sliders` | Guest gets 401 |
| 3 | `test_list_sliders_returns_empty_data_when_none_exist` | Empty table returns empty data |
| 4 | `test_list_sliders_pagination` | Pagination works correctly |
| 5 | `test_list_sliders_active_filter` | `?status=true` filters active |

### Show

| # | Test | Description |
|---|------|-------------|
| 6 | `test_authenticated_user_can_show_slider` | Fetch by ID returns slider |
| 7 | `test_guest_gets_401_for_show_slider` | Guest gets 401 |
| 8 | `test_show_slider_returns_404_for_nonexistent_id` | Invalid ID → 404 |

### Create

| # | Test | Description |
|---|------|-------------|
| 9 | `test_unauthenticated_user_cannot_create_slider` | Guest → 401 |
| 10 | `test_user_without_create_permission_gets_forbidden` | No permission → 403 |
| 11 | `test_authenticated_admin_can_create_slider` | Admin creates with valid data |
| 12 | `test_create_slider_returns_422_for_missing_title` | Missing title → 422 |
| 13 | `test_create_slider_returns_422_for_missing_images` | Missing images → 422 |

### Update

| # | Test | Description |
|---|------|-------------|
| 14 | `test_unauthenticated_user_cannot_update_slider` | Guest → 401 |
| 15 | `test_user_without_update_permission_gets_forbidden` | No permission → 403 |
| 16 | `test_authenticated_admin_can_update_slider` | Admin updates with valid data |
| 17 | `test_update_slider_returns_404_for_nonexistent_id` | Invalid ID → 404 |

### Delete

| # | Test | Description |
|---|------|-------------|
| 18 | `test_unauthenticated_user_cannot_delete_slider` | Guest → 401 |
| 19 | `test_user_without_delete_permission_gets_forbidden` | No permission → 403 |
| 20 | `test_authenticated_admin_can_soft_delete_slider` | Admin soft-deletes |
| 21 | `test_delete_slider_returns_404_for_nonexistent_id` | Invalid ID → 404 |
| 22 | `test_soft_deleted_slider_not_listed_in_index` | Deleted absent from list |
| 23 | `test_soft_deleted_slider_returns_404_on_show` | Deleted → 404 on show |

### Change Status

| # | Test | Description |
|---|------|-------------|
| 24 | `test_unauthenticated_user_cannot_change_slider_status` | Guest → 401 |
| 25 | `test_user_without_update_permission_gets_forbidden_for_change_status` | No perm → 403 |
| 26 | `test_authenticated_admin_can_toggle_slider_status` | Admin toggles status |
| 27 | `test_change_status_returns_422_for_missing_id` | Missing `id` → 422 |
| 28 | `test_change_status_returns_422_for_nonexistent_id` | Invalid `id` → 422 |

### Reorder

| # | Test | Description |
|---|------|-------------|
| 29 | `test_unauthenticated_user_cannot_reorder_sliders` | Guest → 401 |
| 30 | `test_user_without_update_permission_gets_forbidden_for_reorder` | No perm → 403 |
| 31 | `test_authenticated_admin_can_reorder_sliders` | Admin reorders successfully |
| 32 | `test_reorder_returns_422_for_missing_sliders` | Missing `sliders` → 422 |
| 33 | `test_reorder_returns_422_for_invalid_ids` | Invalid IDs → 422 |

### Product Relation & Translation

| # | Test | Description |
|---|------|-------------|
| 34 | `test_create_slider_with_product_association` | Create with products sync |
| 35 | `test_slider_title_is_translatable` | Title stored/returned as translation |

### Response Structure

| # | Test | Description |
|---|------|-------------|
| 36 | `test_slider_resource_structure_on_show` | Correct JSON structure |
| 37 | `test_slider_title_is_object_on_show` | Title is object (not string) on show |
| 38 | `test_slider_title_is_string_on_index` | Title is string on index |

---

## Recommended Additional Tests

### Feature Tests

| # | Test | Description |
|---|------|-------------|
| FT-001 | Public slider listing returns only active | Status filter verified |
| FT-002 | Public slider listing respects order | Sort order verified |
| FT-003 | Public slider listing respects limit | Max results check |
| FT-004 | Public slider by slug with pricing | Products enriched with pricing |
| FT-005 | Admin listing with pagination | Pagination meta verified |
| FT-006 | Admin listing with status filter | Filtered results |
| FT-007 | Create slider with all locales | EN + AR both stored |
| FT-008 | Update slider changes slug on title change | Slug regenerated |
| FT-009 | Update slider without changing images | Images preserved |
| FT-010 | Reorder maintains correct order values | Order column verified |

### Integration Tests

| # | Test | Description |
|---|------|-------------|
| IT-001 | Repository createSlider in transaction | Rollback on failure |
| IT-002 | Repository updateSlider image replacement | Old image removed, new added |
| IT-003 | Controller → Repository → Model delegation | Full chain works |
| IT-004 | Public service pricing enrichment | Product pricing attached |

### Edge Case Tests

| # | Test | Description |
|---|------|-------------|
| EC-001 | Upload SVG image (not in mimes list) | 422 |
| EC-002 | Upload file >2MB | 422 |
| EC-003 | Reorder with one slider in array | Single item reorder |
| EC-004 | Reorder with duplicate IDs | Handled gracefully |
| EC-005 | Toggle status on soft-deleted slider | 404 |
| EC-006 | Create slider without Arabic title (if required) | 422 |
| EC-007 | Update slider with duplicate title (other slider) | 422 |
| EC-008 | Force delete restores from soft delete | Force delete behavior |

### API Contract Tests

| # | Test | Description |
|---|------|-------------|
| CT-001 | Admin resource has `order` field | Present |
| CT-002 | Public resource has no `order` field | Absent |
| CT-003 | Admin resource title is string on index | String type |
| CT-004 | Admin resource title is object on show | Object type |
| CT-005 | Image fallback works (both collection names) | Image URL not null |

---

## Test Implementation Notes

```php
// Example test skeleton for public endpoints
class SliderPublicApiTest extends TestCase
{
    use DatabaseTransactions;

    /** @test */
    public function test_public_can_list_active_sliders()
    {
        Slider::factory()->create(['status' => true, 'order' => 1]);
        Slider::factory()->create(['status' => false, 'order' => 2]); // inactive

        $response = $this->getJson('/api/v1/general/sliders');

        $response->assertStatus(200)
            ->assertJsonCount(1, 'data'); // only active
    }

    /** @test */
    public function test_public_slider_by_slug_returns_products()
    {
        $slider = Slider::factory()
            ->has(Product::factory()->count(3))
            ->create(['slug' => 'test-slider', 'status' => true]);

        $response = $this->getJson('/api/v1/general/sliders/test-slider');

        $response->assertStatus(200)
            ->assertJsonPath('data.slug', 'test-slider')
            ->assertJsonCount(3, 'data.products');
    }
}
```
