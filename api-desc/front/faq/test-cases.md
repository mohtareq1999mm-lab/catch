# Test Cases - FAQ Feature

## Current Coverage

**9 test files in `tests/Feature/Faqs/`:**

| Test File | Lines | Focus |
|-----------|-------|-------|
| `FaqCrudTest.php` | 163 | CRUD operations |
| `FaqValidationTest.php` | 117 | Validation rules |
| `FaqAuthorizationTest.php` | 231 | Permission-based access |
| `FaqAuthenticationTest.php` | 149 | Auth requirements |
| `FaqTranslationTest.php` | 139 | Translation storage/retrieval |
| `FaqSoftDeleteTest.php` | 125 | Soft delete behavior |
| `FaqResourceTest.php` | 169 | API resource JSON structure |
| `FaqReorderTest.php` | 111 | Reordering |
| `FaqRegressionTest.php` | 187 | Regression tests |

---

## Existing Test Methods Summary

### FaqCrudTest

| # | Test | Description |
|---|------|-------------|
| 1 | Create FAQ | Admin creates with valid data |
| 2 | List FAQs | Paginated list |
| 3 | Show FAQ | Single FAQ by ID |
| 4 | Update FAQ | Partial update |
| 5 | Delete FAQ | Soft delete |
| 6 | 404 for nonexistent | Invalid ID |

### FaqValidationTest

| # | Test | Description |
|---|------|-------------|
| 1 | Missing faq_title | 422 |
| 2 | Missing faq_description | 422 |
| 3 | faq_title min:3 | 422 |
| 4 | faq_title max:1000 | 422 |
| 5 | faq_description min:3 | 422 |
| 6 | Unique translation | Duplicate → 422 |

### FaqAuthorizationTest

| # | Test | Description |
|---|------|-------------|
| 1 | View permission check | Without view-faqs → 403 |
| 2 | Create permission check | Without create-faq → 403 |
| 3 | Update permission check | Without update-faq → 403 |
| 4 | Delete permission check | Without delete-faq → 403 |
| 5 | Reorder permission check | Without update-faq → 403 |

### FaqAuthenticationTest

| # | Test | Description |
|---|------|-------------|
| 1 | Guest cannot list | 401 |
| 2 | Guest cannot create | 401 |
| 3 | Guest cannot show | 401 |
| 4 | Guest cannot update | 401 |
| 5 | Guest cannot delete | 401 |
| 6 | Guest cannot reorder | 401 |

### FaqTranslationTest

| # | Test | Description |
|---|------|-------------|
| 1 | Create with multiple languages | en + ar stored |
| 2 | Resource returns translated string | Not JSON |
| 3 | Locale-sensitive retrieval | Correct locale |

### FaqSoftDeleteTest

| # | Test | Description |
|---|------|-------------|
| 1 | Soft delete works | deleted_at set |
| 2 | Deleted absent from index | Not in list |
| 3 | Show returns 404 for deleted | 404 |
| 4 | Force delete | Permanently removed |

### FaqResourceTest

| # | Test | Description |
|---|------|-------------|
| 1 | Correct JSON structure | id, faq_title, faq_description |
| 2 | Translated strings | String type |
| 3 | Type assertions | Correct data types |

### FaqReorderTest

| # | Test | Description |
|---|------|-------------|
| 1 | Reorder with valid IDs | Order updated |
| 2 | Missing faqs param | 422 |
| 3 | Invalid IDs | 422 |

### FaqRegressionTest

| # | Test | Description |
|---|------|-------------|
| 1 | SoftDelete trait present | Yes |
| 2 | HasTranslations trait | Yes |
| 3 | Resource returns translated strings | String |
| 4 | Translation keys exist (known gap) | Missing EN keys |
| 5 | Sortable trait | Yes |

---

## Recommended Additional Tests

### Feature Tests

| # | Test | Description |
|---|------|-------------|
| FT-001 | Public FAQ listing returns only active | Inactive filtered |
| FT-002 | Public FAQ listing sorted by order | Correct order |
| FT-003 | Public FAQ with empty database | Empty data array |
| FT-004 | Update FAQ status from 0 to 1 | Status toggled |
| FT-005 | Create FAQ with shop_id association | Shop linked |
| FT-006 | Update FAQ without changing content | Unchanged fields preserved |

### Integration Tests

| # | Test | Description |
|---|------|-------------|
| IT-001 | Role-based scoping (Super Admin) | All FAQs visible |
| IT-002 | Role-based scoping (Store Owner) | Own shop FAQs only |
| IT-003 | Role-based scoping (Staff) | Assigned shop FAQs |
| IT-004 | GraphQL faqs query with pagination | Correct pagination |
| IT-005 | GraphQL createFaq mutation | FAQ created |

### Edge Case Tests

| # | Test | Description |
|---|------|-------------|
| EC-001 | Reorder with single FAQ | Works |
| EC-002 | Reorder with duplicate IDs | Handled |
| EC-003 | Create with blank translated strings | 422 |
| EC-004 | Update with null values | 422 |
| EC-005 | Bulk delete multiple FAQs | All deleted |

### API Contract Tests

| # | Test | Description |
|---|------|-------------|
| CT-001 | Admin resource has all fields | id, faq_title, faq_description |
| CT-002 | Public resource matches admin structure | Same fields |
| CT-003 | Response type assertions | String vs JSON |

---

## Test Implementation Notes

```php
// Example test for public FAQ endpoint
class FaqPublicTest extends TestCase
{
    use RefreshDatabase;

    /** @test */
    public function test_public_listing_returns_only_active_faqs()
    {
        $active = Faqs::factory()->create(['status' => true]);
        $inactive = Faqs::factory()->create(['status' => false]);

        $response = $this->getJson('/api/v1/general/faqs');

        $response->assertStatus(200)
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $active->id);
    }

    /** @test */
    public function test_public_listing_sorted_by_order()
    {
        $second = Faqs::factory()->create(['order' => 2]);
        $first = Faqs::factory()->create(['order' => 1]);

        $response = $this->getJson('/api/v1/general/faqs');

        $response->assertStatus(200)
            ->assertJsonPath('data.0.id', $first->id)
            ->assertJsonPath('data.1.id', $second->id);
    }
}
```
