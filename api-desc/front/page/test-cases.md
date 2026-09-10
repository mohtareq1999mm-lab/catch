# Test Cases - Content Page Feature

## Current Coverage

**2 test files, ~1,196 lines total:**

| Test File | Lines | Focus |
|-----------|-------|-------|
| `tests/Feature/ContentPageSectionTypeApiTest.php` | 1,069 | ContentPages, Sections, SectionTypes CRUD + auth + permissions + translations + response structure |
| `tests/Feature/CmsPageTest.php` | 127 | CmsPages: public fetch, editor CRUD, authorization |

---

## Existing Test Methods

### ContentPageSectionTypeApiTest

| # | Test Area | Description |
|---|-----------|-------------|
| 1 | Auth - Content Pages | All CRUD endpoints return 401 for guests |
| 2 | Auth - Sections | All CRUD endpoints return 401 for guests |
| 3 | Auth - Section Types | All CRUD endpoints return 401 for guests |
| 4 | Auth - Settings | Settings endpoints return 401 for guests |
| 5 | Permissions - Content Pages | Users without proper permissions get 403 |
| 6 | Permissions - Sections | Users without proper permissions get 403 |
| 7 | Permissions - Section Types | Users without proper permissions get 403 |
| 8 | Content Pages CRUD | List, show, create (201), update, toggle-active, delete (with 404 cases) |
| 9 | Content Pages Attach Sections | Attach, detach with empty array, invalid IDs |
| 10 | Sections CRUD | List, show, create, update, toggle-status, delete |
| 11 | Sections Reorder | Valid reorder, missing sections, invalid IDs |
| 12 | Section Types CRUD | List, create (with duplicate check), show by type string, update, delete |
| 13 | Section Types Settings | Get settings, update settings, 404 for nonexistent type |
| 14 | Translation Flow | Translatable titles in ar/en locales |
| 15 | Response Structure | Validates JSON resource structure |
| 16 | Mass Assignment Protection | Extra fields ignored |

### CmsPageTest

| # | Test | Description |
|---|------|-------------|
| 1 | Public can fetch page by slug with sorted content | Sorted content order |
| 2 | Editor can create CMS page | POST /api/v1/cms-pages |
| 3 | Editor can update CMS page | PUT /api/v1/cms-pages/{id} |
| 4 | Editor can delete CMS page | DELETE /api/v1/cms-pages/{id} |
| 5 | Non-editor cannot mutate CMS pages | 403 |

---

## Recommended Additional Tests

### Feature Tests

| # | Test | Description |
|---|------|-------------|
| FT-001 | Public page listing returns only active pages | Status filter |
| FT-002 | Public page sections filtered by active | Only active sections returned |
| FT-003 | Public page by inactive slug → 404 | Inactive page |
| FT-004 | Puck page by path returns data | Path lookup |
| FT-005 | Puck page upsert creates new page | First time creation |
| FT-006 | Puck page upsert updates existing page | Same path update |
| FT-007 | Section title hidden when title_visible=false | Null returned |
| FT-008 | Section endpoint built with all back settings | Correct URL format |
| FT-009 | Attach sections with non-existent IDs | 422 |
| FT-010 | Section type settings grouped as front/back | Structured response |

### Integration Tests

| # | Test | Description |
|---|------|-------------|
| IT-001 | Full content page render flow | Model → Resource → JSON → endpoint generation |
| IT-002 | CmsPageService transactional create | Rollback on failure |
| IT-003 | Component data returns correct structure | Valid JSON |
| IT-004 | SectionTypeService settings grouped correctly | Front + back keys |

### Edge Case Tests

| # | Test | Description |
|---|------|-------------|
| EC-001 | Content page with no sections | Empty sections array |
| EC-002 | Section with all setting fields null | Empty setting object |
| EC-003 | Section type with no settings | Empty front/back |
| EC-004 | Reorder with single section | Single item |
| EC-005 | Reorder with duplicate IDs | Handled gracefully |
| EC-006 | Section type with very long type name (>255 chars) | 422 |
| EC-007 | Content page title exceeding max length (30 chars) | 422 |
| EC-008 | CMS page with null slug | Uses path-based lookup |

### API Contract Tests

| # | Test | Description |
|---|------|-------------|
| CT-001 | ContentPageResource has all required fields | id, title, slug, is_active, sections |
| CT-002 | SectionResource has all required fields | id, type, title, is_active, endpoint, order, setting |
| CT-003 | Section title absent when title_visible=false | Not present in JSON |
| CT-004 | Section endpoint prefix is `/api/v1/general/` | Correct prefix |
| CT-005 | CmsPage content sorted by order | Ascending order |
| CT-006 | CmsPageResource fields | id, slug, title, content, meta |

### Component Data Tests

| # | Test | Description |
|---|------|-------------|
| CD-001 | Categories component data | Valid categories array |
| CD-002 | Collections component data | Valid collections array |
| CD-003 | Flash sale products component data | Valid products |
| CD-004 | Popular products component data | Sorted by popularity |
| CD-005 | Best selling products component data | Sorted by sales |

---

## Test Implementation Notes

```php
// Example test for public page with sections
class ContentPagePublicTest extends TestCase
{
    use DatabaseTransactions;

    /** @test */
    public function test_public_page_returns_only_active_sections()
    {
        $page = ContentPage::factory()->create([
            'slug' => 'test-page',
            'is_active' => true,
        ]);

        $activeSection = Section::factory()->create([
            'content_page_id' => $page->id,
            'is_active' => true,
            'type' => 'sliders',
            'order' => 1,
        ]);

        $inactiveSection = Section::factory()->create([
            'content_page_id' => $page->id,
            'is_active' => false,
            'type' => 'banners',
            'order' => 2,
        ]);

        $response = $this->getJson('/api/v1/general/pages/test-page');

        $response->assertStatus(200)
            ->assertJsonCount(1, 'data.sections')
            ->assertJsonPath('data.sections.0.id', $activeSection->id);
    }

    /** @test */
    public function test_section_endpoint_is_correctly_built()
    {
        $section = Section::factory()->create([
            'type' => 'promotions',
            'setting' => ['back' => ['limit' => 5, 'status' => true]],
        ]);

        $page = ContentPage::factory()->create();
        $page->attachSectionsByIds([$section->id]);

        $response = $this->getJson("/api/v1/general/pages/{$page->slug}");

        $response->assertStatus(200)
            ->assertJsonPath('data.sections.0.endpoint',
                '/api/v1/general/promotions?limit=5&status=1');
    }
}
```
