# Pages Module — QA Test Cases

## Test Files

**Existing test:** `tests/Feature/ContentPageSectionTypeApiTest.php` (1070 lines, 63 tests)

---

## API Functionality Tests

| # | Test | Description | Expected |
|---|------|-------------|----------|
| F0 | Product type list | GET /product-type | 200, object with 9 keys |
| F0a | Product type translated AR | lang: ar header | 200, Arabic values |
| F1 | Public list pages | GET /general/content-pages | 200, only active sections |
| F2 | Public show page by slug | GET /general/content-pages/{slug} | 200, active sections |
| F3 | Public show invalid slug | GET /general/content-pages/invalid | 404 |
| F4 | Admin list pages | GET /content-pages | 200, all sections (inactive included) |
| F5 | Admin create page | POST /content-pages {title} | 201 |
| F6 | Admin create page missing title | POST /content-pages {} | 422 |
| F7 | Admin create page title exceeds 30 chars | title.* max:30 | 422 |
| F8 | Admin show page | GET /content-pages/{id} | 200 |
| F9 | Admin update page | PUT /content-pages/{id} | 200 |
| F10 | Admin update page is_active | PUT /content-pages/{id} {is_active:0} | 200 |
| F11 | Admin delete page | DELETE /content-pages/{id} | 200 |
| F12 | Admin attach sections | POST /content-pages/{id}/attach-sections {sections:[1,2]} | 200, sections attached |
| F13 | Admin attach empty (detach all) | POST /content-pages/{id}/attach-sections {sections:[]} | 200, all detached |
| F14 | Admin toggle active | PATCH /content-pages/{id}/toggle-active | 200, toggled |
| F15 | List sections | GET /sections | 200, ordered |
| F16 | Create section | POST /sections {type, title} | 200 |
| F17 | Create section invalid type | type not in section_types | 422 |
| F18 | Show section | GET /sections/{id} | 200 |
| F19 | Update section | PUT /sections/{id} | 200 |
| F20 | Delete section | DELETE /sections/{id} | 200 |
| F21 | Reorder sections | POST /sections/reorder {sections:[3,1,2]} | 200 |
| F22 | Reorder invalid ID | sections.* not exists | 422 |
| F23 | Get section types | GET /sections/types | 200, unique types |
| F24 | Toggle section active | PATCH /sections/{id}/toggle-active | 200, toggled |
| F25 | List section types | GET /section-types | 200, type list |
| F26 | Create section type | POST /section-types {type} | 200 |
| F27 | Create duplicate type | Same type again | 422 |
| F28 | Show section type | GET /section-types/{type} | 200, settings grouped |
| F29 | Update section type | PUT /section-types/{type} | 200 |
| F30 | Delete section type | DELETE /section-types/{type} | 200 |
| F31 | Get type settings | GET /section-types/{type}/settings | 200 |
| F32 | Get type settings not found | Invalid type | 404 |
| F33 | Update type settings | POST /section-types/{type}/settings {front, back} | 200 |
| F34 | Update type settings replaces all | Multiple updates | Only latest values exist |

---

## Response Structure Tests

| # | Test | Description | Expected |
|---|------|-------------|----------|
| S1 | ContentPageResource | id, title, slug, is_active, sections | Correct types |
| S2 | SectionResource | id, type, title, is_active, endpoint, order, setting | Correct types |
| S3 | ProductType response | Object with 9 keys matching product type names | Correct structure |
| S4 | ProductType AR values | lang: ar | Arabic strings |
| S3 | Sections ordered | Listed by `order` field | Ascending order |
| S4 | Section title null when hidden | title_visible = false | title = null |
| S5 | Section settings cascade | No own setting → falls back to type default | Correct fallback |
| S6 | Dynamic endpoint format | `general/{type}?{back params}` | Correct format |
| S7 | Public page filters inactive | Inactive section not in public response | Only active sections |

---

## Regression Tests (Applied 2026-07-23)

### SECTION-B002: Multilingual Title via FormData

**Given** the Create Section FormData payload:
```
_method: POST
title[en]: Test Section
title[ar]: قسم اختبار
```

**When** `SectionController::store()` is called

**Then** `$section->title` must be `{"en":"Test Section","ar":"قسم اختبار"}` (object, not empty array)

**Same test for Update** using `_method: PUT` with `title[en]: Updated` and `title[ar]: محدث`.

**Covers:** Both `store()` and `update()` paths, FormData content type, multilingual array structure.

---

## Validation Tests

| # | Test | Description | Expected |
|---|------|-------------|----------|
| V1 | Store page missing title | No title field | 422 |
| V2 | Store page title non-array | title is string | 422 |
| V3 | Store section missing type | No type field | 422 |
| V4 | Store section invalid type | Non-existent section_type | 422 |
| V5 | Store section missing title | No title field | 422 |
| V6 | Store section title non-array | title is string | 422 |
| V7 | Store section type exceeding 100 chars | type max:100 | 422 |
| V8 | Store section type empty string | type: "" | 422 |
| V9 | Attach sections invalid ID | sections.* not exists | 422 |
| V10 | Attach sections not array | sections: "string" | 422 |
| V11 | Attach sections missing | No sections field | 422 (present rule) |
| V12 | Reorder sections missing | No sections field | 422 |
| V13 | Reorder sections duplicate IDs | sections: [1,1,2] | 422 (distinct) |
| V14 | Store section type exceeding 100 chars | type max:100 | 422 |
| V15 | Store section type duplicate | type unique | 422 |
| V16 | Update section type settings invalid | front/back not array | 422 |

---

## Security Tests

| # | Test | Description | Expected |
|---|------|-------------|----------|
| SC1 | Public endpoints no auth | Guest access | 200 (public) |
| SC2 | Admin endpoint no auth | Guest access | 401 |
| SC3 | Admin endpoint wrong role | customer role | 403 |
| SC4 | Admin endpoint no permission | User lacks specific permission | 403 |
| SC5 | Mass assignment attempt | Extra fields in request | Ignored/200 |
| SC6 | Create page without create permission | view-only user | 403 |
| SC7 | Delete section without delete permission | update-only user | 403 |

---

## Translation Tests

| # | Test | Description | Expected |
|---|------|-------------|----------|
| T1 | Create page with Arabic title | title.ar present | Arabic stored |
| T2 | Create section with Arabic title | title.ar present | Arabic stored |
| T3 | Update page title in different locale | Switch locale | Correct locale returned |
| T4 | Title max length enforced | 31+ chars | 422 |
